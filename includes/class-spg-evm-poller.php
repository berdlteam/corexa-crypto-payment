<?php
if (!defined('ABSPATH')) exit;

class COREXA_EVM_Poller {

  const ACTION_SINGLE = 'corexa_evm_check_order';
  const ACTION_RECUR  = 'corexa_evm_poll_pending';

  const CONFIRM_ETH     = 2;
  const CONFIRM_BSC     = 5;
  const CONFIRM_POLYGON = 60;
  const CONFIRM_BASE    = 10;

  // NOTE:
  // - For EVM *native* payments, we rely on _corexa_evm_min_accept_wei set by the gateway.
  // - For EVM *token* payments, we apply a percent tolerance (TOL_PCT) in base units (bigint-safe).
  // Keeping a USD cap here would require live USD conversion per-token, which we avoid in pollers.
  const TOL_PCT     = 0.005; // 0.5%
  const TOL_USD_MAX = 0.50;  // (kept for consistency with other pollers; not used for token unit math)

  public static function norm_net($net) {
    $net = strtoupper((string)$net);
    $net = preg_replace('/[^A-Z0-9]/', '', $net);

    if ($net === 'ETH' || $net === 'ERC') $net = 'ERC20';
    if ($net === 'BSC' || $net === 'BEP') $net = 'BEP20';
    if ($net === 'TRON' || $net === 'TRC') $net = 'TRC20';

    if ($net === 'MATIC' || $net === 'POL' || $net === 'POLYGONNETWORK' || $net === 'POLYGON') $net = 'POLYGON';
    if ($net === 'BASE') $net = 'BASE';

    return $net;
  }

  public static function norm_coin($coin) {
    $coin = strtoupper(trim((string)$coin));
    return preg_replace('/[^A-Z0-9]/', '', $coin);
  }

  private static function is_evm_net($net) {
    $net = self::norm_net($net);
    return in_array($net, ['ERC20','BEP20','POLYGON','BASE'], true);
  }

  public static function init() {
    add_action(self::ACTION_SINGLE, [__CLASS__, 'check_order'], 10, 1);
    add_action(self::ACTION_RECUR,  [__CLASS__, 'poll_pending_orders']);
    add_action('corexa_evm_cron_poll', [__CLASS__, 'poll_pending_orders']);

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

    // ✅ Avoid double scheduling: enqueue async once + ensure recurring poll
    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action(self::ACTION_SINGLE, [$order_id], 'spg-crypto');
      self::ensure_recurring_poll();
      return;
    }

    if (!wp_next_scheduled('corexa_evm_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_evm_cron_poll');
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
   * AFTER payment_complete() is called.
   */
  private static function apply_paid_status(WC_Order $order, $note = ''): void {
    $status = self::get_paid_status_from_gateway();

    // If already exactly that status, do nothing.
    if ($order->get_status() === $status) {
      return;
    }

    $order->update_status(
      $status,
      $note !== '' ? $note : __('Crypto payment confirmed.', 'corexa-crypto-payment')
    );
  }

  private static function confirmations_required($net) {
    $net = self::norm_net($net);
    switch ($net) {
      case 'BEP20':   return self::CONFIRM_BSC;
      case 'POLYGON': return self::CONFIRM_POLYGON;
      case 'BASE':    return self::CONFIRM_BASE;
      case 'ERC20':
      default:        return self::CONFIRM_ETH;
    }
  }

  /**
   * HARDENED: scanner base comes from COREXA_API (single source of truth).
   * Must return full /api endpoint.
   */
  private static function scan_base($net) {
    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'evm_scan_api')) {
      return COREXA_API::evm_scan_api($net);
    }

