<?php
/**
 * Plugin Name: Corexa crypto payment
 * Plugin URI: https://berdl.com
 * Description: Accept cryptocurrency payments in WooCommerce using your own wallet addresses with automatic blockchain verification.
 * Version: 1.0.0
 * Author: Nazeli
 * Text Domain: corexa-crypto-payment
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!defined('COREXA_VERSION')) {
  define('COREXA_VERSION', '1.0.0');
}
if (!defined('COREXA_PATH')) {
  define('COREXA_PATH', plugin_dir_path(__FILE__));
}
if (!defined('COREXA_URL')) {
  define('COREXA_URL', plugin_dir_url(__FILE__));
}

/**
 * Ensure "minute" cron schedule exists globally.
 * (Activation hooks may run before poller classes add this schedule.)
 */
add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['minute'])) {
    $schedules['minute'] = [
      'interval' => 60,
      'display'  => 'Every Minute',
    ];
  }
  return $schedules;
});

/**
 * Safe require helper (prevents ../ traversal).
 */
function corexa_safe_require($relative_path)
{
  $relative_path = ltrim((string) $relative_path, '/\\');
  $relative_path = str_replace(['..\\', '../'], '', $relative_path);

  $file = COREXA_PATH . $relative_path;
  if (file_exists($file)) {
    require_once $file;
    return true;
  }
  return false;
}

function corexa_admin_notice($type, $message)
{
  add_action('admin_notices', function () use ($type, $message) {
    if (!current_user_can('activate_plugins')) {
      return;
    }

    printf(
      '<div class="notice notice-%1$s"><p>%2$s</p></div>',
      esc_attr($type),
      wp_kses_post($message)
    );
  });
}



