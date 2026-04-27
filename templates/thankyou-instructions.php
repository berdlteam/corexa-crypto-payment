<?php
if (!defined('ABSPATH')) exit;

(function () use (
  $qr_px,
  $coin,
  $network,
  $address,
  $tag,
  $total,
  $amount_crypto,
  $note,
  $icons_enabled,
  $icon_url,
  $expires_at,
  $qr_enabled,
  $qr,
  $show_address
) {

  // ✅ Prevent duplicate output if the hook fires twice
  static $corexa_already_rendered = false;
  if ($corexa_already_rendered) {
    return;
  }
  $corexa_already_rendered = true;

  /**
   * Build QR size class like: spg-qr-220 (steps of 20 between 120..600)
   */
  $corexa_qr_px_i = isset($qr_px) ? (int) $qr_px : 220;
  if ($corexa_qr_px_i < 120) $corexa_qr_px_i = 120;
  if ($corexa_qr_px_i > 600) $corexa_qr_px_i = 600;
  $corexa_qr_px_i = (int) (round($corexa_qr_px_i / 20) * 20);

  $corexa_qr_size_class = 'spg-qr-' . $corexa_qr_px_i;

  // Normalize expected vars to avoid notices
  $corexa_coin          = isset($coin) ? (string) $coin : '';
  $corexa_network       = isset($network) ? (string) $network : '';
  $corexa_address       = isset($address) ? (string) $address : '';
  $corexa_tag           = isset($tag) ? (string) $tag : '';
  $corexa_total         = $total ?? '';
  $corexa_amount_crypto = isset($amount_crypto) ? (string) $amount_crypto : '';
  $corexa_note          = $note ?? '';
  $corexa_addr          = $corexa_address; // alias used below

  // ✅ Robust QR handling
  $corexa_qr_src = '';
  $corexa_qr_is_data = false;

  $qr_enabled_bool = !empty($qr_enabled) && $qr_enabled !== 'no' && $qr_enabled !== '0';

  if ($qr_enabled_bool && !empty($qr) && is_string($qr)) {
    $corexa_q = trim($qr);

    // If already data URI (png/svg/etc) -> use as-is
    if (stripos($corexa_q, 'data:image/') === 0) {
      $corexa_qr_src = $corexa_q;
      $corexa_qr_is_data = true;

    } else {
      // If it's raw base64 (common), prefix as PNG data URI
      $maybe_b64 = preg_match('/^[A-Za-z0-9+\/=\s]+$/', $corexa_q) && strlen(preg_replace('/\s+/', '', $corexa_q)) > 100;

      if ($maybe_b64 && strpos($corexa_q, '://') === false && strpos($corexa_q, '<') === false) {
        $corexa_q = preg_replace('/\s+/', '', $corexa_q);
        $corexa_qr_src = 'data:image/png;base64,' . $corexa_q;
        $corexa_qr_is_data = true;

      } else {
        // Otherwise treat as URL
        $corexa_qr_src = $corexa_q;
        $corexa_qr_is_data = false;
      }
    }
  }

?>
  <section class="spg-ty">
    <div class="spg-ty-card">

      <!-- Top summary -->
      <div class="spg-ty-top">
        <div class="spg-ty-top-left">
          <?php if (!empty($icons_enabled) && !empty($icon_url)): ?>
            <img
              class="spg-ty-coin-dot"
              src="<?php echo esc_url($icon_url); ?>"
              alt=""
              loading="lazy"
              decoding="async" />
          <?php else: ?>
            <span class="spg-ty-coin-dot spg-ty-coin-dot--fallback"></span>
          <?php endif; ?>

          <?php if (!empty($expires_at) && (int) $expires_at > time()): ?>
            <span class="spg-ty-top-timer" data-expires="<?php echo (int) $expires_at; ?>">
              <span class="spg-ty-timer-text">--:--</span>
            </span>
          <?php endif; ?>
        </div>

        <div class="spg-ty-top-right">
          <?php if (!empty($corexa_total)): ?>
            <div class="spg-ty-top-amount"><?php echo wp_kses_post($corexa_total); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="spg-ty-body">
        <h2 class="spg-ty-title">
          <?php echo esc_html__('Pay with', 'corexa-crypto-payment'); ?>
          <?php
          $corexa_coin_full = function_exists('corexa_coin_full_name')
            ? corexa_coin_full_name($corexa_coin)
            : (function_exists('corexa_coin_full_name') ? corexa_coin_full_name($corexa_coin) : $corexa_coin);

          $corexa_net_short = function_exists('corexa_network_short')
            ? corexa_network_short($corexa_network)
            : (function_exists('corexa_network_short') ? corexa_network_short($corexa_network) : $corexa_network); ?>

          <?php if ($corexa_coin_full !== ''): ?>
            <span class="spg-ty-title-coin"><?php echo esc_html($corexa_coin_full); ?></span>
          <?php endif; ?>

          <?php if ($corexa_net_short !== ''): ?>
            <span class="spg-ty-title-net">
              <?php echo esc_html($corexa_net_short); ?> <span class="spg-ty-k">[network]</span>
            </span>
          <?php endif; ?>
        </h2>

        <div class="spg-ty-panel">
          <!-- QR -->
          <?php if ($corexa_qr_src !== ''): ?>
            <div class="spg-ty-qr <?php echo esc_attr($corexa_qr_size_class); ?>">
              <img
                class="spg-ty-qr-img"
                decoding="async"
                loading="eager"
                src="<?php echo $corexa_qr_is_data ? esc_attr($corexa_qr_src) : esc_url($corexa_qr_src); ?>"
                alt="<?php echo esc_attr__('Payment QR code', 'corexa-crypto-payment'); ?>" />
            </div>
          <?php else: ?>
            <div class="spg-ty-qr-missing" style="margin:12px 0; opacity:.75;">
              <?php echo esc_html__('QR not available.', 'corexa-crypto-payment'); ?>
            </div>
          <?php endif; ?>

          <p class="spg-ty-help">
            <?php echo esc_html__('Scan wallet address to send payment or copy wallet address below', 'corexa-crypto-payment'); ?>
          </p>

          <!-- Wallet row -->
          <div class="spg-ty-row spg-ty-row--split">
            <div class="spg-ty-kv">
              <div class="spg-ty-k"><?php echo esc_html__('Wallet address', 'corexa-crypto-payment'); ?></div>

              <?php if ($corexa_addr !== '' && (!isset($show_address) || $show_address)): ?>
                <div class="spg-ty-v spg-ty-v--mono">
                  <span class="spg-ty-addr-short">
                    <?php
                    $corexa_short = (strlen($corexa_addr) > 16)
                      ? substr($corexa_addr, 0, 6) . '…' . substr($corexa_addr, -10)
                      : $corexa_addr;
                    echo esc_html($corexa_short);
                    ?>
                  </span>

                  <button
                    type="button"
                    class="spg-ty-copy spg-copy-address-btn"
                    data-copy="<?php echo esc_attr($corexa_addr); ?>"
                    aria-label="<?php echo esc_attr__('Copy wallet address', 'corexa-crypto-payment'); ?>">⧉</button>

                  <span class="spg-copy-ok spg-ty-copied">
                    <?php echo esc_html__('Copied!', 'corexa-crypto-payment'); ?>
                  </span>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Amount row -->
          <div class="spg-ty-row spg-ty-row--split spg-ty-row--border">
            <div class="spg-ty-kv">
              <div class="spg-ty-k"><?php echo esc_html__('Amount to send', 'corexa-crypto-payment'); ?></div>
              <div class="spg-ty-v spg-ty-v--mono">
                <?php echo esc_html($corexa_amount_crypto); ?>
              </div>
            </div>

            <div class="spg-ty-kv spg-ty-kv--right">
              <div class="spg-ty-k"><?php echo esc_html__('equivalent', 'corexa-crypto-payment'); ?></div>
              <div class="spg-ty-v">
                <?php echo wp_kses_post($corexa_total ?: ''); ?>
              </div>
            </div>
          </div>

          <?php if (!empty($corexa_tag)): ?>
            <div class="spg-ty-row spg-ty-row--border">
              <div class="spg-ty-kv">
                <div class="spg-ty-k"><?php echo esc_html__('Tag / Memo', 'corexa-crypto-payment'); ?></div>
                <div class="spg-ty-v spg-ty-v--mono"><?php echo esc_html($corexa_tag); ?></div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($corexa_note)): ?>
          <div class="spg-ty-note">
            <?php echo wp_kses_post(wpautop($corexa_note)); ?>
          </div>
        <?php endif; ?>

        <p class="spg-ty-foot">
          <?php
          echo wp_kses_post(
            __('Send your payment.<br>Sit back and relax :)<br>The system will <strong>automatically</strong> verify it and complete your order.', 'corexa-crypto-payment')
          );
          ?>
        </p>
      </div>
    </div>
  </section>
<?php
})();