    // fallback
    $net = self::norm_net($net);
    switch ($net) {
      case 'BEP20':   return 'https://api.bscscan.com/api';
      case 'POLYGON': return 'https://api.polygonscan.com/api';
      case 'BASE':    return 'https://api.basescan.org/api';
      default:        return 'https://api.etherscan.io/api';
    }
  }

  /**
   * HARDENED: API key comes from COREXA_API (backend file), not from WP options.
   * This removes any API key inputs from admin UI.
   */
  private static function get_scan_key($net) {
    $net = self::norm_net($net);

    if (class_exists('COREXA_API') && method_exists('COREXA_API', 'evm_scan_key')) {
      return trim((string) COREXA_API::evm_scan_key($net));
    }

    // fallback: empty means "skip"
    return '';
  }

  // Small JSON helper (keeps poller code clean)
  private static function http_get_json(string $url, int $timeout = 15): ?array {
    $res = wp_remote_get($url, [
      'timeout'     => $timeout,
      'redirection' => 3,
      'sslverify'   => true,
    ]);
    if (is_wp_error($res)) return null;
    if ((int) wp_remote_retrieve_response_code($res) !== 200) return null;

    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '' || strlen($body) > 1024 * 1024) return null; // 1MB cap

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
  }

  // ---------- MAIN ----------
  public static function check_order($order_id) {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $order->update_meta_data('_corexa_evm_last_checked', (string)time());
    $order->save();

    if ($order->is_paid()) return;

    $net  = self::norm_net($order->get_meta('_corexa_evm_net'));
    $coin = self::norm_coin($order->get_meta('_corexa_evm_coin'));

    if (!self::is_evm_net($net)) return;

    $to_address = strtolower(trim((string)$order->get_meta('_corexa_wallet_address')));
    if (!preg_match('/^0x[a-f0-9]{40}$/', $to_address)) return;

    $existing = (string)$order->get_meta('_corexa_evm_txid');
    if ($existing) return;

    $created = $order->get_date_created();
    $created_ts = $created ? $created->getTimestamp() : time();
    $min_ts = $created_ts - 120;

    // TOKEN MODE (requires contract + expected_units)
    $contract = strtolower(trim((string)$order->get_meta('_corexa_evm_contract')));
    $decimals = (int)$order->get_meta('_corexa_evm_decimals');
    $expected_units = preg_replace('/\D+/', '', (string)$order->get_meta('_corexa_expected_token_units'));

    if ($contract && preg_match('/^0x[a-f0-9]{40}$/', $contract) && $decimals > 0 && $expected_units !== '') {

      // ✅ Token tolerance: percent only, bigint-safe (prevents "$0.50" being treated as "0.50 tokens")
      $min_accept_units = self::bigint_pct_sub((string)$expected_units, (float) self::TOL_PCT);

      $tx = self::find_matching_tokentx($net, $to_address, $contract, $min_ts, $min_accept_units);
      if (!$tx) return;

      $order->update_meta_data('_corexa_evm_txid', $tx['txid']);
      $order->update_meta_data('_corexa_evm_received_units', (string)$tx['value_units']);
      $order->update_meta_data('_corexa_evm_timestamp', (string)$tx['timestamp']);
      $order->update_meta_data('_corexa_payment_status', 'paid');
      $order->save();

      $order->payment_complete($tx['txid']);
      $order->add_order_note(sprintf(
        /* translators: 1: coin, 2: network, 3: txid */
        __('✅ %1$s %2$s token payment detected. TXID: %3$s', 'corexa-crypto-payment'),
        $coin,
        $net,
        $tx['txid']
      ));

      self::apply_paid_status(
        $order,
        sprintf(
          /* translators: 1: coin, 2: network */
          __('%1$s %2$s payment confirmed.', 'corexa-crypto-payment'),
          $coin,
          $net
        )
      );
      return;
    }

    // NATIVE MODE (requires expected wei)
    $expected_wei = preg_replace('/\D+/', '', (string)$order->get_meta('_corexa_evm_expected_wei'));
    $min_accept_wei = preg_replace('/\D+/', '', (string)$order->get_meta('_corexa_evm_min_accept_wei'));

    if ($expected_wei !== '' && $min_accept_wei !== '') {
      $tx = self::find_matching_native_tx($net, $to_address, $min_ts, $min_accept_wei);
      if (!$tx) return;

      $order->update_meta_data('_corexa_evm_txid', $tx['txid']);
      $order->update_meta_data('_corexa_evm_received_wei', (string)$tx['value_wei']);
      $order->update_meta_data('_corexa_evm_timestamp', (string)$tx['timestamp']);
      $order->update_meta_data('_corexa_payment_status', 'paid');
      $order->save();

      $order->payment_complete($tx['txid']);
      $order->add_order_note(sprintf(
        /* translators: 1: coin, 2: network, 3: txid */
        __('✅ %1$s %2$s native payment detected. TXID: %3$s', 'corexa-crypto-payment'),
        $coin,
        $net,
        $tx['txid']
      ));

      self::apply_paid_status(
        $order,
        sprintf(
          /* translators: 1: coin, 2: network */
          __('%1$s %2$s payment confirmed.', 'corexa-crypto-payment'),
          $coin,
          $net
        )
      );
      return;
    }
  }

  // ---------- TOKEN ----------
  private static function find_matching_tokentx($net, $to_address, $contract, $min_ts, $min_accept_units) {
    $net = self::norm_net($net);

    $base   = self::scan_base($net);
    $apikey = self::get_scan_key($net);
    if (!$apikey || !$base) return null;

    $url = add_query_arg([
      'module'          => 'account',
      'action'          => 'tokentx',
      'address'         => $to_address,
      'contractaddress' => $contract,
      'page'            => 1,
      'offset'          => 50,
      'sort'            => 'desc',
      'apikey'          => $apikey,
    ], $base);

    $json = self::http_get_json($url, 15);
    if (!$json) return null;

    if (isset($json['status']) && (string)$json['status'] === '0') return null;
    if (empty($json['result']) || !is_array($json['result'])) return null;

    $min_conf = self::confirmations_required($net);

    foreach ($json['result'] as $item) {
      $to = strtolower((string)($item['to'] ?? ''));
      if ($to !== $to_address) continue;

      $ts = (int)($item['timeStamp'] ?? 0);
      if ($ts < (int)$min_ts) continue;

      $conf = (int)($item['confirmations'] ?? 0);
      if ($conf < $min_conf) continue;

      $val_units = preg_replace('/\D+/', '', (string)($item['value'] ?? '0'));
      if ($val_units === '') $val_units = '0';

      if (!self::bigint_gte($val_units, $min_accept_units)) continue;

      $txid = (string)($item['hash'] ?? '');
      if ($txid === '') continue;

      return [
        'txid'        => $txid,
        'value_units' => $val_units,
        'timestamp'   => $ts,
      ];
    }

    return null;
  }

  // ---------- NATIVE ----------
  private static function find_matching_native_tx($net, $to_address, $min_ts, $min_accept_wei) {
    $net = self::norm_net($net);

    $base   = self::scan_base($net);
    $apikey = self::get_scan_key($net);
    if (!$apikey || !$base) return null;

    $url = add_query_arg([
      'module'  => 'account',
      'action'  => 'txlist',
      'address' => $to_address,
      'page'    => 1,
      'offset'  => 50,
      'sort'    => 'desc',
      'apikey'  => $apikey,
    ], $base);

    $json = self::http_get_json($url, 15);
    if (!$json) return null;

    if (isset($json['status']) && (string)$json['status'] === '0') return null;
    if (empty($json['result']) || !is_array($json['result'])) return null;

    $min_conf = self::confirmations_required($net);

    foreach ($json['result'] as $item) {
      $to = strtolower((string)($item['to'] ?? ''));
      if ($to !== $to_address) continue;

      $ts = (int)($item['timeStamp'] ?? 0);
      if ($ts < (int)$min_ts) continue;

      $conf = (int)($item['confirmations'] ?? 0);
      if ($conf < $min_conf) continue;

      $val_wei = preg_replace('/\D+/', '', (string)($item['value'] ?? '0'));
      if ($val_wei === '') $val_wei = '0';

      if (!self::bigint_gte($val_wei, $min_accept_wei)) continue;

      $txid = (string)($item['hash'] ?? '');
      if ($txid === '') continue;

      return [
        'txid'      => $txid,
        'value_wei' => $val_wei,
        'timestamp' => $ts,
      ];
    }

    return null;
  }

  // ---------- UNIT HELPERS ----------
  public static function to_base_units_str($amount_str, $decimals) {
    $s = trim((string)$amount_str);
    if ($s === '') return '0';
    $s = str_replace(',', '.', $s);

    if (function_exists('wc_format_decimal')) {
      $s = (string) wc_format_decimal($s, (int)$decimals);
    }

    $parts = explode('.', $s, 2);
    $int = preg_replace('/\D+/', '', $parts[0] ?? '0');
    $dec = preg_replace('/\D+/', '', $parts[1] ?? '');

    $dec = substr($dec . str_repeat('0', (int)$decimals), 0, (int)$decimals);

    $out = ltrim($int . $dec, '0');
    return $out === '' ? '0' : $out;
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
      if ($v < 0) {
        $v += 10;
        $carry = 1;
      } else {
        $carry = 0;
      }
      $out[] = (string)$v;
    }

    $res = ltrim(strrev(implode('', $out)), '0');
    return $res === '' ? '0' : $res;
  }

  // ---------- BIGINT (small int ops) ----------
  private static function bigint_mul_small(string $a, int $m): string {
    $a = ltrim((string)$a, '0'); if ($a === '') $a = '0';
    if ($a === '0' || $m <= 0) return '0';

    $digits = str_split(strrev($a));
    $carry = 0;
    $out = [];

    foreach ($digits as $d) {
      $v = ((int)$d * $m) + $carry;
      $out[] = (string)($v % 10);
      $carry = (int) floor($v / 10);
    }
    while ($carry > 0) {
      $out[] = (string)($carry % 10);
      $carry = (int) floor($carry / 10);
    }

    $res = ltrim(strrev(implode('', $out)), '0');
    return $res === '' ? '0' : $res;
  }

  private static function bigint_div_small(string $a, int $d): string {
    $a = ltrim((string)$a, '0'); if ($a === '') $a = '0';
    if ($d <= 1) return $a;

    $out = '';
    $rem = 0;
    $len = strlen($a);

    for ($i = 0; $i < $len; $i++) {
      $rem = ($rem * 10) + (int)$a[$i];
      $q = (int) floor($rem / $d);
      $out .= (string)$q;
      $rem = $rem % $d;
    }

    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
  }

  // units - floor(units * pct)
  private static function bigint_pct_sub(string $units, float $pct): string {
    $units = preg_replace('/\D+/', '', (string)$units);
    if ($units === '' || $units === '0') return '0';

    // pct like 0.005 => multiply by 1e6
    $mult = (int) round($pct * 1000000);
    if ($mult <= 0) return $units;

    $prod  = self::bigint_mul_small($units, $mult);
    $delta = self::bigint_div_small($prod, 1000000);

    return self::bigint_sub($units, $delta);
  }
}

COREXA_EVM_Poller::init();