add_action('plugins_loaded', function () {

  // Woo required
  if (!class_exists('WooCommerce')) {
    corexa_admin_notice('error', '<strong>Corexa crypto payment</strong> requires <strong>WooCommerce</strong> to be installed and active.');
    return;
  }

  /**
   * THANK YOU PAGE: add body class ONLY for orders paid via our gateway
   */
  add_filter('body_class', function ($classes) {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
      return $classes;
    }
    if (!function_exists('wc_get_order')) {
      return $classes;
    }

    $order_id = absint(get_query_var('order-received'));
    if (!$order_id) {
      return $classes;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
      return $classes;
    }

    if ($order->get_payment_method() === 'corexa_crypto_manual') {
      $classes[] = 'spg-ty-hide-header';
    }

    return $classes;
  }, 20);

  /**
   * THANK YOU PAGE: replace "order received" notice ONLY for our gateway
   */
  add_filter('woocommerce_thankyou_order_received_text', function ($text, $order) {

    if (!($order instanceof WC_Order)) {
      return $text;
    }

    if ($order->get_payment_method() !== 'corexa_crypto_manual') {
      return $text;
    }

    $msg  = __('Make your transfer using the details below. After it’s sent, your order will be completed AUTOMATICALLY once the payment is confirmed.', 'corexa-crypto-payment');
    $icon = '<span class="spg-ty-info-icon" aria-hidden="true">i</span>';

    return '<span class="spg-ty-order-received">'
      . $icon
      . '<span class="spg-ty-order-received-text">' . wp_kses_post($msg) . '</span>'
      . '</span>';
  }, 20, 2);

  /**
   * THANK YOU assets: enqueue ONLY on order-received page and ONLY for our gateway
   */
  add_action('wp_enqueue_scripts', function () {

    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
      return;
    }
    if (!function_exists('wc_get_order')) {
      return;
    }

    $order_id = absint(get_query_var('order-received'));
    if (!$order_id) {
      return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
      return;
    }

    if ($order->get_payment_method() !== 'corexa_crypto_manual') {
      return;
    }

    wp_enqueue_style('spg-thankyou', COREXA_URL . 'assets/css/thankyou.css', [], COREXA_VERSION);
    wp_enqueue_script('spg-thankyou', COREXA_URL . 'assets/js/thankyou.js', [], COREXA_VERSION, true);
  }, 20);

  /**
   * GLOBAL cancel handler (not inside the gateway class)
   */
  add_action('corexa_timer_cancel_unpaid_order', function ($order_id) {
    if (!function_exists('wc_get_order')) {
      return;
    }

    $order = wc_get_order((int)$order_id);
    if (!$order) {
      return;
    }
    if ($order->is_paid()) {
      return;
    }

    $st = $order->get_status();
    if (!in_array($st, ['pending', 'on-hold'], true)) {
      return;
    }

    $expires_at = (int)$order->get_meta('_corexa_timer_expires_at');
    if ($expires_at > 0 && time() < $expires_at) {
      return;
    }

    $order->update_status('cancelled', __('Payment timer expired - order automatically cancelled.', 'corexa-crypto-payment'));
  }, 10, 1);

  // Core includes
  corexa_safe_require('includes/class-spg-api.php');
  corexa_safe_require('includes/class-spg-qr.php');

  if (!class_exists('COREXA_QR')) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: QR class not loaded (<code>includes/class-spg-qr.php</code>).');
  }

  // Helpers
  $ok_helpers = corexa_safe_require('includes/helpers.php');
  if (!$ok_helpers) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/helpers.php</code>.');
  }

  // Wallets admin
  $ok_admin = corexa_safe_require('includes/class-spg-wallets-admin.php');
  if (!$ok_admin) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-wallets-admin.php</code>.');
  } else {
    if (class_exists('COREXA_Wallets_Admin') && method_exists('COREXA_Wallets_Admin', 'init')) {
      COREXA_Wallets_Admin::init();
    }
  }

  // Order admin UI block
  $ok_order_admin = corexa_safe_require('includes/class-spg-order-admin.php');
  if (!$ok_order_admin) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-order-admin.php</code>.');
  } else {
    if (class_exists('COREXA_Order_Admin') && method_exists('COREXA_Order_Admin', 'init')) {
      COREXA_Order_Admin::init();
    }
  }

  // TRON poller
  $ok_tron = corexa_safe_require('includes/class-spg-tron-poller.php');
  if (!$ok_tron) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-tron-poller.php</code>. TRON auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_Tron_Poller') && method_exists('COREXA_Tron_Poller', 'init')) {
      COREXA_Tron_Poller::init();
      if (method_exists('COREXA_Tron_Poller', 'ensure_recurring_poll')) {
        COREXA_Tron_Poller::ensure_recurring_poll();
      }
    }
  }

  // SOL poller
  $ok_sol = corexa_safe_require('includes/class-spg-sol-poller.php');
  if (!$ok_sol) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-sol-poller.php</code>. SOL auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_SOL_Poller') && method_exists('COREXA_SOL_Poller', 'init')) {
      COREXA_SOL_Poller::init();
      if (method_exists('COREXA_SOL_Poller', 'ensure_recurring_poll')) {
        COREXA_SOL_Poller::ensure_recurring_poll();
      }
    }
  }

  // EVM poller
  $ok_evm = corexa_safe_require('includes/class-spg-evm-poller.php');
  if (!$ok_evm) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-evm-poller.php</code>. EVM auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_EVM_Poller') && method_exists('COREXA_EVM_Poller', 'init')) {
      COREXA_EVM_Poller::init();
      if (method_exists('COREXA_EVM_Poller', 'ensure_recurring_poll')) {
        COREXA_EVM_Poller::ensure_recurring_poll();
      }
    }
  }

  // XRP poller
  $ok_xrp = corexa_safe_require('includes/class-spg-xrp-poller.php');
  if (!$ok_xrp) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-xrp-poller.php</code>. XRP auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_XRP_Poller') && method_exists('COREXA_XRP_Poller', 'init')) {
      COREXA_XRP_Poller::init();
      if (method_exists('COREXA_XRP_Poller', 'ensure_recurring_poll')) {
        COREXA_XRP_Poller::ensure_recurring_poll();
      }
    }
  }

  // XLM poller
  $ok_xlm = corexa_safe_require('includes/class-spg-xlm-poller.php');
  if (!$ok_xlm) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-xlm-poller.php</code>. XLM auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_XLM_Poller') && method_exists('COREXA_XLM_Poller', 'init')) {
      COREXA_XLM_Poller::init();
      if (method_exists('COREXA_XLM_Poller', 'ensure_recurring_poll')) {
        COREXA_XLM_Poller::ensure_recurring_poll();
      }
    }
  }

  // ADA poller
  $ok_ada = corexa_safe_require('includes/class-spg-ada-poller.php');
  if (!$ok_ada) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-ada-poller.php</code>. ADA auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_ADA_Poller') && method_exists('COREXA_ADA_Poller', 'init')) {
      COREXA_ADA_Poller::init();
      if (method_exists('COREXA_ADA_Poller', 'ensure_recurring_poll')) {
        COREXA_ADA_Poller::ensure_recurring_poll();
      }
    }
  }

  // Rates + UTXO poller
  $ok_rates = corexa_safe_require('includes/class-spg-rates.php');
  if (!$ok_rates) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-rates.php</code>. BTC-family auto-tracking disabled.');
  }

  $ok_utxo = corexa_safe_require('includes/class-spg-utxo-poller.php');
  if (!$ok_utxo) {
    corexa_admin_notice('warning', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-utxo-poller.php</code>. BTC-family auto-tracking disabled.');
  } else {
    if (class_exists('COREXA_UTXO_Poller') && method_exists('COREXA_UTXO_Poller', 'init')) {
      COREXA_UTXO_Poller::init();
      if (method_exists('COREXA_UTXO_Poller', 'ensure_recurring_poll')) {
        COREXA_UTXO_Poller::ensure_recurring_poll();
      }
    }
  }

  // Gateway
  $ok_gateway = corexa_safe_require('includes/class-spg-gateway.php');
  if (!$ok_gateway) {
    corexa_admin_notice('error', '<strong>Corexa crypto payment</strong>: missing file <code>includes/class-spg-gateway.php</code>.');
    return;
  }

  add_filter('woocommerce_payment_gateways', function ($methods) {
    if (class_exists('WC_Gateway_COREXA_Crypto')) {
      $methods[] = 'WC_Gateway_COREXA_Crypto';
    }
    return $methods;
  });
}, 20);

