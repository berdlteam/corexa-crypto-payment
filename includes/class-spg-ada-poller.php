<?php
if (!defined('ABSPATH')) exit;

class COREXA_ADA_Poller {

  const ACTION_SINGLE = 'corexa_ada_check_order';
  const ACTION_RECUR  = 'corexa_ada_poll_pending';

  const CONFIRM_ADA = 2;

  const TOL_USD_MAX = 0.50;
  const TOL_PCT     = 0.005;

  public static function init() {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_ada_cron_poll', [__CLASS__, 'poll_pending_orders']);

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['minute'])) {
        $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
      }
      return $schedules;
    });
  }

  public static function schedule_order_check($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action(self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      as_schedule_single_action(time() + 120, self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled('corexa_ada_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_ada_cron_poll');
    }
  }

  public static function ensure_recurring_poll() {
    if (!function_exists('as_next_scheduled_action')) return;

    $next = as_next_scheduled_action(self::ACTION_RECUR, [], 'spg-crypto');
    if (!$next) {
      as_schedule_recurring_action(time() + 120, 120, self::ACTION_RECUR, [], 'spg-crypto');
    }
  }

  public static function poll_pending_orders() {
    if (!class_exists('WooCommerce')) return;

    $orders = wc_get_orders([
      'limit'          => 30,
      'status'         => ['on-hold', 'pending'],
      'payment_method' => 'corexa_crypto_manual',
      'orderby'        => 'date',
      'order'          => 'DESC',
      'date_created'   => '>' . (time() - 6 * HOUR_IN_SECONDS),
      'return'         => 'objects',
    ]);

    foreach ($orders as $order) {
      self::check_order($order->get_id());
    }
  }

  /**
   * Read gateway setting: order status after payment confirmation.
   * Allowed: processing|completed. Fallback: processing
   */
  private static function get_paid_status_from_gateway(): string {
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
  private static function apply_paid_status(WC_Order $order, $note = ''): void {
    $status = self::get_paid_status_from_gateway();

    // If it's already in a "paid" final state, don't fight Woo.
    if ($order->is_paid() && in_array($order->get_status(), ['processing', 'completed'], true)) {
      return;
    }

    $order->update_status(
      $status,
      $note !== '' ? $note : __('Crypto payment confirmed.', 'corexa-crypto-payment')
    );
  }

  private static function blockfrost_base(): string {
    // HARDENED: base URL comes from COREXA_API only
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'blockfrost_base')) {
      $b = trim((string) COREXA_API::blockfrost_base());
      if ($b !== '') return rtrim($b, '/');
    }
    // fallback
    return 'https://cardano-mainnet.blockfrost.io/api/v0';
  }

  private static function blockfrost_headers(): array {
    // HARDENED: key comes from COREXA_API only (backend file)
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'blockfrost_headers')) {
      return (array) COREXA_API::blockfrost_headers();
    }
    return [];
  }

  private static function bf_get($path) {
    $headers = self::blockfrost_headers();
    // if no key -> no polling
    if (empty($headers['project_id'])) return null;

    $base = self::blockfrost_base();
    $path = '/' . ltrim((string)$path, '/');
    $url  = $base . $path;

    $res = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => $headers,
    ]);

    if (is_wp_error($res)) return null;
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code !== 200 || $body === '') return null;

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
  }

  public static function build_expected_meta($usd_total_str) {
    $usd_total = (float) str_replace(',', '.', (string)$usd_total_str);
    if ($usd_total <= 0) return [];

    if (!class_exists('COREXA_Rates')) return [];

    $price = (float) COREXA_Rates::get_usd_price('ADA');
    if ($price <= 0) return [];

    $ada_amt = $usd_total / $price;
    $expected_lovelace = self::ada_to_lovelace($ada_amt);

    $tol_usd = min(self::TOL_USD_MAX, $usd_total * self::TOL_PCT);
    $tol_ada = $tol_usd / $price;
    $tol_lovelace = self::ada_to_lovelace($tol_ada);

    $min_accept = max(0, $expected_lovelace - $tol_lovelace);

    return [
      '_corexa_ada_rate_usd'            => (string)$price,
      '_corexa_ada_usd_total'           => (string)$usd_total,
      '_corexa_ada_expected_lovelace'   => (string)$expected_lovelace,
      '_corexa_ada_min_accept_lovelace' => (string)$min_accept,
    ];
  }

  private static function ada_to_lovelace($ada) {
    return max(0, (int) round(((float)$ada) * 1000000));
  }

  private static function lovelace_to_ada_str($lovelace) {
    $ada = number_format(((int)$lovelace) / 1000000, 6, '.', '');
    return rtrim(rtrim($ada, '0'), '.');
  }

  public static function check_order($order_id) {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_ada_last_checked', (string)time());
    $order->save();

    if ($order->is_paid()) return;

    $coin = strtoupper(trim((string)$order->get_meta('_corexa_wallet_coin')));
    $net  = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order->get_meta('_corexa_wallet_network')));
    if ($net === 'CARDANO') $net = 'ADA';

    if ($coin !== 'ADA' && $net !== 'ADA') return;

    // If no Blockfrost key in COREXA_API, skip silently
    $headers = self::blockfrost_headers();
    if (empty($headers['project_id'])) return;

    $existing = (string)$order->get_meta('_corexa_ada_txid');
    if ($existing) return;

    $to_address = trim((string)$order->get_meta('_corexa_wallet_address'));
    if ($to_address === '') return;

    $expected   = (int)$order->get_meta('_corexa_ada_expected_lovelace');
    $min_accept = (int)$order->get_meta('_corexa_ada_min_accept_lovelace');
    if ($expected <= 0 || $min_accept < 0) return;

    $created = $order->get_date_created();
    $min_ts = ($created ? $created->getTimestamp() : time()) - 120;

    $tx = self::find_matching_ada_incoming($to_address, $min_ts, $min_accept);
    if (!$tx) return;

    $order->update_meta_data('_corexa_ada_txid', $tx['txid']);
    $order->update_meta_data('_corexa_ada_received_lovelace', (string)$tx['lovelace']);
    $order->update_meta_data('_corexa_ada_timestamp', (string)$tx['timestamp']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    // Mark paid (Woo hooks)
    $order->payment_complete($tx['txid']);

    // Human note
    $order->add_order_note(sprintf(
      /* translators: 1: txid, 2: ADA amount */
      __('✅ ADA payment detected. TXID: %1$s (received %2$s ADA)', 'corexa-crypto-payment'),
      $tx['txid'],
      self::lovelace_to_ada_str($tx['lovelace'])
    ));

    // Apply admin chosen paid status (processing/completed)
    self::apply_paid_status($order, __('ADA payment confirmed.', 'corexa-crypto-payment'));
  }

  private static function get_latest_block_height() {
    $latest = self::bf_get('/blocks/latest');
    if (!is_array($latest)) return 0;
    return (int)($latest['height'] ?? 0);
  }

  private static function find_matching_ada_incoming($address, $min_ts, $min_accept_lovelace) {
    $txs = self::bf_get('/addresses/' . rawurlencode($address) . '/transactions?order=desc&count=50');
    if (!is_array($txs) || empty($txs)) return null;

    $latest_height = self::get_latest_block_height();

    foreach ($txs as $row) {
      $txid = (string)($row['tx_hash'] ?? '');
      if ($txid === '') continue;

      $tx = self::bf_get('/txs/' . rawurlencode($txid));
      if (!is_array($tx)) continue;

      $timestamp = (int)($tx['block_time'] ?? 0);
      if ($timestamp > 0 && $timestamp < (int)$min_ts) continue;

      // confirmations
      $height = (int)($tx['block_height'] ?? 0);
      if ($latest_height > 0 && $height > 0) {
        $conf = ($latest_height - $height) + 1;
        if ($conf < self::CONFIRM_ADA) continue;
      }

      $utxos = self::bf_get('/txs/' . rawurlencode($txid) . '/utxos');
      if (!is_array($utxos) || empty($utxos['outputs']) || !is_array($utxos['outputs'])) continue;

      foreach ($utxos['outputs'] as $out) {
        if (!is_array($out)) continue;
        if ((string)($out['address'] ?? '') !== $address) continue;

        $amounts = $out['amount'] ?? [];
        if (!is_array($amounts)) continue;

        foreach ($amounts as $a) {
          if (!is_array($a)) continue;
          if ((string)($a['unit'] ?? '') !== 'lovelace') continue;

          $lovelace = (int) preg_replace('/\D+/', '', (string)($a['quantity'] ?? '0'));
          if ($lovelace >= (int)$min_accept_lovelace) {
            return [
              'txid'      => $txid,
              'lovelace'  => $lovelace,
              'timestamp' => $timestamp,
            ];
          }
        }
      }
    }

    return null;
  }
}

COREXA_ADA_Poller::init();