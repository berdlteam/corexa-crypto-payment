<?php
if (!defined('ABSPATH')) exit;

/**
 * Order Admin (Legacy + HPOS) — Crypto payment block + actions
 * No global helper functions (Plugin Check friendly).
 */
class COREXA_Order_Admin {

  public static function init(): void {
    // Crypto payment admin block (Legacy + HPOS)
    add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'render_cryptodir_block_any']); // legacy
    add_action('woocommerce_order_edit_page_after_order_details', [__CLASS__, 'render_cryptodir_block_any']); // HPOS

    // Enqueue admin CSS only on order screens.
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_css'], 20);

    // Admin actions
    add_action('admin_post_corexa_mark_paid', [__CLASS__, 'handle_mark_paid']);
    add_action('admin_post_corexa_cancel_order', [__CLASS__, 'handle_cancel_order']);
  }

  public static function enqueue_admin_css(string $hook): void {
    $screen_id = '';
    if (function_exists('get_current_screen')) {
      $screen = get_current_screen();
      if ($screen && isset($screen->id)) {
        $screen_id = (string) $screen->id;
      }
    }

    $is_legacy_order = in_array($hook, ['post.php', 'post-new.php'], true) && ($screen_id === 'shop_order');
    $is_hpos_orders  = ($hook === 'woocommerce_page_wc-orders');

    if (!$is_legacy_order && !$is_hpos_orders && in_array($hook, ['post.php', 'post-new.php'], true)) {
      // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check
      $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
      if ($post_type === 'shop_order') {
        $is_legacy_order = true;
      }
    }

    if (!$is_legacy_order && !$is_hpos_orders) {
      return;
    }

    wp_enqueue_style(
      'spg-admin-order',
      COREXA_URL . 'assets/css/admin-order.css',
      [],
      defined('COREXA_VERSION') ? COREXA_VERSION : '1.0.0'
    );
  }

  // ---------------------------
  // Helpers
  // ---------------------------

  private static function norm_net($net): string {
    $net = strtoupper((string) $net);
    $net = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $net));

    if ($net === 'ETH' || $net === 'ERC') $net = 'ERC20';
    if ($net === 'BSC' || $net === 'BEP') $net = 'BEP20';
    if ($net === 'TRON' || $net === 'TRC') $net = 'TRC20';
    if ($net === 'DOGECOIN') $net = 'DOGE';
    if ($net === 'MATIC' || $net === 'POL' || $net === 'POLYGONNETWORK' || $net === 'POLYGON') $net = 'POLYGON';
    if ($net === 'BASE') $net = 'BASE';
    if ($net === 'SOLANA') $net = 'SOL';

    return $net;
  }

  private static function coin_is_utxo($coin): bool {
    $c = strtoupper(trim((string) $coin));
    return in_array($c, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH', 'DGB', 'MAZA', 'XVG'], true);
  }

  private static function coin_is_sol($coin, $net): bool {
    $c = strtoupper(trim((string) $coin));
    $n = self::norm_net($net);
    return ($c === 'SOL' || $n === 'SOL');
  }

  private static function sats_to_coin_str($sats): string {
    $sats = (int) $sats;
    $coin = number_format($sats / 100000000, 8, '.', '');
    return rtrim(rtrim($coin, '0'), '.');
  }

  private static function lamports_to_sol_str($lamports): string {
    $lamports = (int) $lamports;
    $sol = number_format($lamports / 1000000000, 9, '.', '');
    return rtrim(rtrim($sol, '0'), '.');
  }

  private static function is_evm_net($net): bool {
    $net = self::norm_net($net);
    return in_array($net, ['ERC20', 'BEP20', 'POLYGON', 'BASE'], true);
  }

  private static function evm_tx_explorer($net, $txid): string {
    $net = self::norm_net($net);

    if ($net === 'BEP20')   return 'https://bscscan.com/tx/' . rawurlencode((string) $txid);
    if ($net === 'POLYGON') return 'https://polygonscan.com/tx/' . rawurlencode((string) $txid);
    if ($net === 'BASE')    return 'https://basescan.org/tx/' . rawurlencode((string) $txid);

    return 'https://etherscan.io/tx/' . rawurlencode((string) $txid);
  }

  private static function evm_explorer_label($net): string {
    $net = self::norm_net($net);
    if ($net === 'BEP20')   return __('View on BscScan', 'corexa-crypto-payment');
    if ($net === 'POLYGON') return __('View on PolygonScan', 'corexa-crypto-payment');
    if ($net === 'BASE')    return __('View on BaseScan', 'corexa-crypto-payment');
    return __('View on Etherscan', 'corexa-crypto-payment');
  }

  private static function blockchair_slug_for_utxo($coin): string {
    $c = strtoupper(trim((string) $coin));

    if ($c === 'BCH')  return 'bitcoin-cash';
    if ($c === 'DOGE') return 'dogecoin';
    if ($c === 'BTC')  return 'bitcoin';
    if ($c === 'LTC')  return 'litecoin';
    if ($c === 'DASH') return 'dash';
    if ($c === 'DGB')  return 'digibyte';

    return strtolower($c);
  }

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
   * Resolve last checked timestamp from order meta.
   */
  private static function get_last_checked_ts(WC_Order $order, string $coin, string $net_raw, string $net): int {
    $last_checked_tron = (string) $order->get_meta('_corexa_tron_last_checked');
    $last_checked_evm  = (string) $order->get_meta('_corexa_evm_last_checked');
    $last_checked_utxo = (string) $order->get_meta('_corexa_utxo_last_checked');
    $last_checked_sol  = (string) $order->get_meta('_corexa_sol_last_checked');

    if ($net === 'TRC20' || $net === 'TRON') {
      return (int) $last_checked_tron;
    }

    if (self::is_evm_net($net)) {
      return (int) $last_checked_evm;
    }

    if (self::coin_is_utxo($coin)) {
      return (int) $last_checked_utxo;
    }

    if (self::coin_is_sol($coin, $net_raw)) {
      return (int) $last_checked_sol;
    }

    // fallback: if mapping fails, still try to show any existing timestamp
    foreach ([$last_checked_tron, $last_checked_evm, $last_checked_utxo, $last_checked_sol] as $candidate) {
      $ts = (int) $candidate;
      if ($ts > 0) {
        return $ts;
      }
    }

    return 0;
  }

  /**
   * Resolve tracking status with fallback.
   */
  private static function get_tracking_status(WC_Order $order): string {
    $pay_status = (string) $order->get_meta('_corexa_payment_status');
    if ($pay_status !== '') {
      return $pay_status;
    }

    $status = $order->get_status();
    if ($status === 'processing' || $status === 'completed') {
      return 'paid';
    }

    if ($status === 'cancelled' || $status === 'failed') {
      return $status;
    }

    return 'pending';
  }

  // ---------------------------
  // Render block
  // ---------------------------

  public static function render_cryptodir_block_any($order): void {
    if (is_numeric($order)) {
      $order = wc_get_order((int) $order);
    }

    if (!($order instanceof WC_Order)) {
      return;
    }

    if ($order->get_payment_method() !== 'corexa_crypto_manual') {
      return;
    }

    $coin    = (string) $order->get_meta('_corexa_wallet_coin');
    $net_raw = (string) $order->get_meta('_corexa_wallet_network');
    $net     = self::norm_net($net_raw);
    $addr    = (string) $order->get_meta('_corexa_wallet_address');

    $pay_status = self::get_tracking_status($order);

    // TRON meta
    $expected_micro_tron = (string) $order->get_meta('_corexa_expected_usdt_micro');
    $txid_tron           = (string) $order->get_meta('_corexa_tron_txid');

    // EVM meta
    $expected_amount_str = (string) $order->get_meta('_corexa_expected_amount_str');
    $txid_evm            = (string) $order->get_meta('_corexa_evm_txid');

    // UTXO meta
    $utxo_expected_sats   = (string) $order->get_meta('_corexa_utxo_expected_sats');
    $utxo_min_accept_sats = (string) $order->get_meta('_corexa_utxo_min_accept_sats');
    $utxo_txid            = (string) $order->get_meta('_corexa_utxo_txid');
    $utxo_rate_usd        = (string) $order->get_meta('_corexa_utxo_rate_usd');

    // SOL meta
    $sol_expected_lamports   = (string) $order->get_meta('_corexa_sol_expected_lamports');
    $sol_min_accept_lamports = (string) $order->get_meta('_corexa_sol_min_accept_lamports');
    $sol_txid                = (string) $order->get_meta('_corexa_sol_txid');
    $sol_rate_usd            = (string) $order->get_meta('_corexa_sol_rate_usd');

    $last_checked_ts = self::get_last_checked_ts($order, $coin, $net_raw, $net);

    echo '<div class="order_data_column spg-admin-order-col">';
    echo '<h3 class="spg-admin-title">' . esc_html__('Corexa crypto payment', 'corexa-crypto-payment') . '</h3>';
    echo '<div class="spg-admin-box">';

    echo '<p class="spg-admin-row"><strong>' . esc_html__('Selected:', 'corexa-crypto-payment') . '</strong> ' . esc_html(trim($coin . ' ' . $net_raw)) . '</p>';

    if ($addr !== '') {
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Address:', 'corexa-crypto-payment') . '</strong></p>';
      echo '<code class="spg-admin-code">' . esc_html($addr) . '</code>';
    }

    // Expected amount display
    if ($net === 'TRC20' && $expected_micro_tron !== '') {
      $expected = rtrim(rtrim(number_format(((int) $expected_micro_tron) / 1000000, 6, '.', ''), '0'), '.');
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Expected:', 'corexa-crypto-payment') . '</strong> ' . esc_html($expected) . ' USDT</p>';
    } elseif (self::is_evm_net($net) && $expected_amount_str !== '') {
      $expected_clean = rtrim(rtrim((string) $expected_amount_str, '0'), '.');
      if ($expected_clean === '') {
        $expected_clean = (string) $expected_amount_str;
      }
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Expected:', 'corexa-crypto-payment') . '</strong> ' . esc_html($expected_clean) . ' ' . esc_html(strtoupper(trim($coin))) . '</p>';
    } elseif (self::coin_is_utxo($coin) && $utxo_expected_sats !== '') {
      $expected_coin = self::sats_to_coin_str((int) $utxo_expected_sats);
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Expected:', 'corexa-crypto-payment') . '</strong> ' . esc_html($expected_coin) . ' ' . esc_html(strtoupper(trim($coin))) . '</p>';

      if ($utxo_min_accept_sats !== '' && (int) $utxo_min_accept_sats > 0) {
        $min_coin = self::sats_to_coin_str((int) $utxo_min_accept_sats);
        echo '<p class="spg-admin-subrow">' . esc_html__('Min accepted:', 'corexa-crypto-payment') . ' ' . esc_html($min_coin) . ' ' . esc_html(strtoupper(trim($coin))) . '</p>';
      }

      if ($utxo_rate_usd !== '') {
        echo '<p class="spg-admin-subrow">' . esc_html__('Rate used:', 'corexa-crypto-payment') . ' ' . esc_html($utxo_rate_usd) . ' USD</p>';
      }
    } elseif (self::coin_is_sol($coin, $net_raw) && $sol_expected_lamports !== '') {
      $expected_sol = self::lamports_to_sol_str((int) $sol_expected_lamports);
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Expected:', 'corexa-crypto-payment') . '</strong> ' . esc_html($expected_sol) . ' SOL</p>';

      if ($sol_min_accept_lamports !== '' && (int) $sol_min_accept_lamports > 0) {
        $min_sol = self::lamports_to_sol_str((int) $sol_min_accept_lamports);
        echo '<p class="spg-admin-subrow">' . esc_html__('Min accepted:', 'corexa-crypto-payment') . ' ' . esc_html($min_sol) . ' SOL</p>';
      }

      if ($sol_rate_usd !== '') {
        echo '<p class="spg-admin-subrow">' . esc_html__('Rate used:', 'corexa-crypto-payment') . ' ' . esc_html($sol_rate_usd) . ' USD</p>';
      }
    }

    echo '<p class="spg-admin-row"><strong>' . esc_html__('Tracking:', 'corexa-crypto-payment') . '</strong> ' . esc_html($pay_status) . '</p>';

    if ($last_checked_ts > 0) {
      echo '<p class="spg-admin-row"><strong>' . esc_html__('Last checked:', 'corexa-crypto-payment') . '</strong> ' . esc_html(wp_date('Y-m-d H:i:s', $last_checked_ts)) . '</p>';
    }

    // TX links
    if ($net === 'TRC20' && $txid_tron !== '') {
      $scan = 'https://tronscan.org/#/transaction/' . rawurlencode($txid_tron);
      echo '<p class="spg-admin-row"><strong>' . esc_html__('TXID:', 'corexa-crypto-payment') . '</strong></p>';
      echo '<code class="spg-admin-code">' . esc_html($txid_tron) . '</code>';
      echo '<p class="spg-admin-row"><a class="spg-admin-link" href="' . esc_url($scan) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View on TronScan', 'corexa-crypto-payment') . '</a></p>';
    }

    if (self::is_evm_net($net) && $txid_evm !== '') {
      $scan  = self::evm_tx_explorer($net, $txid_evm);
      $label = self::evm_explorer_label($net);

      echo '<p class="spg-admin-row"><strong>' . esc_html__('TXID:', 'corexa-crypto-payment') . '</strong></p>';
      echo '<code class="spg-admin-code">' . esc_html($txid_evm) . '</code>';
      echo '<p class="spg-admin-row"><a class="spg-admin-link" href="' . esc_url($scan) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a></p>';
    }

    if (self::coin_is_utxo($coin) && $utxo_txid !== '') {
      $c = strtoupper(trim((string) $coin));

      if ($c === 'MAZA') {
        $scan  = 'https://mazacha.in/tx/' . rawurlencode($utxo_txid);
        $label = __('View on Mazacha', 'corexa-crypto-payment');
      } elseif ($c === 'XVG') {
        $scan  = 'https://verge-blockchain.info/tx/' . rawurlencode($utxo_txid);
        $label = __('View on Verge Explorer', 'corexa-crypto-payment');
      } else {
        $slug  = self::blockchair_slug_for_utxo($coin);
        $scan  = 'https://blockchair.com/' . rawurlencode($slug) . '/transaction/' . rawurlencode($utxo_txid);
        $label = __('View on Blockchair', 'corexa-crypto-payment');
      }

      echo '<p class="spg-admin-row"><strong>' . esc_html__('TXID:', 'corexa-crypto-payment') . '</strong></p>';
      echo '<code class="spg-admin-code">' . esc_html($utxo_txid) . '</code>';
      echo '<p class="spg-admin-row"><a class="spg-admin-link" href="' . esc_url($scan) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a></p>';
    }

    if (self::coin_is_sol($coin, $net_raw) && $sol_txid !== '') {
      $scan = 'https://solscan.io/tx/' . rawurlencode($sol_txid);
      echo '<p class="spg-admin-row"><strong>' . esc_html__('TXID:', 'corexa-crypto-payment') . '</strong></p>';
      echo '<code class="spg-admin-code">' . esc_html($sol_txid) . '</code>';
      echo '<p class="spg-admin-row"><a class="spg-admin-link" href="' . esc_url($scan) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View on Solscan', 'corexa-crypto-payment') . '</a></p>';
    }

    $paid_url = wp_nonce_url(
      admin_url('admin-post.php?action=corexa_mark_paid&order_id=' . $order->get_id()),
      'corexa_mark_paid_' . $order->get_id()
    );

    $cancel_url = wp_nonce_url(
      admin_url('admin-post.php?action=corexa_cancel_order&order_id=' . $order->get_id()),
      'corexa_cancel_' . $order->get_id()
    );

    echo '<p class="spg-admin-actions">';
    echo '<a class="button button-primary" href="' . esc_url($paid_url) . '">' . esc_html__('Mark paid', 'corexa-crypto-payment') . '</a> ';
    echo '<a class="button" href="' . esc_url($cancel_url) . '">' . esc_html__('Cancel', 'corexa-crypto-payment') . '</a>';
    echo '</p>';

    echo '</div>';
    echo '</div>';
  }

  // ---------------------------
  // Admin actions
  // ---------------------------

  public static function handle_mark_paid(): void {
    if (!current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Access denied.', 'corexa-crypto-payment'));
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $nonce    = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if (!$order_id || !wp_verify_nonce($nonce, 'corexa_mark_paid_' . $order_id)) {
      wp_die(esc_html__('Invalid request.', 'corexa-crypto-payment'));
    }

    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'corexa_crypto_manual') {
      wp_die(esc_html__('Invalid order.', 'corexa-crypto-payment'));
    }

    $order->payment_complete();
    $order->update_meta_data('_corexa_payment_status', 'paid');
    $order->save();

    $status = self::get_paid_status_from_gateway();
    if ($order->get_status() !== $status) {
      $order->update_status($status, __('Manually marked paid after crypto verification.', 'corexa-crypto-payment'));
    } else {
      $order->add_order_note(__('Manually marked paid after crypto verification.', 'corexa-crypto-payment'));
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-orders'));
    exit;
  }

  public static function handle_cancel_order(): void {
    if (!current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Access denied.', 'corexa-crypto-payment'));
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $nonce    = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if (!$order_id || !wp_verify_nonce($nonce, 'corexa_cancel_' . $order_id)) {
      wp_die(esc_html__('Invalid request.', 'corexa-crypto-payment'));
    }

    $order = wc_get_order($order_id);
    if ($order) {
      $order->update_status('cancelled', __('Manually cancelled (crypto not received).', 'corexa-crypto-payment'));
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-orders'));
    exit;
  }
}