/**
 * Activation: WP-Cron fallback schedules (only if Action Scheduler not available)
 */
register_activation_hook(__FILE__, function () {

  // If Action Scheduler is present, pollers schedule themselves via AS.
  if (!function_exists('as_next_scheduled_action')) {

    if (!wp_next_scheduled('corexa_tron_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_tron_cron_poll');
    }
    if (!wp_next_scheduled('corexa_sol_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_sol_cron_poll');
    }
    if (!wp_next_scheduled('corexa_evm_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_evm_cron_poll');
    }
    if (!wp_next_scheduled('corexa_utxo_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_utxo_cron_poll');
    }

    // Added (you have these pollers in your folder)
    if (!wp_next_scheduled('corexa_xrp_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_xrp_cron_poll');
    }
    if (!wp_next_scheduled('corexa_xlm_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_xlm_cron_poll');
    }
    if (!wp_next_scheduled('corexa_ada_cron_poll')) {
      wp_schedule_event(time() + 60, 'minute', 'corexa_ada_cron_poll');
    }
  }
});

register_deactivation_hook(__FILE__, function () {

  if (wp_next_scheduled('corexa_tron_cron_poll')) {
    wp_clear_scheduled_hook('corexa_tron_cron_poll');
  }
  if (wp_next_scheduled('corexa_sol_cron_poll')) {
    wp_clear_scheduled_hook('corexa_sol_cron_poll');
  }
  if (wp_next_scheduled('corexa_evm_cron_poll')) {
    wp_clear_scheduled_hook('corexa_evm_cron_poll');
  }
  if (wp_next_scheduled('corexa_utxo_cron_poll')) {
    wp_clear_scheduled_hook('corexa_utxo_cron_poll');
  }

  // Added (you have these pollers in your folder)
  if (wp_next_scheduled('corexa_xrp_cron_poll')) {
    wp_clear_scheduled_hook('corexa_xrp_cron_poll');
  }
  if (wp_next_scheduled('corexa_xlm_cron_poll')) {
    wp_clear_scheduled_hook('corexa_xlm_cron_poll');
  }
  if (wp_next_scheduled('corexa_ada_cron_poll')) {
    wp_clear_scheduled_hook('corexa_ada_cron_poll');
  }
});