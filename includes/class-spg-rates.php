<?php
if (!defined('ABSPATH'))
  exit;

class COREXA_Rates
{

  const TRANSIENT_KEY = 'corexa_rates_usd';
  const CACHE_SECONDS = 60;

  /**
   * CoinGecko IDs for coins we convert from USD.
   * (Not wallets — safe to keep in code.)
   */
  private static function ids_map()
  {
    return [
      'BTC' => 'bitcoin',
      'LTC' => 'litecoin',
      'BCH' => 'bitcoin-cash',
      'DOGE' => 'dogecoin',
      'DASH' => 'dash',
      'DGB' => 'digibyte',
      'ETH' => 'ethereum',
      'BNB' => 'binancecoin',
      'MATIC' => 'polygon-pos',
      'XRP' => 'ripple',
      'SOL' => 'solana',
      'MAZA' => 'maza',
      'XLM' => 'stellar',
      'ADA' => 'cardano',
      'XVG' => 'verge',

    ];
  }

  public static function get_usd_price($coin)
  {
    $coin = strtoupper(trim((string) $coin));
    $all = self::get_all_usd_prices();
    return isset($all[$coin]) ? (float) $all[$coin] : 0.0;
  }

  public static function get_all_usd_prices()
  {
    $cached = get_transient(self::TRANSIENT_KEY);
    if (is_array($cached) && !empty($cached)) {
      return $cached;
    }

    $ids = array_values(self::ids_map());
    $url = 'https://api.coingecko.com/api/v3/simple/price?' . http_build_query([
      'ids' => implode(',', $ids),
      'vs_currencies' => 'usd',
    ]);

    $res = wp_remote_get($url, [
      'timeout' => 15,
      'headers' => [
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($res))
      return [];

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code !== 200 || $body === '')
      return [];

    $json = json_decode($body, true);
    if (!is_array($json))
      return [];

    $out = [];
    foreach (self::ids_map() as $sym => $id) {
      $usd = isset($json[$id]['usd']) ? (float) $json[$id]['usd'] : 0.0;
      if ($usd > 0)
        $out[$sym] = $usd;
    }

    set_transient(self::TRANSIENT_KEY, $out, self::CACHE_SECONDS);
    return $out;
  }
}
