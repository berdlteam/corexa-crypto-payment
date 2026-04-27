<?php
if (!defined('ABSPATH'))
  exit;

class WC_Gateway_COREXA_Crypto extends WC_Payment_Gateway
{
  // Tolerance: allow underpay up to min($0.50, 0.5%)
  const TOL_USD_MAX = 0.50;
  const TOL_PCT = 0.005;

  public function is_available()
  {
    if (!parent::is_available())
      return false;
    return true;
  }

  /**
   * Helpers wrapper (new primary + fallback).
   */
  private function corexa_get_wallets_enabled_any(): array
  {
    if (function_exists('corexa_get_wallets_enabled')) {
      $w = corexa_get_wallets_enabled();
      return is_array($w) ? $w : [];
    }
    return [];
  }

  private function corexa_get_wallets_any(): array
  {
    if (function_exists('corexa_get_wallets')) {
      $w = corexa_get_wallets();
      return is_array($w) ? $w : [];
    }
    return [];
  }

  private function corexa_currency_catalog_any(): array
  {
    if (function_exists('corexa_currency_catalog')) {
      $c = corexa_currency_catalog();
      return is_array($c) ? $c : [];
    }
    return [];
  }

  private function corexa_wallet_select_label_any(string $coin, string $network): string
  {
    if (function_exists('corexa_wallet_select_label')) {
      return (string) corexa_wallet_select_label($coin, $network);
    }
    return trim($coin . ' ' . $network);
  }

  private function corexa_format_crypto_amount_from_usd($usd_total, $coin)
  {
    $usd_total = (float) $usd_total;
    $coin = strtoupper(trim((string) $coin));
    if ($usd_total <= 0 || $coin === '')
      return '';

    if (in_array($coin, ['USDT', 'USDC', 'DAI', 'BUSD', 'TUSD', 'EURC'], true)) {
      return number_format($usd_total, 2, '.', '') . ' ' . $coin;
    }

    if (!class_exists('COREXA_Rates'))
      return '';
    $price = (float) COREXA_Rates::get_usd_price($coin);
    if ($price <= 0)
      return '';

    $amt = $usd_total / $price;
    $s = number_format($amt, 8, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');

    return ($s !== '' ? $s . ' ' . $coin : '');
  }

  public function corexa_schedule_cancel_after_order($order_id, $posted_data, $order)
  {
    if (!($order instanceof WC_Order))
      $order = wc_get_order((int) $order_id);
    if (!$order)
      return;

    if ($order->get_payment_method() !== $this->id)
      return;
    if ($this->get_option('payment_timer_enabled', 'disabled') !== 'enabled')
      return;

    $expires_at = (int) $order->get_meta('_corexa_timer_expires_at');
    if ($expires_at <= 0) {
      $mins = (int) $this->get_option('payment_timer_minutes', 30);
      if ($mins < 1)
        $mins = 1;
      if ($mins > 1440)
        $mins = 1440;

      $expires_at = time() + ($mins * 60);
      $order->update_meta_data('_corexa_timer_expires_at', (string) $expires_at);
      $order->save();
    }

    if (function_exists('as_schedule_single_action')) {
      $args = [(int) $order->get_id()];
      $group = 'spg-crypto';

      $already = function_exists('as_next_scheduled_action')
        ? as_next_scheduled_action('corexa_timer_cancel_unpaid_order', $args, $group)
        : false;

      if (!$already) {
        as_schedule_single_action($expires_at, 'corexa_timer_cancel_unpaid_order', $args, $group);
      }
    } else {
      wp_schedule_single_event($expires_at, 'corexa_timer_cancel_unpaid_order', [(int) $order->get_id()]);
    }
  }

  public function __construct()
  {
    $this->id = 'corexa_crypto_manual';
    $this->method_title = __('Corexa crypto payment', 'corexa-crypto-payment');
    $this->method_description = '';
    $this->has_fields = true;

    $this->init_form_fields();
    $this->init_settings();

    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');

    $this->icon = plugin_dir_url(__FILE__) . '../assets/img/gateway-icon.png';
    $this->order_button_text = __('next', 'corexa-crypto-payment');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, ['COREXA_Wallets_Admin', 'save_wallets'], 20);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_action('woocommerce_checkout_order_created', [$this, 'save_wallet_selection'], 20, 1);
    add_action('woocommerce_thankyou_' . $this->id, [$this, 'render_thankyou_instructions'], 20, 1);
    add_action('woocommerce_checkout_order_processed', [$this, 'corexa_schedule_cancel_after_order'], 20, 3);
  }

  private function corexa_qr_enabled()
  {
    return $this->get_option('qr_enabled', 'yes') === 'yes';
  }

  private function corexa_qr_size_px()
  {
    $px = (int) $this->get_option('qr_size_px', 220);
    if ($px < 120)
      $px = 120;
    if ($px > 600)
      $px = 600;
    return $px;
  }

  private function corexa_icons_enabled()
  {
    return $this->get_option('show_coin_icons', 'yes') === 'yes';
  }

  public function corexa_get_paid_status(): string
  {
    $status = (string) $this->get_option('paid_order_status', 'processing');
    if (!in_array($status, ['processing', 'completed'], true))
      $status = 'processing';
    return $status;
  }

