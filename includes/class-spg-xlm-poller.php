<?php
if (!defined('ABSPATH')) exit;

class COREXA_XLM_Poller {

  const ACTION_SINGLE = 'corexa_xlm_check_order';
  const ACTION_RECUR  = 'corexa_xlm_poll_pending';

  const COMMITMENT_NOTE = 'XLM Horizon';
  const CONFIRM_XLM = 1; // Stellar is fast; 1 is enough for digital goods

  const TOL_USD_MAX = 0.50;
  const TOL_PCT     = 0.005;

  // Hardening
  const BODY_MAX_BYTES = 1048576; // 1MB
  const THROTTLE_SECS  = 45;

  public static function init() {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_xlm_cron_poll', [__CLASS__, 'poll_pending_orders']);

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['minute'])) {
        $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
      }
      return $schedules;
    });
  }

  /**
   * Centralized Horizon base (COREXA_API)
   */
  private static function horizon_base(): string {
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'horizon_base')) {
      return rtrim((string) COREXA_API::horizon_base(), '/');
    }
    return 'https://horizon.stellar.org';
  }

  private static function throttle_ok(int $order_id, string $addr): bool {
    $k = 'corexa_xlm_throttle_' . md5($order_id . '|' . strtolower($addr));
    $last = (int) get_transient($k);
    $now  = time();

    if ($last > 0 && ($now - $last) < self::THROTTLE_SECS) {
      return false;
    }

    set_transient($k, $now, self::THROTTLE_SECS + 10);
    return true;
  }

  private static function looks_like_stellar_address(string $addr): bool {
    $addr = trim($addr);
    // Public key starts with G and is 56 chars base32 (A-Z2-7)
    return (bool) preg_match('/^G[A-Z2-7]{55}$/', $addr);
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

    if (!wp_next_scheduled('corexa_xlm_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_xlm_cron_poll');
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
  private static function apply_paid_status(WC_Order $order, string $note = ''): void {
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

  public static function build_expected_meta($usd_total_str) {
    $usd_total = (float) str_replace(',', '.', (string)$usd_total_str);
    if ($usd_total <= 0) return [];

    if (!class_exists('COREXA_Rates')) return [];

    $price = (float) COREXA_Rates::get_usd_price('XLM');
    if ($price <= 0) return [];

    $xlm_amt = $usd_total / $price;
    $expected_stroops = self::xlm_to_stroops($xlm_amt);

    $tol_usd = min(self::TOL_USD_MAX, $usd_total * self::TOL_PCT);
    $tol_xlm = $tol_usd / $price;
    $tol_stroops = self::xlm_to_stroops($tol_xlm);

    $min_accept = max(0, $expected_stroops - $tol_stroops);

    return [
      '_corexa_xlm_rate_usd'           => (string)$price,
      '_corexa_xlm_usd_total'          => (string)$usd_total,
      '_corexa_xlm_expected_stroops'   => (string)$expected_stroops,
      '_corexa_xlm_min_accept_stroops' => (string)$min_accept,
    ];
  }

  private static function xlm_to_stroops($xlm) {
    return max(0, (int) round(((float)$xlm) * 10000000));
  }

  private static function stroops_to_xlm_str($stroops) {
    $xlm = number_format(((int)$stroops) / 10000000, 7, '.', '');
    return rtrim(rtrim($xlm, '0'), '.');
  }

  private static function http_get_json(string $url, int $timeout = 20): ?array {
    $res = wp_remote_get($url, [
      'timeout' => $timeout,
      'headers' => ['Accept' => 'application/json'],
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

  public static function check_order($order_id) {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_xlm_last_checked', (string)time());
    $order->save();

    if ($order->is_paid()) return;

    $coin = strtoupper(trim((string)$order->get_meta('_corexa_wallet_coin')));
    $net  = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order->get_meta('_corexa_wallet_network')));
    if ($net === 'STELLAR') $net = 'XLM';

    // ✅ FIX: require BOTH to be XLM (not "either")
    if ($coin !== 'XLM' || $net !== 'XLM') return;

    if ((string)$order->get_meta('_corexa_xlm_txid') !== '') return;

    $to_address = trim((string)$order->get_meta('_corexa_wallet_address'));
    if ($to_address === '' || !self::looks_like_stellar_address($to_address)) return;

    if (!self::throttle_ok((int)$order->get_id(), $to_address)) return;

    // Memo is REQUIRED for safety
    $memo = trim((string)$order->get_meta('_corexa_wallet_tag'));
    if ($memo === '') return;

    // hard cap memo length (protect logs + requests)
    if (strlen($memo) > 64) return;

    $expected   = (int)$order->get_meta('_corexa_xlm_expected_stroops');
    $min_accept = (int)$order->get_meta('_corexa_xlm_min_accept_stroops');
    if ($expected <= 0 || $min_accept < 0) return;

    $created = $order->get_date_created();
    $min_ts = ($created ? $created->getTimestamp() : time()) - 120;

    $tx = self::find_matching_xlm_payment($to_address, $memo, $min_ts, $min_accept);
    if (!$tx) return;

    $order->update_meta_data('_corexa_xlm_txid', $tx['txid']);
    $order->update_meta_data('_corexa_xlm_received_stroops', (string)$tx['stroops']);
    $order->update_meta_data('_corexa_xlm_timestamp', (string)$tx['timestamp']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $order->payment_complete($tx['txid']);

    $order->add_order_note(sprintf(
      /* translators: 1: txid, 2: amount, 3: memo */
      __('✅ XLM payment detected. TXID: %1$s (received %2$s XLM, memo: %3$s)', 'corexa-crypto-payment'),
      $tx['txid'],
      self::stroops_to_xlm_str($tx['stroops']),
      $memo
    ));

    self::apply_paid_status(
      $order,
      __('XLM payment confirmed.', 'corexa-crypto-payment')
    );
  }

  private static function find_matching_xlm_payment($to_address, $memo, $min_ts, $min_accept_stroops) {
    $base = self::horizon_base();

    // list payments to the account
    $url  = $base . '/accounts/' . rawurlencode($to_address) . '/payments?order=desc&limit=50';
    $json = self::http_get_json($url, 20);
    if (!$json) return null;

    $records = $json['_embedded']['records'] ?? null;
    if (!is_array($records)) return null;

    foreach ($records as $rec) {
      if (!is_array($rec)) continue;

      if (($rec['type'] ?? '') !== 'payment') continue;
      if (($rec['to'] ?? '') !== $to_address) continue;
      if (($rec['asset_type'] ?? '') !== 'native') continue;

      $txid = (string)($rec['transaction_hash'] ?? '');
      if ($txid === '') continue;

      // fetch tx to read memo safely
      $tx = self::http_get_json($base . '/transactions/' . rawurlencode($txid), 20);
      if (!$tx) continue;

      $txMemo = (string)($tx['memo'] ?? '');
      if ($txMemo !== $memo) continue;

      $ts = isset($tx['created_at']) ? strtotime((string)$tx['created_at']) : 0;
      if ($ts <= 0 || $ts < (int)$min_ts) continue;

      $amt = (string)($rec['amount'] ?? '0');
      $stroops = self::xlm_to_stroops((float) str_replace(',', '.', $amt));
      if ($stroops < (int)$min_accept_stroops) continue;

      return [
        'txid'      => $txid,
        'stroops'   => $stroops,
        'timestamp' => $ts,
      ];
    }

    return null;
  }
}

COREXA_XLM_Poller::init();