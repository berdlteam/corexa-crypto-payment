<?php
if (!defined('ABSPATH')) exit;

class COREXA_Tron_Poller
{

  // USDT TRC20 contract on TRON mainnet
  const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

  // Tolerance: allow underpay up to min($0.50, 0.5%)
  const TOL_USD_MAX = 0.50;
  const TOL_PCT     = 0.005;

  const ACTION_SINGLE = 'corexa_tron_check_order';
  const ACTION_RECUR  = 'corexa_tron_poll_pending';

  public static function init()
  {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_tron_cron_poll', [__CLASS__, 'poll_pending_orders']);

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['minute'])) {
        $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
      }
      return $schedules;
    });
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

  /**
   * Convert USDT amount to micro-units (6 decimals).
   */
  public static function usdt_to_micro($amount_str)
  {
    $s = trim((string) $amount_str);
    if ($s === '') return 0;

    $s = str_replace(',', '.', $s);

    $parts = explode('.', $s, 2);
    $int = preg_replace('/\D+/', '', $parts[0] ?? '0');
    $dec = preg_replace('/\D+/', '', $parts[1] ?? '');

    $dec = substr($dec . '000000', 0, 6);
    return (int) $int * 1000000 + (int) $dec;
  }

  private static function is_amount_acceptable(int $paid_micro, int $expected_micro): bool
  {
    if ($expected_micro <= 0) return false;

    $tol_micro = (int) min(
      (int) round(self::TOL_USD_MAX * 1000000),
      (int) floor($expected_micro * self::TOL_PCT)
    );

    return $paid_micro >= ($expected_micro - $tol_micro);
  }

  private static function looks_like_tron_address(string $addr): bool
  {
    // Basic TRON base58check address format: starts with T and is 34 chars
    return (bool) preg_match('/^T[a-zA-Z0-9]{33}$/', $addr);
  }

  public static function schedule_order_check($order_id)
  {
    $order_id = (int) $order_id;
    if ($order_id <= 0) return;

    if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
      $next = as_next_scheduled_action(self::ACTION_SINGLE, [$order_id], 'spg-crypto');

      if (!$next) {
        as_schedule_single_action(time() + 10, self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      }

      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled(self::ACTION_SINGLE, [$order_id])) {
      wp_schedule_single_event(time() + 10, self::ACTION_SINGLE, [$order_id]);
    }

    if (!wp_next_scheduled('corexa_tron_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_tron_cron_poll');
    }
  }

  public static function ensure_recurring_poll()
  {
    if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action') || !function_exists('as_schedule_single_action')) {
      return;
    }

    $next = as_next_scheduled_action(self::ACTION_RECUR, [], 'spg-crypto');

    if (!$next) {
      as_schedule_single_action(time() + 15, self::ACTION_RECUR, [], 'spg-crypto');
      as_schedule_recurring_action(time() + 60, 60, self::ACTION_RECUR, [], 'spg-crypto');
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

  public static function check_order($order_id)
  {
    if (!class_exists('WooCommerce')) return;

    $order = wc_get_order((int) $order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_tron_last_checked', (string) time());
    $order->save();

    if ($order->is_paid()) return;

    $coin = strtoupper((string) $order->get_meta('_corexa_wallet_coin'));

    // normalize network: "TRC 20" => "TRC20"
    $network_raw = (string) $order->get_meta('_corexa_wallet_network');
    $network = strtoupper(preg_replace('/[^A-Z0-9]/', '', $network_raw));

    if ($coin !== 'USDT' || $network !== 'TRC20') return;

    $to_address     = trim((string) $order->get_meta('_corexa_wallet_address'));
    $expected_micro = (int) $order->get_meta('_corexa_expected_usdt_micro');

    if ($to_address === '' || $expected_micro <= 0) return;
    if (!self::looks_like_tron_address($to_address)) return;

    $existing_tx = (string) $order->get_meta('_corexa_tron_txid');
    if ($existing_tx) return;

    $created = $order->get_date_created();
    $created_ts = $created ? $created->getTimestamp() : time();
    $min_ts = $created_ts - 120;

    $tx = self::find_matching_transfer($to_address, $expected_micro, $min_ts);
    if (!$tx) return;

    $order->update_meta_data('_corexa_tron_txid', $tx['txid']);
    $order->update_meta_data('_corexa_tron_amount_micro', (string) $tx['amount_micro']);
    $order->update_meta_data('_corexa_tron_timestamp', (string) $tx['timestamp']);
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $order->payment_complete($tx['txid']);

    $order->add_order_note(sprintf(
      /* translators: 1: txid */
      __('✅ USDT TRC20 payment detected. TXID: %1$s', 'corexa-crypto-payment'),
      $tx['txid']
    ));

    self::apply_paid_status(
      $order,
      __('USDT TRC20 payment confirmed.', 'corexa-crypto-payment')
    );
  }

  /**
   * Find matching USDT TRC20 transfer via TronGrid.
   * Endpoint + headers are fully controlled by COREXA_API (backend file).
   */
  private static function find_matching_transfer($to_address, $expected_micro, $min_ts)
  {

    $base = (class_exists('COREXA_API') && method_exists('COREXA_API', 'trongrid_trc20_transactions_url'))
      ? COREXA_API::trongrid_trc20_transactions_url($to_address)
      : 'https://api.trongrid.io/v1/accounts/' . rawurlencode($to_address) . '/transactions/trc20';

    $url = add_query_arg([
      'only_confirmed'   => 'true',
      'limit'            => 50,
      'contract_address' => self::USDT_CONTRACT,
      'to'               => $to_address,
    ], $base);

    // HARDENED: key comes from COREXA_API, not from WP options
    $headers = [];
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'trongrid_headers')) {
      $headers = (array) COREXA_API::trongrid_headers();
    }

    $res = wp_remote_get($url, [
      'timeout'     => 15,
      'redirection' => 3,
      'sslverify'   => true,
      'headers'     => $headers,
    ]);

    if (is_wp_error($res)) return null;
    if ((int) wp_remote_retrieve_response_code($res) !== 200) return null;

    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '' || strlen($body) > 1024 * 1024) return null; // 1MB cap

    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) return null;

    foreach ($json['data'] as $item) {
      $to = (string) ($item['to'] ?? '');
      if (strtoupper($to) !== strtoupper($to_address)) continue;

      $ts_ms = (int) ($item['block_timestamp'] ?? 0);
      $ts = (int) floor($ts_ms / 1000);
      if ($ts < (int) $min_ts) continue;

      $value_str = (string) ($item['value'] ?? '0');
      $decimals  = (int) ($item['token_info']['decimals'] ?? 6);

      $amount_micro = self::normalize_to_micro($value_str, $decimals);
      if (!self::is_amount_acceptable((int) $amount_micro, (int) $expected_micro)) continue;

      $txid = (string) ($item['transaction_id'] ?? '');
      if ($txid === '') continue;

      return [
        'txid'         => $txid,
        'amount_micro' => $amount_micro,
        'timestamp'    => $ts,
      ];
    }

    return null;
  }

  private static function normalize_to_micro($raw_value_str, $decimals)
  {
    $raw = preg_replace('/\D+/', '', (string) $raw_value_str);
    if ($raw === '') return 0;

    // TronGrid returns TRC20 "value" often as an integer string already scaled by token decimals.
    // Normalize to "micro-USDT" (6 decimals).
    $raw_int = (int) $raw;

    if ((int) $decimals === 6) return $raw_int;

    if ((int) $decimals > 6) {
      $pow = (int) pow(10, ((int)$decimals - 6));
      if ($pow <= 0) return 0;
      return (int) floor($raw_int / $pow);
    }

    $pow = (int) pow(10, (6 - (int)$decimals));
    if ($pow <= 0) return 0;
    return (int) ($raw_int * $pow);
  }
}

COREXA_Tron_Poller::init();
