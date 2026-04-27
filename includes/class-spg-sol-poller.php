<?php
if (!defined('ABSPATH')) exit;

class COREXA_SOL_Poller
{

  const ACTION_SINGLE = 'corexa_sol_check_order';
  const ACTION_RECUR  = 'corexa_sol_poll_pending';

  const COMMITMENT = 'confirmed';

  const TOL_USD_MAX = 0.50;
  const TOL_PCT     = 0.005;

  public static function init()
  {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_sol_cron_poll', [__CLASS__, 'poll_pending_orders']);

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['minute'])) {
        $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
      }
      return $schedules;
    });
  }

  public static function schedule_order_check($order_id)
  {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action(self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      as_schedule_single_action(time() + 120, self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled('corexa_sol_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_sol_cron_poll');
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

  public static function poll_pending_orders()
  {
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

    // If already set to a paid status, don't fight Woo.
    if ($order->is_paid() && in_array($order->get_status(), ['processing', 'completed'], true)) {
      return;
    }

    $order->update_status(
      $status,
      $note !== '' ? $note : __('Crypto payment confirmed.', 'corexa-crypto-payment')
    );
  }

  /**
   * HARDENED: RPC URL comes from COREXA_API only (single source of truth).
   * No user settings for endpoint.
   */
  private static function get_rpc_url()
  {
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'sol_rpc_url')) {
      $u = trim((string) COREXA_API::sol_rpc_url());
      if ($u !== '') return $u;
    }
    // fallback
    return 'https://api.mainnet-beta.solana.com';
  }

  private static function rpc($method, $params = [])
  {
    $url = self::get_rpc_url();

    $payload = [
      'jsonrpc' => '2.0',
      'id'      => 1,
      'method'  => $method,
      'params'  => $params,
    ];

    $res = wp_remote_post($url, [
      'timeout' => 20,
      'headers' => ['Content-Type' => 'application/json'],
      'body'    => wp_json_encode($payload),
    ]);

    if (is_wp_error($res)) return null;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code !== 200 || $body === '') return null;

    $json = json_decode($body, true);
    if (!is_array($json) || isset($json['error'])) return null;

    return $json['result'] ?? null;
  }

  public static function build_expected_meta($usd_total_str)
  {
    $usd_total = (float) str_replace(',', '.', (string)$usd_total_str);
    if ($usd_total <= 0) return [];

    if (!class_exists('COREXA_Rates')) return [];

    $price = (float) COREXA_Rates::get_usd_price('SOL');
    if ($price <= 0) return [];

    $sol_amt = $usd_total / $price;
    $expected_lamports = self::sol_to_lamports($sol_amt);

    $tol_usd = min(self::TOL_USD_MAX, $usd_total * self::TOL_PCT);
    $tol_sol = $tol_usd / $price;
    $tol_lamports = self::sol_to_lamports($tol_sol);

    $min_accept = max(0, $expected_lamports - $tol_lamports);

    return [
      '_corexa_sol_rate_usd'            => (string)$price,
      '_corexa_sol_usd_total'           => (string)$usd_total,
      '_corexa_sol_expected_lamports'   => (string)$expected_lamports,
      '_corexa_sol_min_accept_lamports' => (string)$min_accept,
    ];
  }

  private static function sol_to_lamports($sol)
  {
    $lamports = (int) round(((float)$sol) * 1000000000);
    return max(0, $lamports);
  }

  private static function lamports_to_sol_str($lamports)
  {
    $lamports = (int)$lamports;
    $sol = number_format($lamports / 1000000000, 9, '.', '');
    return rtrim(rtrim($sol, '0'), '.');
  }

  public static function check_order($order_id)
  {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_sol_last_checked', (string)time());
    $order->save();

    if ($order->is_paid()) return;

    $coin = strtoupper(trim((string)$order->get_meta('_corexa_wallet_coin')));
    $net  = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order->get_meta('_corexa_wallet_network')));
    if ($net === 'SOLANA') $net = 'SOL';

    if ($coin !== 'SOL' && $net !== 'SOL') return;

    $existing = (string)$order->get_meta('_corexa_sol_txid');
    if ($existing) return;

    $to_address = trim((string)$order->get_meta('_corexa_wallet_address'));
    if ($to_address === '') return;

    $expected   = (int)$order->get_meta('_corexa_sol_expected_lamports');
    $min_accept = (int)$order->get_meta('_corexa_sol_min_accept_lamports');
    if ($expected <= 0 || $min_accept < 0) return;

    $created = $order->get_date_created();
    $min_ts = ($created ? $created->getTimestamp() : time()) - 120;

    $tx = self::find_matching_sol_transfer($to_address, $min_ts, $min_accept);
    if (!$tx) return;

    $order->update_meta_data('_corexa_sol_txid', $tx['sig']);
    $order->update_meta_data('_corexa_sol_received_lamports', (string)$tx['lamports']);
    $order->update_meta_data('_corexa_sol_timestamp', (string)$tx['timestamp']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $order->payment_complete($tx['sig']);

    $order->add_order_note(sprintf(
      /* translators: 1: txid, 2: received SOL */
      __('✅ SOL payment detected. TXID: %1$s (received %2$s SOL)', 'corexa-crypto-payment'),
      $tx['sig'],
      self::lamports_to_sol_str($tx['lamports'])
    ));

    self::apply_paid_status(
      $order,
      __('SOL payment confirmed.', 'corexa-crypto-payment')
    );
  }

  private static function find_matching_sol_transfer($to_address, $min_ts, $min_accept_lamports)
  {
    $sigs = self::rpc('getSignaturesForAddress', [
      $to_address,
      [
        'limit' => 100,
        'commitment' => self::COMMITMENT,
      ]
    ]);

    if (!is_array($sigs)) return null;

    foreach ($sigs as $row) {
      $sig = (string)($row['signature'] ?? '');
      if ($sig === '') continue;

      $blockTime = (int)($row['blockTime'] ?? 0);
      if ($blockTime > 0 && $blockTime < (int)$min_ts) continue;

      $tx = self::rpc('getTransaction', [
        $sig,
        [
          'encoding' => 'jsonParsed',
          'commitment' => self::COMMITMENT,
          'maxSupportedTransactionVersion' => 0,
        ]
      ]);

      if (!is_array($tx) || empty($tx['transaction']['message']['instructions'])) continue;

      $timestamp = (int)($tx['blockTime'] ?? $blockTime);
      if ($timestamp > 0 && $timestamp < (int)$min_ts) continue;

      $instructions = $tx['transaction']['message']['instructions'];
      foreach ($instructions as $ix) {
        $program = (string)($ix['program'] ?? '');
        $parsed  = $ix['parsed'] ?? null;
        if ($program !== 'system' || !is_array($parsed)) continue;

        $type = (string)($parsed['type'] ?? '');
        if ($type !== 'transfer') continue;

        $info = $parsed['info'] ?? null;
        if (!is_array($info)) continue;

        $dest = (string)($info['destination'] ?? '');
        if ($dest !== $to_address) continue;

        $lamports = (int)($info['lamports'] ?? 0);
        if ($lamports >= (int)$min_accept_lamports) {
          return [
            'sig'       => $sig,
            'lamports'  => $lamports,
            'timestamp' => $timestamp,
          ];
        }
      }
    }

    return null;
  }
}

COREXA_SOL_Poller::init();
