<?php
if (!defined('ABSPATH')) exit;

class COREXA_XRP_Poller {

  const ACTION_SINGLE = 'corexa_xrp_check_order';
  const ACTION_RECUR  = 'corexa_xrp_poll_pending';

  // Tolerance: allow underpay up to min($0.50, 0.5%)
  const TOL_USD_MAX = 0.50;
  const TOL_PCT     = 0.005;

  // Hardening
  const BODY_MAX_BYTES = 1048576; // 1MB
  const THROTTLE_SECS  = 45;

  public static function init() {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_xrp_cron_poll', [__CLASS__, 'poll_pending_orders']);

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
      as_schedule_single_action(time() + 60, self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled('corexa_xrp_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_xrp_cron_poll');
    }
  }

  public static function ensure_recurring_poll() {
    if (!function_exists('as_next_scheduled_action')) return;

    $next = as_next_scheduled_action(self::ACTION_RECUR, [], 'spg-crypto');
    if (!$next) {
      as_schedule_recurring_action(time() + 90, 120, self::ACTION_RECUR, [], 'spg-crypto');
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

  /**
   * USD -> XRP drops (1 XRP = 1,000,000 drops)
   * Uses COREXA_Rates::get_usd_price('XRP')
   */
  public static function build_expected_meta($usd_total_str) {
    $usd_total = (float) str_replace(',', '.', (string)$usd_total_str);
    if ($usd_total <= 0) return [];

    if (!class_exists('COREXA_Rates')) return [];
    $price = (float) COREXA_Rates::get_usd_price('XRP');
    if ($price <= 0) return [];

    $xrp_amt = $usd_total / $price;

    $tol_usd = min(self::TOL_USD_MAX, $usd_total * self::TOL_PCT);
    $tol_xrp = $tol_usd / $price;

    $expected_drops = self::xrp_to_drops_str($xrp_amt);
    $tol_drops      = self::xrp_to_drops_str($tol_xrp);
    $min_accept     = self::bigint_sub($expected_drops, $tol_drops);

    return [
      '_corexa_xrp_rate_usd'         => (string)$price,
      '_corexa_xrp_usd_total'        => (string)$usd_total,
      '_corexa_xrp_expected_drops'   => (string)$expected_drops,
      '_corexa_xrp_min_accept_drops' => (string)$min_accept,
    ];
  }

  /* ---------------- hardening helpers ---------------- */

  private static function throttle_ok(int $order_id, string $addr): bool {
    $k = 'corexa_xrp_throttle_' . md5($order_id . '|' . strtolower($addr));
    $last = (int) get_transient($k);
    $now  = time();

    if ($last > 0 && ($now - $last) < self::THROTTLE_SECS) {
      return false;
    }

    set_transient($k, $now, self::THROTTLE_SECS + 10);
    return true;
  }

  private static function looks_like_xrp_address(string $addr): bool {
    $addr = trim($addr);
    if ($addr === '') return false;

    // Classic XRPL accounts are usually r... base58 (25-35ish chars)
    if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $addr)) return true;

    // X-addresses often start with X and are longer
    if (preg_match('/^X[1-9A-HJ-NP-Za-km-z]{30,60}$/', $addr)) return true;

    return false;
  }

