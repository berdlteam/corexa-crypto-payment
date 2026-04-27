<?php
if (!defined('ABSPATH')) exit;

class COREXA_QR {

  /**
   * Returns the raw QR payload string.
   * This payload is rendered locally in the browser by a bundled JS QR library.
   */
  public static function get_payload(string $address, string $coin = '', string $network = '', string $tag = '', string $amount = ''): string {
    return self::build_payload($address, $coin, $network, $tag, $amount);
  }

  /**
   * Backward-compatible alias in case other plugin files still call get_url().
   * We now return the payload instead of a remote URL.
   */
public static function get_url(string $address, string $coin = '', string $network = '', string $tag = '', int $size_px = 220): string {

  $payload = self::build_payload($address, $coin, $network, $tag, '');

  if ($payload === '') {
    return '';
  }

  $size_px = max(120, min(600, $size_px));

  return 'https://api.qrserver.com/v1/create-qr-code/?size='
    . $size_px . 'x' . $size_px
    . '&data=' . rawurlencode($payload);
}

  private static function build_payload(string $address, string $coin, string $network, string $tag, string $amount = ''): string {

    $address = trim($address);
    if ($address === '') {
      return '';
    }

    $coin    = strtoupper(trim($coin));
    $network = strtoupper(trim($network));
    $tag     = trim($tag);
    $amount  = trim($amount);

    // XRP destination tag
    if (($coin === 'XRP' || $network === 'XRP') && $tag !== '') {
      return $address . '?dt=' . rawurlencode($tag);
    }

    // XLM memo
    if (($coin === 'XLM' || $network === 'XLM') && $tag !== '') {
      return $address . '?memo=' . rawurlencode($tag);
    }

    // Simple amount support if needed later
    if ($amount !== '') {
      return $address . '?amount=' . rawurlencode($amount);
    }

    return $address;
  }
}