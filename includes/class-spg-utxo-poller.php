<?php
if (!defined('ABSPATH')) exit;

class COREXA_UTXO_Poller
{
  const ACTION_SINGLE = 'corexa_utxo_check_order';
  const ACTION_RECUR  = 'corexa_utxo_poll_pending';

  // Confirmations
  const CONF_BTC  = 1;
  const CONF_LTC  = 2;
  const CONF_BCH  = 1;
  const CONF_DOGE = 6;
  const CONF_DASH = 2;
  const CONF_XVG  = 2;

  // Basic hardening
  const BODY_MAX_BYTES = 5242880; // 5MB
  const THROTTLE_SECS  = 45;      // avoid hammering APIs per order/addr

  public static function init()
  {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_utxo_cron_poll', [__CLASS__, 'poll_pending_orders']);

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['minute'])) {
        $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
      }
      return $schedules;
    });
  }

  public static function schedule_order_check($order_id)
  {
    $order_id = (int) $order_id;
    if ($order_id <= 0) return;

    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action(self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled('corexa_utxo_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_utxo_cron_poll');
    }
  }

  public static function ensure_recurring_poll()
  {
    if (!function_exists('as_next_scheduled_action')) return;

    $next = as_next_scheduled_action(self::ACTION_RECUR, [], 'spg-crypto');
    if (!$next) {
      as_schedule_recurring_action(time() + 120, 120, self::ACTION_RECUR, [], 'spg-crypto');
    }
  }

  /**
   * Read gateway setting: order status after payment confirmation.
   * Allowed: processing|completed. Fallback: processing
   */
  private static function get_paid_status_from_gateway(): string
  {
    $status = 'processing';

    if (function_exists('WC') && WC() && WC()->payment_gateways()) {
      $gws = WC()->payment_gateways()->get_payment_gateways();
      $gw  = $gws['corexa_crypto_manual'] ?? null;

      if ($gw && method_exists($gw, 'get_option')) {
        $candidate = (string) $gw->get_option('paid_order_status', 'processing');
        if (in_array($candidate, ['processing', 'completed'], true)) {
          $status = $candidate;
        }
      }
    }

    return $status;
  }

  /**
   * Apply admin-chosen paid status (processing/completed)
   * after payment_complete() is called.
   */
  private static function apply_paid_status(WC_Order $order, string $note = ''): void
  {
    $status = self::get_paid_status_from_gateway();

    // If already in a paid state, don't fight Woo.
    if ($order->is_paid() && in_array($order->get_status(), ['processing', 'completed'], true)) {
      return;
    }

    $order->update_status(
      $status,
      $note !== '' ? $note : __('Crypto payment confirmed.', 'corexa-crypto-payment')
    );
  }

  /* ---------------- main ---------------- */

  public static function poll_pending_orders()
  {
    if (!class_exists('WooCommerce')) return;

    $orders = wc_get_orders([
      'limit'          => 30,
      'status'         => ['on-hold', 'pending'],
      'payment_method' => 'corexa_crypto_manual',
      'date_created'   => '>' . (time() - 6 * HOUR_IN_SECONDS),
      'return'         => 'objects',
    ]);

    foreach ($orders as $order) {
      self::check_order($order->get_id());
    }
  }

  public static function check_order($order_id)
  {
    $order = wc_get_order((int) $order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_utxo_last_checked', (string) time());
    $order->save();

    if ($order->is_paid()) return;

    // NOTE: you store coin in _corexa_utxo_coin for UTXO poller logic
    $coin = strtoupper((string) $order->get_meta('_corexa_utxo_coin'));
    if (!in_array($coin, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH', 'XVG'], true)) return;

    $addr = trim((string) $order->get_meta('_corexa_wallet_address'));
    if ($addr === '') return;

    // Basic sanity check (prevents needless API calls)
    if (!self::looks_like_utxo_address($coin, $addr)) return;

    $expected = (int) $order->get_meta('_corexa_utxo_expected_sats');
    $min_acc  = (int) $order->get_meta('_corexa_utxo_min_accept_sats');
    if ($expected <= 0 || $min_acc < 0) return;

    if ((string) $order->get_meta('_corexa_utxo_txid') !== '') return;

    // Throttle per order+coin+addr so 30 orders don't explode the API
    if (!self::throttle_ok((int) $order->get_id(), $coin, $addr)) return;

    $created = $order->get_date_created();
    $min_ts = ($created ? $created->getTimestamp() : time()) - 120;

    $min_conf = self::confirmations_required($coin);

    $tx = self::find_matching_received_tx($coin, $addr, $min_ts, $min_acc, $min_conf);
    if (!$tx) return;

    $order->update_meta_data('_corexa_utxo_txid', $tx['txid']);
    $order->update_meta_data('_corexa_utxo_received_sats', (string) $tx['sats']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $order->payment_complete($tx['txid']);

    $order->add_order_note(sprintf(
      /* translators: 1: coin, 2: txid */
      __('✅ %1$s payment detected. TXID: %2$s', 'corexa-crypto-payment'),
      $coin,
      $tx['txid']
    ));

    self::apply_paid_status(
      $order,
      sprintf(
        /* translators: 1: coin */
        __('%1$s payment confirmed.', 'corexa-crypto-payment'),
        $coin
      )
    );
  }

  /* ---------------- throttling + validation ---------------- */

  private static function throttle_ok(int $order_id, string $coin, string $addr): bool
  {
    $k = 'corexa_utxo_throttle_' . md5($order_id . '|' . $coin . '|' . strtolower($addr));
    $last = (int) get_transient($k);
    $now  = time();

    if ($last > 0 && ($now - $last) < self::THROTTLE_SECS) {
      return false;
    }

    set_transient($k, $now, self::THROTTLE_SECS + 10);
    return true;
  }

  private static function looks_like_utxo_address(string $coin, string $addr): bool
  {
    $coin = strtoupper($coin);
    $addr = trim($addr);
    if ($addr === '') return false;

    // Not perfect validation, just a cheap filter.
    // Base58-ish: no 0 O I l
    $base58 = '/^[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+$/';
    $bech32 = '/^(bc1|ltc1|bchtest1)[0-9a-z]{10,}$/i';

    switch ($coin) {
      case 'BTC':
        return (bool) (preg_match($bech32, $addr) || preg_match($base58, $addr));
      case 'LTC':
        return (bool) (preg_match('/^(ltc1)[0-9a-z]{10,}$/i', $addr) || preg_match($base58, $addr));
      case 'BCH':
        // Accept both BCH legacy and cashaddr-style formats loosely
        return (bool) (preg_match('/^(bitcoincash:)?[0-9a-z]{20,}$/i', $addr) || preg_match($base58, $addr));
      case 'DOGE':
      case 'DASH':
      case 'XVG':
        return (bool) preg_match($base58, $addr);
      default:
        return false;
    }
  }

  /* ---------------- routers ---------------- */

  private static function find_matching_received_tx($coin, $addr, $min_ts, $min_acc, $min_conf)
  {
    $coin = strtoupper((string) $coin);

    if ($coin === 'XVG') {
      return self::find_xvg($addr, $min_ts, $min_acc, $min_conf);
    }

    if (in_array($coin, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH'], true)) {
      return self::find_tatum($coin, $addr, $min_ts, $min_acc, $min_conf);
    }

    return null;
  }

  /* ---------------- confirmations ---------------- */

  private static function confirmations_required($coin)
  {
    switch (strtoupper((string) $coin)) {
      case 'BTC':
        return self::CONF_BTC;
      case 'LTC':
        return self::CONF_LTC;
      case 'BCH':
        return self::CONF_BCH;
      case 'DOGE':
        return self::CONF_DOGE;
      case 'DASH':
        return self::CONF_DASH;
      case 'XVG':
        return self::CONF_XVG;
      default:
        return 1;
    }
  }

  /* ---------------- HTTP helper ---------------- */

  private static function http_get_json(string $url, int $timeout = 15, array $headers = []): ?array
  {
    $res = wp_remote_get($url, [
      'timeout' => $timeout,
      'headers' => $headers,
    ]);
    if (is_wp_error($res)) return null;

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code !== 200) return null;

    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '') return null;
    if (strlen($body) > self::BODY_MAX_BYTES) return null;

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
  }

  /* ---------------- Tatum ---------------- */

  private static function find_tatum($coin, $addr, $min_ts, $min_acc, $min_conf)
  {
    if (!class_exists('COREXA_API')) return null;
    if (!method_exists('COREXA_API', 'tatum_utxo_address_url')) return null;
    if (!method_exists('COREXA_API', 'tatum_headers')) return null;

    $url = COREXA_API::tatum_utxo_address_url((string) $coin, (string) $addr);
    if ($url === '') return null;

    $headers = (array) COREXA_API::tatum_headers();
    if (empty($headers['x-api-key'])) return null;

    $json = self::http_get_json($url, 20, $headers);
    if (!$json) return null;

    $txs = null;

    if (isset($json[0]) && is_array($json[0])) {
      $txs = $json;
    } elseif (isset($json['transactions']) && is_array($json['transactions'])) {
      $txs = $json['transactions'];
    } elseif (isset($json['txs']) && is_array($json['txs'])) {
      $txs = $json['txs'];
    }

    if (!is_array($txs)) return null;

    foreach ($txs as $tx) {
      if (!is_array($tx)) continue;

      $conf = isset($tx['confirmations']) ? (int) $tx['confirmations'] : 0;

      // Tatum address history may omit explicit confirmations.
      // Treat transactions with a block number as confirmed.
      if ($conf <= 0 && !empty($tx['blockNumber'])) {
        $conf = 999999;
      }

      if ($conf < (int) $min_conf) continue;

      $ts = isset($tx['time']) ? (int) $tx['time'] : time();

      // If Tatum returns an invalid future timestamp, do not reject the tx for that.
      if ($ts > (time() + DAY_IN_SECONDS)) {
        $ts = time();
      }

      if ($ts < (int) $min_ts) continue;

      $txid = (string) ($tx['hash'] ?? ($tx['txid'] ?? ''));
      if ($txid === '') continue;

      $outs = [];
      if (isset($tx['outputs']) && is_array($tx['outputs'])) {
        $outs = $tx['outputs'];
      } elseif (isset($tx['vout']) && is_array($tx['vout'])) {
        $outs = $tx['vout'];
      }

      if (empty($outs)) continue;

      foreach ($outs as $out) {
        if (!is_array($out)) continue;

        $out_addr = '';
        if (isset($out['address'])) {
          $out_addr = (string) $out['address'];
        } elseif (isset($out['addresses']) && is_array($out['addresses']) && !empty($out['addresses'][0])) {
          $out_addr = (string) $out['addresses'][0];
        }

        if ($out_addr !== $addr) continue;

        if (isset($out['valueSat'])) {
          $sats = (int) preg_replace('/\D+/', '', (string) $out['valueSat']);
        } else {
          $sats = self::coin_str_to_sats($out['value'] ?? '0');
        }

        if ($sats >= (int) $min_acc) {
          return [
            'txid' => $txid,
            'sats' => $sats,
          ];
        }
      }
    }

    return null;
  }

  /* ---------------- XVG ---------------- */

  private static function xvg_base()
  {
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'xvg_blockbook_base')) {
      $b = trim((string) COREXA_API::xvg_blockbook_base());
      if ($b !== '') return rtrim($b, '/');
    }
    return 'https://xvg-blockbook.nownodes.io';
  }

  private static function xvg_headers()
  {
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'xvg_blockbook_headers')) {
      return (array) COREXA_API::xvg_blockbook_headers();
    }
    return [];
  }

  private static function find_xvg($addr, $min_ts, $min_acc, $min_conf)
  {
    $headers = self::xvg_headers();

    // if no key -> don't poll
    if (empty($headers['api-key']) && empty($headers['X-API-Key'])) return null;

    $base = self::xvg_base();
    $url  = $base . '/api/v2/address/' . rawurlencode($addr) . '?details=txs&pageSize=50';

    $json = self::http_get_json($url, 20, $headers);
    if (!$json) return null;

    $txs = $json['txs'] ?? null;
    if (!is_array($txs)) return null;

    foreach ($txs as $tx) {
      if (!is_array($tx)) continue;

      $conf = (int) ($tx['confirmations'] ?? 0);
      if ($conf < (int) $min_conf) continue;

      $ts = (int) ($tx['blockTime'] ?? 0);
      if ($ts < (int) $min_ts) continue;

      $vout = $tx['vout'] ?? [];
      if (!is_array($vout)) continue;

      foreach ($vout as $o) {
        if (!is_array($o)) continue;

        $addrs = $o['addresses'] ?? [];
        if (is_string($addrs)) $addrs = [$addrs];
        if (!is_array($addrs)) $addrs = [];

        if (!in_array($addr, $addrs, true)) continue;

        if (isset($o['valueSat'])) {
          $sats = (int) preg_replace('/\D+/', '', (string) $o['valueSat']);
        } else {
          $sats = self::coin_str_to_sats($o['value'] ?? '0');
        }

        if ($sats >= (int) $min_acc) {
          $txid = (string) ($tx['txid'] ?? ($tx['hash'] ?? ''));
          if ($txid === '') continue;

          return [
            'txid' => $txid,
            'sats' => $sats,
          ];
        }
      }
    }

    return null;
  }

  /* ---------------- utils ---------------- */

  private static function coin_str_to_sats($str)
  {
    $str = preg_replace('/[^0-9.]/', '', (string) $str);
    if ($str === '' || $str === '.') return 0;

    $parts = explode('.', $str, 2);
    $i = preg_replace('/\D+/', '', $parts[0] ?? '0');
    $d = preg_replace('/\D+/', '', $parts[1] ?? '');
    $d = substr($d . '00000000', 0, 8);

    return ((int) $i * 100000000) + (int) $d;
  }
}

COREXA_UTXO_Poller::init();