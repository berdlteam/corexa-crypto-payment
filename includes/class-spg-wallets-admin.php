<?php
if (!defined('ABSPATH')) exit;
if (class_exists('COREXA_Wallets_Admin')) {
  return;
}
class COREXA_Wallets_Admin {

  const OPTION_NAME = 'corexa_wallets';

  public static function init(): void {
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

    // Handle save from WC settings page form
    add_action('admin_post_corexa_save_wallets', [__CLASS__, 'save_wallets']);
  }

public static function enqueue_admin_assets(string $hook): void {
  if ($hook !== 'woocommerce_page_wc-settings' && strpos($hook, 'wc-settings') === false) {
    return;
  }

  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen check, no state change.
  $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen check, no state change.
  $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen check, no state change.
  $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

  if ($page !== 'wc-settings') {
    return;
  }

  if (!in_array($tab, ['checkout', 'payment', 'payments'], true)) {
    return;
  }

  if ($section !== 'corexa_crypto_manual') {
    return;
  }

  wp_enqueue_script('wp-util');
  wp_enqueue_media();

  $js_path = COREXA_PATH . 'assets/js/admin-wallets.js';
  $js_ver  = file_exists($js_path) ? (string) filemtime($js_path) : COREXA_VERSION;

  wp_enqueue_script(
    'spg-admin-wallets',
    COREXA_URL . 'assets/js/admin-wallets.js',
    ['jquery', 'wp-util'],
    $js_ver,
    true
  );

  wp_enqueue_style(
    'spg-admin-wallets',
    COREXA_URL . 'assets/css/admin-wallets.css',
    [],
    COREXA_VERSION
  );
}
  // ---------- helpers ----------
  private static function norm_coin(string $coin): string {
    $coin = strtoupper(trim($coin));
    return (string) preg_replace('/[^A-Z0-9]/', '', $coin);
  }

  private static function norm_net(string $net): string {
    $net = strtoupper(trim($net));
    $net = (string) preg_replace('/[^A-Z0-9]/', '', $net);

    // aliases
    if ($net === 'ETH' || $net === 'ERC') $net = 'ERC20';
    if ($net === 'BSC' || $net === 'BEP') $net = 'BEP20';
    if ($net === 'TRON' || $net === 'TRC') $net = 'TRC20';
    if ($net === 'MATIC' || $net === 'POL' || $net === 'POLYGONNETWORK' || $net === 'POLYGON') $net = 'POLYGON';
    if ($net === 'SOLANA') $net = 'SOL';
    if ($net === 'STELLAR') $net = 'XLM';
    if ($net === 'CARDANO') $net = 'ADA';

    return $net;
  }

  private static function needs_tag(string $coin): bool {
    $c = self::norm_coin($coin);
    return ($c === 'XRP' || $c === 'XLM');
  }

  /**
   * Build a stable unique key for wallet rows.
   * - If same address+coin+net exists, keep same key.
   * - Otherwise generate a deterministic key.
   */
  private static function build_key(string $coin, string $net, string $address, int $fallback_idx): string {
    $coin = self::norm_coin($coin);
    $net  = self::norm_net($net);
    $addr = strtolower(trim($address));

    $base = $coin . '-' . $net;

    // If address exists, include a short hash so reordering doesn't change keys
    if ($addr !== '') {
      $h = substr(md5($addr), 0, 10);
      return sanitize_title($base . '-' . $h);
    }

    // fallback if no address (shouldn't happen because we skip empty)
    return sanitize_title($base . '-' . $fallback_idx);
  }

  /**
   * Save wallets table from gateway settings page.
   */
  public static function save_wallets(): void {

    if (
      !isset($_POST['corexa_wallets_nonce']) ||
      !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['corexa_wallets_nonce'])), 'corexa_wallets_save')
    ) {
      wp_die(esc_html__('Security check failed.', 'corexa-crypto-payment'));
    }

    // Capability (required for admin-side option saves)
    if (!current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Insufficient permissions.', 'corexa-crypto-payment'));
    }

    // Read + sanitize
    $raw = [];
    if (isset($_POST['corexa_wallets'])) {
      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
      $raw = wc_clean(wp_unslash($_POST['corexa_wallets']));
    }
    $raw = is_array($raw) ? $raw : [];

    $out = [];
    $idx = 0;

    foreach ($raw as $row) {
      if (!is_array($row)) {
        continue;
      }

      $currency_key = isset($row['currency_key']) ? sanitize_text_field((string) $row['currency_key']) : '';
      $currency_key = strtoupper((string) preg_replace('/[^A-Z0-9_]/', '', $currency_key));

      $coin = isset($row['coin']) ? sanitize_text_field((string) $row['coin']) : '';
      $net  = isset($row['network']) ? sanitize_text_field((string) $row['network']) : '';

      $addr = isset($row['address']) ? sanitize_text_field((string) $row['address']) : '';
      $en   = !empty($row['enabled']) ? 1 : 0;

      $tag = isset($row['tag']) ? sanitize_text_field((string) $row['tag']) : '';

      $contract = isset($row['contract']) ? sanitize_text_field((string) $row['contract']) : '';
      $contract = strtolower(trim($contract));

      $decimals = isset($row['decimals']) ? absint($row['decimals']) : 0;

      // normalize
      $coin = self::norm_coin($coin);
      $net  = self::norm_net($net);

      // If coin/net missing and catalog key provided, resolve from catalog
      if (($coin === '' || $net === '') && $currency_key !== '' && $currency_key !== '__CUSTOM__') {
        if (function_exists('corexa_currency_meta')) {
          $meta = corexa_currency_meta($currency_key);
          if (is_array($meta) && isset($meta[1], $meta[2])) {
            $coin = self::norm_coin((string) $meta[1]);
            $net  = self::norm_net((string) $meta[2]);

            if ($decimals <= 0 && array_key_exists(3, $meta) && $meta[3] !== null) {
              $decimals = absint($meta[3]);
            }
          }
        }
      }

      // required fields
      if ($coin === '' || $net === '' || $addr === '') {
        continue;
      }

      // tag rule
      if (self::needs_tag($coin) && $tag === '') {
        $en = 0;
      }

      // decimals clamp
      if ($decimals > 30) {
        $decimals = 30;
      }

      // validate contract
      if ($contract !== '' && !preg_match('/^0x[a-f0-9]{40}$/', $contract)) {
        $contract = '';
      }
      if ($contract === '') {
        $decimals = 0;
      }

      $key = self::build_key($coin, $net, $addr, $idx);

      $out[] = [
        'key'          => $key,
        'enabled'      => $en,
        'currency_key' => $currency_key,
        'coin'         => $coin,
        'network'      => $net,
        'address'      => $addr,
        'tag'          => $tag,
        'contract'     => $contract,
        'decimals'     => $decimals,
      ];

      $idx++;
    }

    update_option(self::OPTION_NAME, $out);

    // Redirect back to the settings page (avoid resubmits)
    $redirect = wp_get_referer();
    if (!$redirect) {
      $redirect = admin_url('admin.php?page=wc-settings&tab=checkout&section=corexa_crypto_manual');
    }
    wp_safe_redirect($redirect);
    exit;
  }
}

// Init after plugins loaded (prevents early-load issues in some installs)
add_action('plugins_loaded', ['COREXA_Wallets_Admin', 'init']);