  private function corexa_icon_url($coin, $network)
  {
    $coin = strtolower(trim((string) $coin));
    $net = strtolower(trim((string) $network));
    if ($coin === '' || $net === '')
      return '';

    $file = $coin . '-' . $net . '.png';
    $path = rtrim(COREXA_PATH, '/\\') . '/assets/coins/' . $file;
    if (!file_exists($path))
      return '';

    return rtrim(COREXA_URL, '/\\') . '/assets/coins/' . $file;
  }

  public function enqueue_assets()
  {
    // CHECKOUT (avoid order-pay endpoints)
    if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))) {

      wp_enqueue_style('spg-checkout', COREXA_URL . 'assets/css/checkout.css', [], COREXA_VERSION);
      wp_enqueue_script('spg-checkout', COREXA_URL . 'assets/js/checkout.js', ['jquery'], COREXA_VERSION, true);

      $rates = [];
      if (class_exists('COREXA_Rates') && method_exists('COREXA_Rates', 'get_all_usd_prices')) {
        $rates = COREXA_Rates::get_all_usd_prices();
      }

      $qr_enabled = $this->corexa_qr_enabled();
      $qr_size_px = $this->corexa_qr_size_px();
      $icons_enabled = $this->corexa_icons_enabled();

      $wallets = $this->corexa_get_wallets_enabled_any();

      foreach ($wallets as &$w) {
        if (!is_array($w))
          $w = [];

        $addr = (string) ($w['address'] ?? '');
        $coin = (string) ($w['coin'] ?? '');
        $net = (string) ($w['network'] ?? '');
        $tag = (string) ($w['tag'] ?? '');

        if ($qr_enabled && $addr !== '' && class_exists('COREXA_QR')) {
          $w['qr'] = COREXA_QR::get_url($addr, $coin, $net, $tag, (int) $qr_size_px);
        } else {
          $w['qr'] = '';
        }

        $w['icon'] = $icons_enabled ? $this->corexa_icon_url($coin, $net) : '';
      }
      unset($w);

      wp_localize_script('spg-checkout', 'COREXA_DATA', [
        'wallets' => $wallets,
        'rates_usd' => $rates,
        'primary_currency' => $this->get_option('primary_currency', 'random'),

        'qr_enabled' => $qr_enabled,
        'qr_size_px' => $qr_size_px,
        'icons_enabled' => $icons_enabled,

        'timer_enabled' => ($this->get_option('payment_timer_enabled', 'disabled') === 'enabled'),
        'timer_minutes' => (int) $this->get_option('payment_timer_minutes', 30),

        'i18n' => ['copied' => __('Copied!', 'corexa-crypto-payment')],
      ]);
    }

    // THANK YOU PAGE (only when it’s OUR gateway order)
    if (function_exists('is_order_received_page') && is_order_received_page()) {

      $order_id = absint(get_query_var('order-received'));

      if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_payment_method() === $this->id) {
          wp_enqueue_script('spg-thankyou', COREXA_URL . 'assets/js/thankyou.js', [], COREXA_VERSION, true);
        }
      }
    }
  }

  private function corexa_show_address()
  {
    if (!$this->corexa_qr_enabled())
      return true;
    return $this->get_option('show_address_with_qr', 'yes') === 'yes';
  }

  public function render_thankyou_instructions($order_id)
  {
    static $done = [];

    $order_id = (int) $order_id;

    // Prevent duplicate render for same order
    if ($order_id > 0 && isset($done[$order_id])) {
      return;
    }
    $done[$order_id] = true;

    $order = wc_get_order($order_id);
    if (!$order)
      return;
    if ($order->get_payment_method() !== $this->id)
      return;

    $qr_enabled = $this->corexa_qr_enabled();
    $qr_px = $this->corexa_qr_size_px();

    $coin = (string) $order->get_meta('_corexa_wallet_coin');
    $network = (string) $order->get_meta('_corexa_wallet_network');
    $address = (string) $order->get_meta('_corexa_wallet_address');
    $tag = (string) $order->get_meta('_corexa_wallet_tag');

    $icons_enabled = $this->corexa_icons_enabled();
    $icon_url = $icons_enabled ? $this->corexa_icon_url($coin, $network) : '';

    $total = $order->get_formatted_order_total();

    $usd_total_raw = (float) $order->get_total();
    $amount_crypto = (string) $this->corexa_format_crypto_amount_from_usd($usd_total_raw, $coin);

    $expires_at = (int) $order->get_meta('_corexa_timer_expires_at');

    $note_raw = (string) $this->get_option('checkout_note', '');
    $note = html_entity_decode($note_raw, ENT_QUOTES, 'UTF-8');

    /**
     * -------------------------------------------------
     * QR generation
     * -------------------------------------------------
     */

    $qr = '';

    if (!empty($qr_enabled) && $address !== '' && class_exists('COREXA_QR')) {

      $qr = (string) COREXA_QR::get_url($address, $coin, $network, $tag, (int) $qr_px);

      // If raw base64 was returned, convert to data URI
      if ($qr !== '' && stripos($qr, 'data:image/') !== 0 && strpos($qr, '://') === false) {

        $clean = preg_replace('/\s+/', '', $qr);

        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $clean) && strlen($clean) > 100) {
          $qr = 'data:image/png;base64,' . $clean;
        }
      }
    }

    /**
     * -------------------------------------------------
     * Template render
     * -------------------------------------------------
     */

    $template = trailingslashit(COREXA_PATH) . 'templates/thankyou-instructions.php';
    if (!file_exists($template))
      return;

    $vars = [
      'coin' => $coin,
      'network' => $network,
      'address' => $address,
      'tag' => $tag,
      'total' => $total,
      'amount_crypto' => $amount_crypto,
      'expires_at' => $expires_at,
      'show_address' => $this->corexa_show_address(),
      'icons_enabled' => $icons_enabled,
      'icon_url' => $icon_url,
      'qr' => $qr,
      'qr_enabled' => $qr_enabled,
      'qr_px' => $qr_px,
      'note' => $note,
      'order' => $order,
    ];

    extract($vars, EXTR_SKIP);
    include $template;
  }

  public function init_form_fields()
  {
    $this->form_fields = [
      'enabled' => [
        'title' => __('Turn crypto payments on or off', 'corexa-crypto-payment'),
        'type' => 'checkbox',
        'label' => __('Enable this payment method to let customers pay with crypto at checkout.', 'corexa-crypto-payment'),
        'default' => 'no',
      ],
      'title' => [
        'title' => __('What customers see as the payment option name', 'corexa-crypto-payment'),
        'type' => 'text',
        'default' => __('Corexa crypto payment', 'corexa-crypto-payment'),
      ],
      'description' => [
        'title' => __('A short tip to guide customers', 'corexa-crypto-payment'),
        'type' => 'textarea',
        'default' => __('e.g., “Pick your wallet, send crypto — we auto-confirm supported networks.”', 'corexa-crypto-payment'),
      ],
      'wallets' => [
        'title' => __('Add wallets you’ll accept', 'corexa-crypto-payment'),
        'type' => 'corexa_wallets',
        'description' => __('Auto-confirmation works automatically for supported networks (like BTC, ETH, TRON).', 'corexa-crypto-payment'),
      ],
      'primary_currency' => [
        'title' => __('Default currency shown first', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'random',
        'options' => $this->get_primary_currency_options(),
        'description' => __('Pick which coin appears by default. “Random” picks any available wallet.', 'corexa-crypto-payment'),
      ],
      'checkout_note' => [
        'title' => __('Custom note under wallet details', 'corexa-crypto-payment'),
        'type' => 'textarea',
        'default' => '',
        'description' => __('Add helpful instructions (HTML allowed: bold, links, line breaks).', 'corexa-crypto-payment'),
      ],
      'paid_order_status' => [
        'title' => __('Order status after payment confirmation', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'processing',
        'options' => [
          'processing' => __('Processing', 'corexa-crypto-payment'),
          'completed' => __('Completed', 'corexa-crypto-payment'),
        ],
      ],
      'qr_enabled' => [
        'title' => __('Show QR code for easy scanning?', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'yes',
        'options' => [
          'yes' => __('Enabled', 'corexa-crypto-payment'),
          'no' => __('Disabled (show address only)', 'corexa-crypto-payment'),
        ],
      ],
      'qr_size_px' => [
        'title' => __('How big should the QR code be?', 'corexa-crypto-payment'),
        'type' => 'number',
        'default' => 220,
        'description' => __('Default: 220px. Allowed range: 120–600.', 'corexa-crypto-payment'),
      ],
      'show_address_with_qr' => [
        'title' => __('Always show wallet address?', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'yes',
        'options' => [
          'yes' => __('Show address (recommended)', 'corexa-crypto-payment'),
          'no' => __('Hide address (QR only)', 'corexa-crypto-payment'),
        ],
      ],
      'show_coin_icons' => [
        'title' => __('Show coin icons next to currency?', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'yes',
        'options' => [
          'yes' => __('Enabled', 'corexa-crypto-payment'),
          'no' => __('Disabled', 'corexa-crypto-payment'),
        ],
      ],
      'payment_timer_enabled' => [
        'title' => __('Countdown timer during checkout?', 'corexa-crypto-payment'),
        'type' => 'select',
        'default' => 'enabled',
        'options' => [
          'disabled' => __('Disabled', 'corexa-crypto-payment'),
          'enabled' => __('Enabled', 'corexa-crypto-payment'),
        ],
      ],
      'payment_timer_minutes' => [
        'title' => __('How long do customers have to pay?', 'corexa-crypto-payment'),
        'type' => 'number',
        'default' => 30,
      ],
      'payment_timer_html' => [
        'title' => __('Timer HTML', 'corexa-crypto-payment'),
        'type' => 'textarea',
        'default' => '<div class="mcc_payment_timer"><div class="timer"><p>Awaiting payment<br><span class="timer_check_text">(checked every 15 secs)</span></p><span class="hours_minutes">--:--</span></div><div class="paid mcc_hidden"><p>Payment complete!</p></div></div>',
        'description' => __('HTML used to display the timer under the gateway description on checkout.', 'corexa-crypto-payment'),
      ],
    ];
  }

  public function generate_corexa_wallets_html($key, $data)
  {
    $wallets = $this->corexa_get_wallets_any();
    $catalog = $this->corexa_currency_catalog_any();

    $wallets_json = wp_json_encode($wallets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $catalog_json = wp_json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($wallets_json))
      $wallets_json = '[]';
    if (!is_string($catalog_json))
      $catalog_json = '{}';

    ob_start(); ?>
    <tr valign="top">
      <th scope="row" class="titledesc">
        <label><?php echo esc_html($data['title'] ?? __('wallets', 'corexa-crypto-payment')); ?></label>
      </th>
      <td class="forminp">

        <?php if (!empty($data['description'])): ?>
          <p class="description"><?php echo esc_html($data['description']); ?></p>
        <?php endif; ?>

        <?php wp_nonce_field('corexa_wallets_save', 'corexa_wallets_nonce'); ?>

        <div id="corexa_wallets_root" class="spg-wallets-root" data-wallets="<?php echo esc_attr($wallets_json); ?>"
          data-catalog="<?php echo esc_attr($catalog_json); ?>">
          <table class="widefat spg-wallets-table">
            <thead>
              <tr>
                <th class="spg-col-on"><?php echo esc_html__('On', 'corexa-crypto-payment'); ?></th>
                <th class="spg-col-currency"><?php echo esc_html__('Currency', 'corexa-crypto-payment'); ?></th>
                <th class="spg-col-wallet"><?php echo esc_html__('Wallet', 'corexa-crypto-payment'); ?></th>
                <th class="spg-col-actions"></th>
              </tr>
            </thead>
            <tbody id="corexa_wallets_tbody"></tbody>
          </table>

          <p class="spg-wallets-actions">
            <button type="button" class="button" id="corexa_add_wallet">
              + <?php echo esc_html__('add crypto wallet', 'corexa-crypto-payment'); ?>
            </button>
          </p>
        </div>

      </td>
    </tr>
<?php
    return ob_get_clean();
  }

  public function payment_fields()
  {
    $wallets = $this->corexa_get_wallets_enabled_any();

    echo '<div class="spg-wrap">';

    if (!empty($this->description)) {
      echo wp_kses_post(wpautop($this->description));
    }

    if (empty($wallets)) {
      echo '<p class="spg-warning">' . esc_html__('This payment method is not configured yet. Please choose another method.', 'corexa-crypto-payment') . '</p>';
      echo '</div>';
      return;
    }

    $primary = (string) $this->get_option('primary_currency', 'random');
    $icons_enabled = $this->corexa_icons_enabled();
    foreach ($wallets as &$w) {
      if (!is_array($w)) {
        $w = [];
      }

      $coin_i = (string) ($w['coin'] ?? '');
      $net_i  = (string) ($w['network'] ?? '');

      $w['icon'] = $icons_enabled ? $this->corexa_icon_url($coin_i, $net_i) : '';
    }
    unset($w);
    $seen = [];
    $primary_wallet = null;

    foreach ($wallets as $w) {
      $key = trim((string) ($w['key'] ?? ''));
      if ($key !== '' && $key === $primary) {
        $primary_wallet = $w;
        break;
      }
    }

    if (!$primary_wallet && !empty($wallets)) {
      foreach ($wallets as $w) {
        $key = trim((string) ($w['key'] ?? ''));
        $coin = trim((string) ($w['coin'] ?? ''));
        $net = trim((string) ($w['network'] ?? ''));
        if ($key !== '' && $coin !== '' && $net !== '') {
          $primary_wallet = $w;
          break;
        }
      }
    }

    $primary_key = trim((string) ($primary_wallet['key'] ?? ''));
    $primary_coin = trim((string) ($primary_wallet['coin'] ?? ''));
    $primary_net = trim((string) ($primary_wallet['network'] ?? ''));
    $primary_label = $primary_wallet ? $this->corexa_wallet_select_label_any($primary_coin, $primary_net) : __('Select…', 'corexa-crypto-payment');
    $primary_icon = !empty($primary_wallet['icon']) ? (string) $primary_wallet['icon'] : '';

    echo '<div class="spg-wallet-picker" id="corexa_wallet_picker">';
    echo '<button type="button" class="spg-wallet-picker-toggle" id="corexa_wallet_picker_toggle" aria-expanded="false">';
    echo '<span class="spg-wallet-picker-selected">';

    if ($icons_enabled) {
      echo '<img id="corexa_selected_coin_icon" class="spg-wallet-picker-icon' . ($primary_icon ? '' : ' is-hidden') . '" src="' . esc_url($primary_icon) . '" alt="" />';
    }

    echo '<span id="corexa_selected_coin_label" class="spg-wallet-picker-label">' . esc_html($primary_label) . '</span>';
    echo '</span>';
    echo '<span class="spg-wallet-picker-arrow" aria-hidden="true">&#9662;</span>';
    echo '</button>';

    echo '<div class="spg-wallet-picker-menu" id="corexa_wallet_picker_menu">';

    foreach ($wallets as $w) {
      $key = trim((string) ($w['key'] ?? ''));
      $coin = trim((string) ($w['coin'] ?? ''));
      $net = trim((string) ($w['network'] ?? ''));
      $icon = trim((string) ($w['icon'] ?? ''));

      if ($key === '' || $coin === '' || $net === '')
        continue;
      if (isset($seen[$key]))
        continue;
      $seen[$key] = true;

      $label = $this->corexa_wallet_select_label_any($coin, $net);
      $is_selected = ($primary_key !== '' && $key === $primary_key) ? ' is-selected' : '';

      echo '<button type="button" class="spg-wallet-picker-item' . esc_attr($is_selected) . '" data-key="' . esc_attr($key) . '" data-label="' . esc_attr($label) . '" data-icon="' . esc_url($icon) . '">';

      if ($icons_enabled) {
        echo '<img class="spg-wallet-picker-item-icon' . ($icon ? '' : ' is-hidden') . '" src="' . esc_url($icon) . '" alt="" />';
      }

      echo '<span class="spg-wallet-picker-item-label">' . esc_html($label) . '</span>';
      echo '</button>';
    }

    echo '</div>';
    echo '</div>';

    echo '<input type="hidden" id="corexa_wallet_choice" name="corexa_wallet_choice" value="' . esc_attr($primary_key) . '" required />';
    $note = trim((string) $this->get_option('checkout_note'));
    if ($note !== '') {
      echo '<div class="Binance_manual_payment_basic_info">' . wp_kses_post($note) . '</div>';
    }

    echo '<input type="hidden" name="corexa_wallet_coin" id="corexa_wallet_coin" value="" />';
    echo '<input type="hidden" name="corexa_wallet_network" id="corexa_wallet_network" value="" />';
    echo '<input type="hidden" name="corexa_wallet_address" id="corexa_wallet_address" value="" />';
    echo '<input type="hidden" name="corexa_wallet_qr" id="corexa_wallet_qr" value="" />';
    echo '<input type="hidden" name="corexa_wallet_contract" id="corexa_wallet_contract" value="" />';
    echo '<input type="hidden" name="corexa_wallet_decimals" id="corexa_wallet_decimals" value="" />';
    echo '<input type="hidden" name="corexa_wallet_tag" id="corexa_wallet_tag" value="" />';

    wp_nonce_field('corexa_cdp_checkout', 'corexa_cdp_nonce');
    echo '</div>';
  }

  public function validate_fields()
  {
    $pm = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
    if ($pm !== $this->id)
      return true;

    $nonce = isset($_POST['corexa_cdp_nonce']) ? sanitize_text_field(wp_unslash($_POST['corexa_cdp_nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'corexa_cdp_checkout')) {
      wc_add_notice(__('Security check failed. Please refresh the page and try again.', 'corexa-crypto-payment'), 'error');
      return false;
    }

    $choice = isset($_POST['corexa_wallet_choice']) ? sanitize_text_field(wp_unslash($_POST['corexa_wallet_choice'])) : '';
    if ($choice === '') {
      wc_add_notice(__('Please select a crypto/network.', 'corexa-crypto-payment'), 'error');
      return false;
    }

    $wallets = $this->corexa_get_wallets_enabled_any();
    $map = [];
    foreach ($wallets as $w) {
      $k = isset($w['key']) ? (string) $w['key'] : '';
      if ($k !== '')
        $map[$k] = $w;
    }

    if (!isset($map[$choice])) {
      wc_add_notice(__('Selected wallet is not available. Please choose another option.', 'corexa-crypto-payment'), 'error');
      return false;
    }

    return true;
  }

  public function process_payment($order_id)
  {
    $order = wc_get_order($order_id);
    $order->update_status('on-hold', __('Awaiting crypto payment confirmation.', 'corexa-crypto-payment'));
    wc_reduce_stock_levels($order_id);
    WC()->cart->empty_cart();

    return [
      'result' => 'success',
      'redirect' => $this->get_return_url($order),
    ];
  }

  // ---------- Normalizers ----------
  private function corexa_norm_net($net)
  {
    $net = strtoupper((string) $net);
    $net = preg_replace('/[^A-Z0-9]/', '', $net);

    if ($net === 'ETH' || $net === 'ERC')
      $net = 'ERC20';
    if ($net === 'BSC' || $net === 'BEP')
      $net = 'BEP20';
    if ($net === 'TRON' || $net === 'TRC')
      $net = 'TRC20';
    if ($net === 'DOGECOIN')
      $net = 'DOGE';
    if ($net === 'MATIC' || $net === 'POL' || $net === 'POLYGONNETWORK' || $net === 'POLYGON')
      $net = 'POLYGON';
    if ($net === 'BASE')
      $net = 'BASE';
    if ($net === 'SOLANA')
      $net = 'SOL';
    if ($net === 'STELLAR' || $net === 'XLM')
      $net = 'XLM';
    if ($net === 'CARDANO')
      $net = 'ADA';

    return $net;
  }

  private function corexa_norm_coin($coin)
  {
    $coin = strtoupper(trim((string) $coin));
    return preg_replace('/[^A-Z0-9]/', '', $coin);
  }

  private function get_primary_currency_options()
  {
    $opts = ['random' => __('Random', 'corexa-crypto-payment')];
    $all = $this->corexa_get_wallets_any();
    if (empty($all))
      return $opts;

    $i = 0;
    foreach ($all as $w) {
      if (empty($w['enabled'])) {
        $i++;
        continue;
      }

      $coin = strtolower(trim((string) ($w['coin'] ?? '')));
      $net = strtolower(trim((string) ($w['network'] ?? '')));

      $key = (string) ($w['key'] ?? '');
      if ($key === '') {
        $coin_slug = preg_replace('/[^a-z0-9]+/', '-', $coin);
        $net_slug = preg_replace('/[^a-z0-9]+/', '-', $net);
        $key = trim($coin_slug, '-') . '-' . trim($net_slug, '-') . '-' . $i;
      }

      $label = $this->corexa_wallet_select_label_any((string) ($w['coin'] ?? ''), (string) ($w['network'] ?? ''));

      if ($key && $label)
        $opts[$key] = $label;
      $i++;
    }

    return $opts;
  }

  private function corexa_is_evm_net($net)
  {
    return in_array($net, ['ERC20', 'BEP20', 'POLYGON', 'BASE'], true);
  }

  private function corexa_usd_to_sats($usd, $price_usd_per_coin)
  {
    $usd = (float) $usd;
    $price = (float) $price_usd_per_coin;
    if ($usd <= 0 || $price <= 0)
      return 0;

    $coin_amt = $usd / $price;
    $sats = (int) round($coin_amt * 100000000);
    return max(0, $sats);
  }

  // bigint helpers (for min_accept_wei etc.)
  private function corexa_bigint_gte($a, $b)
  {
    $a = ltrim((string) $a, '0');
    if ($a === '')
      $a = '0';
    $b = ltrim((string) $b, '0');
    if ($b === '')
      $b = '0';
    if (strlen($a) !== strlen($b))
      return strlen($a) > strlen($b);
    return strcmp($a, $b) >= 0;
  }

  private function corexa_bigint_sub($a, $b)
  {
    $a = ltrim((string) $a, '0');
    if ($a === '')
      $a = '0';
    $b = ltrim((string) $b, '0');
    if ($b === '')
      $b = '0';
    if (!$this->corexa_bigint_gte($a, $b))
      return '0';

    $a_digits = str_split(strrev($a));
    $b_digits = str_split(strrev($b));
    $out = [];
    $carry = 0;

    $n = max(count($a_digits), count($b_digits));
    for ($i = 0; $i < $n; $i++) {
      $ad = $i < count($a_digits) ? (int) $a_digits[$i] : 0;
      $bd = $i < count($b_digits) ? (int) $b_digits[$i] : 0;

      $v = $ad - $carry - $bd;
      if ($v < 0) {
        $v += 10;
        $carry = 1;
      } else {
        $carry = 0;
      }
      $out[] = (string) $v;
    }

    $res = ltrim(strrev(implode('', $out)), '0');
    return $res === '' ? '0' : $res;
  }

public function save_wallet_selection($order)  {
    if (!($order instanceof WC_Order))
      return;

    $pm = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
    if ($pm !== $this->id)
      return;

    $nonce = isset($_POST['corexa_cdp_nonce']) ? sanitize_text_field(wp_unslash($_POST['corexa_cdp_nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'corexa_cdp_checkout'))
      return;

    $wallet_choice = isset($_POST['corexa_wallet_choice']) ? sanitize_text_field(wp_unslash($_POST['corexa_wallet_choice'])) : '';
    if ($wallet_choice === '')
      return;

    // ✅ Source of truth: enabled wallets saved in WP options
    $wallets = $this->corexa_get_wallets_enabled_any();

    $selected = null;
    foreach ($wallets as $w) {
      if (!empty($w['key']) && (string) $w['key'] === $wallet_choice) {
        $selected = $w;
        break;
      }
    }
    if (!$selected || empty($selected['coin']) || empty($selected['network']) || empty($selected['address'])) {
      return; // invalid/disabled selection
    }

    // ✅ Use server-side wallet data, not user-posted hidden inputs
    $wallet_coin = sanitize_text_field((string) $selected['coin']);
    $wallet_network = sanitize_text_field((string) $selected['network']);
    $wallet_address = sanitize_text_field((string) $selected['address']);
    $wallet_tag = isset($selected['tag']) ? sanitize_text_field((string) $selected['tag']) : '';
    $wallet_contract = isset($selected['contract']) ? sanitize_text_field((string) $selected['contract']) : '';
    $wallet_decimals = isset($selected['decimals']) ? absint($selected['decimals']) : 0;

    // QR is derived (safe) – don’t trust POST
    $wallet_qr = '';
    if (!empty($selected['qr'])) {
      $wallet_qr = esc_url_raw((string) $selected['qr']);
    }

    $order->update_meta_data('_corexa_wallet_choice', $wallet_choice);
    $order->update_meta_data('_corexa_wallet_coin', $wallet_coin);
    $order->update_meta_data('_corexa_wallet_network', $wallet_network);
    $order->update_meta_data('_corexa_wallet_address', $wallet_address);
    $order->update_meta_data('_corexa_wallet_qr', (string) $wallet_qr);
    $order->update_meta_data('_corexa_wallet_contract', $wallet_contract);
    $order->update_meta_data('_corexa_wallet_decimals', (string) $wallet_decimals);
    $order->update_meta_data('_corexa_wallet_tag', $wallet_tag);

    $coin = $this->corexa_norm_coin($wallet_coin);
    $net = $this->corexa_norm_net($wallet_network);
    $total_str = (string) $order->get_total();

    // ===== Payment timer meta =====
    if ($this->get_option('payment_timer_enabled', 'disabled') === 'enabled') {
      $expires_at = (int) $order->get_meta('_corexa_timer_expires_at');
      if ($expires_at <= 0) {
        $mins = (int) $this->get_option('payment_timer_minutes', 30);
        if ($mins < 1)
          $mins = 1;
        if ($mins > 1440)
          $mins = 1440;
        $expires_at = time() + ($mins * 60);
        $order->update_meta_data('_corexa_timer_expires_at', (string) $expires_at);
      }
    }

    // ===== TRON USDT TRC20 =====
    if ($coin === 'USDT' && $net === 'TRC20' && class_exists('COREXA_Tron_Poller') && method_exists('COREXA_Tron_Poller', 'usdt_to_micro')) {
      $micro = COREXA_Tron_Poller::usdt_to_micro($total_str);
      $order->update_meta_data('_corexa_expected_usdt_micro', (string) $micro);
      $order->update_meta_data('_corexa_payment_status', 'pending');

      if (method_exists('COREXA_Tron_Poller', 'schedule_order_check')) {
      }
    }

    // ===== XRP =====
    if ($coin === 'XRP' && $net === 'XRP' && class_exists('COREXA_XRP_Poller') && method_exists('COREXA_XRP_Poller', 'build_expected_meta')) {
      $meta = COREXA_XRP_Poller::build_expected_meta($total_str);
      if (!empty($meta)) {
        foreach ($meta as $k => $v)
          $order->update_meta_data($k, $v);
        $order->update_meta_data('_corexa_payment_status', 'pending');

        if (method_exists('COREXA_XRP_Poller', 'schedule_order_check')) {
          COREXA_XRP_Poller::schedule_order_check($order->get_id());
        }
      }
    }

    // ===== EVM =====
    if ($this->corexa_is_evm_net($net) && class_exists('COREXA_EVM_Poller') && method_exists('COREXA_EVM_Poller', 'to_base_units_str')) {
      $order->update_meta_data('_corexa_expected_amount_str', $total_str);

      $contract = strtolower(trim((string) $wallet_contract));
      $decimals = (int) $wallet_decimals;

      // token mode: contract present + decimals > 0
      if ($contract && preg_match('/^0x[a-f0-9]{40}$/', $contract) && $decimals > 0) {
        $order->update_meta_data('_corexa_evm_net', $net);
        $order->update_meta_data('_corexa_evm_coin', $coin);
        $order->update_meta_data('_corexa_evm_contract', $contract);
        $order->update_meta_data('_corexa_evm_decimals', (string) $decimals);

        $expected_units = COREXA_EVM_Poller::to_base_units_str($total_str, $decimals);
        $order->update_meta_data('_corexa_expected_token_units', (string) $expected_units);

        $order->update_meta_data('_corexa_payment_status', 'pending');
        if (method_exists('COREXA_EVM_Poller', 'schedule_order_check')) {
        }
      } else {
        // native mode
        $native_ok = (
          ($coin === 'ETH' && in_array($net, ['ERC20', 'BASE'], true)) ||
          ($coin === 'BNB' && $net === 'BEP20') ||
          ($coin === 'MATIC' && $net === 'POLYGON')
        );

        if ($native_ok && class_exists('COREXA_Rates')) {
          $price = (float) COREXA_Rates::get_usd_price($coin);
          $usd_total = (float) str_replace(',', '.', (string) $total_str);

          if ($price > 0 && $usd_total > 0) {
            $coin_amt = $usd_total / $price;

            $expected_wei = COREXA_EVM_Poller::to_base_units_str((string) $coin_amt, 18);

            // tolerance
            $tol_usd = min((float) self::TOL_USD_MAX, (float) ($usd_total * self::TOL_PCT));
            $tol_coin = $tol_usd / $price;
            $tol_wei = COREXA_EVM_Poller::to_base_units_str((string) $tol_coin, 18);
            $min_accept_wei = $this->corexa_bigint_sub($expected_wei, $tol_wei);

            $order->update_meta_data('_corexa_evm_net', $net);
            $order->update_meta_data('_corexa_evm_coin', $coin);
            $order->update_meta_data('_corexa_evm_rate_usd', (string) $price);
            $order->update_meta_data('_corexa_evm_expected_wei', (string) $expected_wei);
            $order->update_meta_data('_corexa_evm_min_accept_wei', (string) $min_accept_wei);

            $order->update_meta_data('_corexa_payment_status', 'pending');
            if (method_exists('COREXA_EVM_Poller', 'schedule_order_check')) {
            }
          }
        }
      }
    }

    // ===== SOL =====
    if (($coin === 'SOL' || $net === 'SOL') && class_exists('COREXA_SOL_Poller') && method_exists('COREXA_SOL_Poller', 'build_expected_meta')) {
      $meta = COREXA_SOL_Poller::build_expected_meta($total_str);
      if (!empty($meta)) {
        foreach ($meta as $k => $v)
          $order->update_meta_data($k, $v);
        $order->update_meta_data('_corexa_payment_status', 'pending');
        if (method_exists('COREXA_SOL_Poller', 'schedule_order_check')) {
        }
      }
    }

    // ===== XLM (require BOTH) =====
    if ($coin === 'XLM' && $net === 'XLM' && class_exists('COREXA_XLM_Poller') && method_exists('COREXA_XLM_Poller', 'build_expected_meta')) {
      if ($wallet_tag !== '') {
        $meta = COREXA_XLM_Poller::build_expected_meta($total_str);
        if (!empty($meta)) {
          foreach ($meta as $k => $v)
            $order->update_meta_data($k, $v);
          $order->update_meta_data('_corexa_payment_status', 'pending');
          if (method_exists('COREXA_XLM_Poller', 'schedule_order_check')) {
            COREXA_XLM_Poller::schedule_order_check($order->get_id());
          }
        }
      }
    }

    // ===== ADA =====
    if (($coin === 'ADA' || $net === 'ADA') && class_exists('COREXA_ADA_Poller') && method_exists('COREXA_ADA_Poller', 'build_expected_meta')) {
      $meta = COREXA_ADA_Poller::build_expected_meta($total_str);
      if (!empty($meta)) {
        foreach ($meta as $k => $v)
          $order->update_meta_data($k, $v);
        $order->update_meta_data('_corexa_payment_status', 'pending');
        if (method_exists('COREXA_ADA_Poller', 'schedule_order_check')) {
          COREXA_ADA_Poller::schedule_order_check($order->get_id());
        }
      }
    }

    // ===== UTXO =====
    if (in_array($coin, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH', 'DGB', 'MAZA', 'XVG'], true) && class_exists('COREXA_Rates')) {
      $usd_total = (float) str_replace(',', '.', (string) $total_str);
      $price = (float) COREXA_Rates::get_usd_price($coin);

      if ($usd_total > 0 && $price > 0) {
        $expected_sats = $this->corexa_usd_to_sats($usd_total, $price);

        $tol_usd = min((float) self::TOL_USD_MAX, (float) ($usd_total * self::TOL_PCT));
        $tol_sats = $this->corexa_usd_to_sats($tol_usd, $price);
        $min_accept_sats = max(0, $expected_sats - $tol_sats);

        $order->update_meta_data('_corexa_utxo_coin', $coin);
        $order->update_meta_data('_corexa_utxo_expected_sats', (string) $expected_sats);
        $order->update_meta_data('_corexa_utxo_min_accept_sats', (string) $min_accept_sats);
        $order->update_meta_data('_corexa_payment_status', 'pending');

        // ✅ schedule UTXO poller
        if (class_exists('COREXA_UTXO_Poller') && method_exists('COREXA_UTXO_Poller', 'schedule_order_check')) {
        }
      }
    }

    // Persist meta first
    $order->save();

    // Run one immediate check so tracking starts right away
    if ($coin === 'USDT' && $net === 'TRC20' && class_exists('COREXA_Tron_Poller') && method_exists('COREXA_Tron_Poller', 'check_order')) {
      COREXA_Tron_Poller::check_order($order->get_id());
    } elseif ($coin === 'XRP' && $net === 'XRP' && class_exists('COREXA_XRP_Poller') && method_exists('COREXA_XRP_Poller', 'check_order')) {
      COREXA_XRP_Poller::check_order($order->get_id());
    } elseif ($this->corexa_is_evm_net($net) && class_exists('COREXA_EVM_Poller') && method_exists('COREXA_EVM_Poller', 'check_order')) {
      COREXA_EVM_Poller::check_order($order->get_id());
    } elseif (($coin === 'SOL' || $net === 'SOL') && class_exists('COREXA_SOL_Poller') && method_exists('COREXA_SOL_Poller', 'check_order')) {
      COREXA_SOL_Poller::check_order($order->get_id());
    } elseif (in_array($coin, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH', 'DGB', 'MAZA', 'XVG'], true) && class_exists('COREXA_UTXO_Poller') && method_exists('COREXA_UTXO_Poller', 'check_order')) {
      COREXA_UTXO_Poller::check_order($order->get_id());
    }

    // Then schedule background follow-up checks
    if ($coin === 'USDT' && $net === 'TRC20' && class_exists('COREXA_Tron_Poller') && method_exists('COREXA_Tron_Poller', 'schedule_order_check')) {
      COREXA_Tron_Poller::schedule_order_check($order->get_id());
    } elseif ($coin === 'XRP' && $net === 'XRP' && class_exists('COREXA_XRP_Poller') && method_exists('COREXA_XRP_Poller', 'schedule_order_check')) {
      COREXA_XRP_Poller::schedule_order_check($order->get_id());
    } elseif ($this->corexa_is_evm_net($net) && class_exists('COREXA_EVM_Poller') && method_exists('COREXA_EVM_Poller', 'schedule_order_check')) {
      COREXA_EVM_Poller::schedule_order_check($order->get_id());
    } elseif (($coin === 'SOL' || $net === 'SOL') && class_exists('COREXA_SOL_Poller') && method_exists('COREXA_SOL_Poller', 'schedule_order_check')) {
      COREXA_SOL_Poller::schedule_order_check($order->get_id());
    } elseif ($coin === 'XLM' && $net === 'XLM' && class_exists('COREXA_XLM_Poller') && method_exists('COREXA_XLM_Poller', 'schedule_order_check')) {
      COREXA_XLM_Poller::schedule_order_check($order->get_id());
    } elseif (($coin === 'ADA' || $net === 'ADA') && class_exists('COREXA_ADA_Poller') && method_exists('COREXA_ADA_Poller', 'schedule_order_check')) {
      COREXA_ADA_Poller::schedule_order_check($order->get_id());
    } elseif (in_array($coin, ['BTC', 'LTC', 'BCH', 'DOGE', 'DASH', 'DGB', 'MAZA', 'XVG'], true) && class_exists('COREXA_UTXO_Poller') && method_exists('COREXA_UTXO_Poller', 'schedule_order_check')) {
      COREXA_UTXO_Poller::schedule_order_check($order->get_id());
    }
  }
}