  private static function http_get_json(string $url, int $timeout = 20): ?array {
    $res = wp_remote_get($url, ['timeout' => $timeout]);
    if (is_wp_error($res)) return null;

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code !== 200) return null;

    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '') return null;
    if (strlen($body) > self::BODY_MAX_BYTES) return null;

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
  }

  private static function xrpscan_url_for_account(string $address): string {
    // HARDENED: endpoint controlled by COREXA_API
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'xrpscan_account_transactions_url')) {
      return (string) COREXA_API::xrpscan_account_transactions_url($address);
    }

    // fallback
    return 'https://api.xrpscan.com/api/v1/account/' . rawurlencode($address) . '/transactions';
  }

  private static function parse_xrp_timestamp($date_field): int {
    // XRPSCAN often returns an ISO date string
    if (is_string($date_field) && $date_field !== '') {
      $ts = strtotime($date_field);
      return $ts ? (int)$ts : 0;
    }

    // Some APIs return Ripple epoch seconds (seconds since 2000-01-01)
    if (is_int($date_field) || ctype_digit((string)$date_field)) {
      $n = (int)$date_field;
      if ($n > 0 && $n < 2000000000) {
        // Heuristic: if it's small-ish, treat as Ripple epoch and convert
        // Ripple epoch starts 2000-01-01T00:00:00Z
        $ripple_epoch = 946684800;
        if ($n < 946684800) {
          return $ripple_epoch + $n;
        }
        return $n;
      }
    }

    return 0;
  }

  public static function check_order($order_id) {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_xrp_last_checked', (string)time());
    $order->save();

    if ($order->is_paid()) return;

    $coin = strtoupper((string)$order->get_meta('_corexa_wallet_coin'));
    $net  = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order->get_meta('_corexa_wallet_network')));

    if ($coin !== 'XRP' || $net !== 'XRP') return;

    $addr = trim((string)$order->get_meta('_corexa_wallet_address'));
    if ($addr === '' || !self::looks_like_xrp_address($addr)) return;

    if (!self::throttle_ok((int)$order->get_id(), $addr)) return;

    $tag = trim((string)$order->get_meta('_corexa_wallet_tag'));
    $tag_int = null;
    if ($tag !== '') {
      // tag must be digits only
      if (!preg_match('/^\d+$/', $tag)) return;
      $tag_int = (int)$tag;
    }

    $expected = preg_replace('/\D+/', '', (string)$order->get_meta('_corexa_xrp_expected_drops'));
    $min_acc  = preg_replace('/\D+/', '', (string)$order->get_meta('_corexa_xrp_min_accept_drops'));
    if ($expected === '' || $min_acc === '') return;

    $existing = (string)$order->get_meta('_corexa_xrp_txid');
    if ($existing) return;

    $created = $order->get_date_created();
    $created_ts = $created ? $created->getTimestamp() : time();
    $min_ts = $created_ts - 120;

    $tx = self::find_matching_payment($addr, $tag_int, $min_ts, $min_acc);
    if (!$tx) return;

    $order->update_meta_data('_corexa_xrp_txid', $tx['txid']);
    $order->update_meta_data('_corexa_xrp_received_drops', (string)$tx['drops']);
    $order->update_meta_data('_corexa_xrp_timestamp', (string)$tx['timestamp']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $order->payment_complete($tx['txid']);

    $order->add_order_note(sprintf(
      /* translators: 1: txid */
      __('✅ XRP payment detected. TXID: %1$s', 'corexa-crypto-payment'),
      $tx['txid']
    ));

    self::apply_paid_status(
      $order,
      __('XRP payment confirmed.', 'corexa-crypto-payment')
    );
  }

  private static function find_matching_payment($address, $tag_int, $min_ts, $min_accept_drops) {
    // XRPSCAN: GET /api/v1/account/{ACCOUNT}/transactions
    $url = self::xrpscan_url_for_account($address);

    $json = self::http_get_json($url, 20);
    if (!$json) return null;

    $txs = $json['transactions'] ?? null;
    if (!is_array($txs)) return null;

    foreach ($txs as $tx) {
      if (!is_array($tx)) continue;

      if (empty($tx['validated'])) continue;
      if (($tx['TransactionType'] ?? '') !== 'Payment') continue;

      $dest = (string)($tx['Destination'] ?? '');
      if ($dest !== $address) continue;

      // Tag check: if merchant requires tag, enforce it
      if ($tag_int !== null) {
        if (!isset($tx['DestinationTag'])) continue;
        $dt = (int)$tx['DestinationTag'];
        if ($dt !== $tag_int) continue;
      }

      // timestamp check
      $ts = self::parse_xrp_timestamp($tx['date'] ?? '');
      if ($ts <= 0) continue;
      if ($ts < (int)$min_ts) continue;

      // XRP Amount in XRPL Payment is typically a STRING of drops.
      // If it's an array/object, it's usually an IOU token -> ignore.
      $amount = $tx['Amount'] ?? null;
      if (!is_string($amount)) continue;

      $drops = preg_replace('/\D+/', '', $amount);
      if ($drops === '') continue;

      if (!self::bigint_gte($drops, $min_accept_drops)) continue;

      $hash = (string)($tx['hash'] ?? '');
      if ($hash === '') continue;

      return [
        'txid'      => $hash,
        'drops'     => $drops,
        'timestamp' => $ts,
      ];
    }

    return null;
  }

  private static function xrp_to_drops_str($xrp_float) {
    // 1 XRP = 1,000,000 drops
    $drops = (int) round((float)$xrp_float * 1000000);
    return (string) max(0, $drops);
  }

  private static function bigint_gte($a, $b) {
    $a = ltrim((string)$a, '0'); if ($a === '') $a = '0';
    $b = ltrim((string)$b, '0'); if ($b === '') $b = '0';

    if (strlen($a) !== strlen($b)) return strlen($a) > strlen($b);
    return strcmp($a, $b) >= 0;
  }

  private static function bigint_sub($a, $b) {
    $a = ltrim((string)$a, '0'); if ($a === '') $a = '0';
    $b = ltrim((string)$b, '0'); if ($b === '') $b = '0';

    if (!self::bigint_gte($a, $b)) return '0';

    $a_digits = str_split(strrev($a));
    $b_digits = str_split(strrev($b));
    $out = [];
    $carry = 0;

    $n = max(count($a_digits), count($b_digits));
    for ($i = 0; $i < $n; $i++) {
      $ad = $i < count($a_digits) ? (int)$a_digits[$i] : 0;
      $bd = $i < count($b_digits) ? (int)$b_digits[$i] : 0;

      $v = $ad - $carry - $bd;
      if ($v < 0) { $v += 10; $carry = 1; } else { $carry = 0; }
      $out[] = (string)$v;
    }

    $res = ltrim(strrev(implode('', $out)), '0');
    return $res === '' ? '0' : $res;
  }
}

COREXA_XRP_Poller::init();