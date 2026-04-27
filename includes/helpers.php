<?php
if (!defined('ABSPATH')) exit;


/**
 * Get all wallets from options.
 */
if (!function_exists('corexa_get_wallets')) {
  function corexa_get_wallets(): array {
    $wallets = get_option('corexa_wallets', []);
    return is_array($wallets) ? $wallets : [];
  }
}

/**
 * Get only enabled wallets (and minimally valid ones).
 */
if (!function_exists('corexa_get_wallets_enabled')) {
  function corexa_get_wallets_enabled(): array {
    $wallets = corexa_get_wallets();
    $out = [];

    foreach ($wallets as $w) {
      if (empty($w['enabled'])) continue;
      if (empty($w['key']) || empty($w['coin']) || empty($w['address'])) continue;
      $out[] = $w;
    }

    return $out;
  }
}

/**
 * Wallet catalog for admin dropdown 
 * value => [label, coin, network, decimals_default(optional)]
 *
 * NOTE:
 * - network values should match normalized nets: TRC20, ERC20, BEP20, POLYGON, BASE, BTC, etc.
 * - decimals is optional (null for UTXO etc.)
 */
if (!function_exists('corexa_currency_catalog')) {
  function corexa_currency_catalog(): array {
    return [

      // =========================
      // Main blockchains
      // =========================
      'BASE_ETH' => ['BASE_ETH ETH on BASE', 'ETH', 'BASE', 18],
      'BCH'      => ['BCH Bitcoin Cash', 'BCH', 'BCH', null],
      'BNB'      => ['BNB Binance Coin', 'BNB', 'BEP20', 18],
      'BTC'      => ['BTC Bitcoin', 'BTC', 'BTC', null],
      'DASH'     => ['DASH Dash', 'DASH', 'DASH', null],
      'DGB'      => ['DGB DigiByte', 'DGB', 'DGB', null],
      'DOGE'     => ['DOGE Dogecoin', 'DOGE', 'DOGE', null],
      'ETH'      => ['ETH Ethereum', 'ETH', 'ERC20', 18],
      'LTC'      => ['LTC Litecoin', 'LTC', 'LTC', null],
      'MATIC'    => ['MATIC Polygon Network', 'MATIC', 'POLYGON', 18],
      'MAZA'     => ['MAZA Maza', 'MAZA', 'MAZA', null],
      'SOL'      => ['SOL Solana', 'SOL', 'SOL', null],
      'TRX'      => ['TRX Tron', 'TRX', 'TRX', null],
      'XLM'      => ['XLM Stellar', 'XLM', 'XLM', null],
      'XRP'      => ['XRP XRP', 'XRP', 'XRP', null],
      'XVG'      => ['XVG Verge', 'XVG', 'XVG', null],
      'ADA'      => ['ADA Cardano', 'ADA', 'ADA', 6],

      // =========================
      // BASE Network
      // =========================
      'BASE_EARN' => ['BASE_EARN Hold (Base Network)', 'EARN', 'BASE', 18],
      'BASE_USDC' => ['BASE_USDC USDC', 'USDC', 'BASE', 6],
      'BASE_USDT' => ['BASE_USDT USDT', 'USDT', 'BASE', 6],
      'BASE_WETH' => ['BASE_WETH Wrapped Ether', 'WETH', 'BASE', 18],
      'DEGEN'     => ['DEGEN Degen', 'DEGEN', 'BASE', 18],

      // =========================
      // BEP20 Tokens
      // =========================
      'BAKE'       => ['BAKE BakeryToken', 'BAKE', 'BEP20', 18],
      'BEP20USDC'  => ['BEP20USDC Binance-Peg USDC', 'USDC', 'BEP20', 18],
      'BEP20USDT'  => ['BEP20USDT Binance-Peg BSC-USD', 'USDT', 'BEP20', 18],
      'BEP20_EARN' => ['BEP20_EARN Hold (BSC Network)', 'EARN', 'BEP20', 18],
      'BUSD'       => ['BUSD Binance-Peg BUSD', 'BUSD', 'BEP20', 18],
      'CAKE'       => ['CAKE PancakeSwap Token', 'CAKE', 'BEP20', 18],
      'HOW'        => ['HOW HowInu', 'HOW', 'BEP20', 18],
      'SXP'        => ['SXP Swipe', 'SXP', 'BEP20', 18],
      'VAI'        => ['VAI VAI Stablecoin', 'VAI', 'BEP20', 18],
      'XVS'        => ['XVS Venus', 'XVS', 'BEP20', 18],

      // =========================
      // ERC20 Tokens
      // =========================
      '1INCH'  => ['1INCH 1inch', '1INCH', 'ERC20', 18],
      'AAVE'   => ['AAVE Aave', 'AAVE', 'ERC20', 18],
      'ANT'    => ['ANT Aragon', 'ANT', 'ERC20', 18],
      'APE'    => ['APE ApeCoin', 'APE', 'ERC20', 18],
      'BAT'    => ['BAT Basic Attention Token', 'BAT', 'ERC20', 18],
      'BNT'    => ['BNT Bancor', 'BNT', 'ERC20', 18],
      'COMP'   => ['COMP Compound', 'COMP', 'ERC20', 18],
      'DAI'    => ['DAI Multi-collateral DAI', 'DAI', 'ERC20', 18],
      'DRGN'   => ['DRGN Dragonchain', 'DRGN', 'ERC20', 18],
      'EFI'    => ['EFI Efinity Token', 'EFI', 'ERC20', 18],
      'ENJ'    => ['ENJ Enjin Coin', 'ENJ', 'ERC20', 18],
      'EURC'   => ['EURC EURC', 'EURC', 'ERC20', 6],
      'FET'    => ['FET Fetch.ai', 'FET', 'ERC20', 18],
      'FUN'    => ['FUN FUNToken', 'FUN', 'ERC20', 8],
      'GLM'    => ['GLM Golem', 'GLM', 'ERC20', 18],
      'GROW'   => ['GROW Grow', 'GROW', 'ERC20', 18],
      'GTC'    => ['GTC Gitcoin', 'GTC', 'ERC20', 18],
      'GUSD'   => ['GUSD Gemini Dollar', 'GUSD', 'ERC20', 2],
      'HT'     => ['HT HuobiToken', 'HT', 'ERC20', 18],
      'IMX'    => ['IMX Immutable', 'IMX', 'ERC20', 18],
      'KNC'    => ['KNC Kyber Network Crystal', 'KNC', 'ERC20', 18],
      'LEO'    => ['LEO UNUS SED LEO', 'LEO', 'ERC20', 18],
      'LINK'   => ['LINK ChainLink Token', 'LINK', 'ERC20', 18],
      'LOOM'   => ['LOOM Loom', 'LOOM', 'ERC20', 18],
      'MANA'   => ['MANA Decentraland', 'MANA', 'ERC20', 18],
      'MIRX'   => ['MIRX Mirada AI', 'MIRX', 'ERC20', 18],
      'MKR'    => ['MKR Maker', 'MKR', 'ERC20', 18],
      'MTL'    => ['MTL MetalPay', 'MTL', 'ERC20', 8],
      'NEXO'   => ['NEXO Nexo', 'NEXO', 'ERC20', 18],
      'OMG'    => ['OMG OMG Network', 'OMG', 'ERC20', 18],
      'PAY'    => ['PAY TenXPay', 'PAY', 'ERC20', 18],
      'PEPE'   => ['PEPE Pepe', 'PEPE', 'ERC20', 18],
      'POLY'   => ['POLY Polymath', 'POLY', 'ERC20', 18],
      'POL'    => ['POL Polygon', 'POL', 'ERC20', 18],
      'PPT'    => ['PPT Populous', 'PPT', 'ERC20', 8],
      'PUNDIX' => ['PUNDIX Pundi X', 'PUNDIX', 'ERC20', 18],
      'REEF'   => ['REEF Reef', 'REEF', 'ERC20', 18],
      'REQ'    => ['REQ Request', 'REQ', 'ERC20', 18],
      'SHIB'   => ['SHIB Shiba Inu', 'SHIB', 'ERC20', 18],
      'SNT'    => ['SNT Status Network', 'SNT', 'ERC20', 18],
      'SNX'    => ['SNX Synthetix', 'SNX', 'ERC20', 18],
      'SWITCH' => ['SWITCH Switch', 'SWITCH', 'ERC20', 18],
      'TUSD'   => ['TUSD TrueUSD', 'TUSD', 'ERC20', 18],
      'UNI'    => ['UNI Uniswap', 'UNI', 'ERC20', 18],
      'USDC'   => ['USDC USD Coin', 'USDC', 'ERC20', 6],
      'USDT_ERC20' => ['USDT_ERC20 Tether (Ethereum)', 'USDT', 'ERC20', 6],
      'VERI'   => ['VERI Veritaseum', 'VERI', 'ERC20', 18],
      'WBTC'   => ['WBTC Wrapped BTC', 'WBTC', 'ERC20', 8],
      'WLD'    => ['WLD Worldcoin', 'WLD', 'ERC20', 18],
      'ZRX'    => ['ZRX 0x Protocol', 'ZRX', 'ERC20', 18],

      // =========================
      // Polygon (MATIC) Tokens
      // =========================
      'MATIC_USDT' => ['MATIC_USDT USDT on Polygon Network', 'USDT', 'POLYGON', 6],
      'MATIC_USDC' => ['MATIC_USDC USDC on Polygon Network', 'USDC', 'POLYGON', 6],
      'NSFW'       => ['NSFW Pleasure Coin', 'NSFW', 'POLYGON', 18],
      'OPEX'       => ['OPEX Operational Excellence Token', 'OPEX', 'POLYGON', 18],

      // =========================
      // Tron tokens
      // =========================
      'USDT_TRON' => ['USDT_TRON Tether (Tron)', 'USDT', 'TRC20', 6],
    ];
  }
}

if (!function_exists('corexa_currency_guess_key')) {
  function corexa_currency_guess_key(string $coin, string $network): string {
    $coin = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $coin));
    $network = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $network));

    // ---------- USDT ----------
    if ($coin === 'USDT') {
      if ($network === 'TRC20')   return 'USDT_TRON';
      if ($network === 'ERC20')   return 'USDT_ERC20';
      if ($network === 'BEP20')   return 'BEP20USDT';
      if ($network === 'POLYGON') return 'MATIC_USDT';
      if ($network === 'BASE')    return 'BASE_USDT';
    }

    // ---------- USDC ----------
    if ($coin === 'USDC') {
      if ($network === 'ERC20')   return 'USDC';
      if ($network === 'BEP20')   return 'BEP20USDC';
      if ($network === 'POLYGON') return 'MATIC_USDC';
      if ($network === 'BASE')    return 'BASE_USDC';
    }

    // ---------- BASE ----------
    if ($network === 'BASE') {
      if ($coin === 'ETH')   return 'BASE_ETH';
      if ($coin === 'EARN')  return 'BASE_EARN';
      if ($coin === 'WETH')  return 'BASE_WETH';
      if ($coin === 'DEGEN') return 'DEGEN';
    }

    // ---------- BEP20 ----------
    if ($network === 'BEP20') {
      if ($coin === 'EARN') return 'BEP20_EARN';
      if (in_array($coin, ['BAKE', 'BUSD', 'CAKE', 'HOW', 'SXP', 'VAI', 'XVS'], true)) {
        return $coin;
      }
    }

    // ---------- POLYGON tokens ----------
    if ($network === 'POLYGON') {
      if ($coin === 'USDT') return 'MATIC_USDT';
      if ($coin === 'USDC') return 'MATIC_USDC';
      if (in_array($coin, ['NSFW', 'OPEX'], true)) return $coin;
    }

    // ---------- MAIN CHAINS (1:1) ----------
    $direct = [
      'BTC','BCH','LTC','DOGE','DASH','DGB','MAZA','XVG',
      'TRX','ETH','BNB','MATIC','SOL','XRP','XLM','ADA','XMR',
    ];

    if (in_array($coin, $direct, true)) {
      return $coin;
    }

    // ---------- ERC20 tokens ----------
    $erc20 = [
      '1INCH','AAVE','ANT','APE','BAT','BNT','COMP','DAI','DRGN','EFI','ENJ','EURC','FET',
      'FUN','GLM','GROW','GTC','GUSD','HT','IMX','KNC','LEO','LINK','LOOM','MANA','MIRX',
      'MKR','MTL','NEXO','OMG','PAY','PEPE','POLY','POL','PPT','PUNDIX','REEF','REQ','SHIB',
      'SNT','SNX','SWITCH','TUSD','UNI','VERI','WBTC','WLD','ZRX'
    ];

    if ($network === 'ERC20' && in_array($coin, $erc20, true)) {
      return $coin;
    }

    return '__CUSTOM__';
  }
}

if (!function_exists('corexa_currency_meta')) {
  function corexa_currency_meta(string $key): array {
    $cat = corexa_currency_catalog();
    return $cat[$key] ?? [];
  }
}

/**
 * Checkout dropdown label formatter
 */
if (!function_exists('corexa_wallet_select_label')) {
  function corexa_wallet_select_label(string $coin, string $network): string {
    $coin_raw = trim($coin);
    $coin = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $coin_raw));

    $net = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $network));
    $net_map = [
      'TRON' => 'TRC20',
      'TRC'  => 'TRC20',
      'ETH'  => 'ERC20',
      'ERC'  => 'ERC20',
      'BSC'  => 'BEP20',
      'BEP'  => 'BEP20',
    ];
    if (isset($net_map[$net])) $net = $net_map[$net];

    if ($coin === 'USDT') {
      return 'USDT [' . ($net !== '' ? $net : 'USDT') . ']';
    }

    $name_map = [
      'BTC'  => 'Bitcoin',
      'ETH'  => 'Ethereum',
      'BCH'  => 'Bitcoin Cash',
      'LTC'  => 'Litecoin',
      'DOGE' => 'Dogecoin',
      'DASH' => 'Dash',
      'DGB'  => 'DigiByte',
      'SOL'  => 'Solana',
      'TRX'  => 'Tron',
      'XRP'  => 'XRP',
      'XLM'  => 'Stellar',
      'ADA'  => 'Cardano',
      'MAZA' => 'Maza',
      'XVG'  => 'Verge',
      'BNB'  => 'BNB',
      'MATIC'=> 'Polygon',
      'DAI'  => 'DAI',
      'USDC' => 'USDC',
    ];

    $full = $name_map[$coin] ?? ($coin_raw !== '' ? $coin_raw : $coin);
    return trim($full) . ' [' . $coin . ']';
  }
}

/**
 * Thank-you label formatter (Full coin name)
 */
if (!function_exists('corexa_coin_full_name')) {
  function corexa_coin_full_name(string $coin): string {
    $c = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $coin));

    $map = [
      'BTC'  => 'Bitcoin',
      'ETH'  => 'Ethereum',
      'USDT' => 'USDT',
      'USDC' => 'USDC',
      'DAI'  => 'DAI',
      'BCH'  => 'Bitcoin Cash',
      'LTC'  => 'Litecoin',
      'DOGE' => 'Dogecoin',
      'TRX'  => 'Tron',
      'SOL'  => 'Solana',
      'XRP'  => 'XRP',
      'XLM'  => 'Stellar',
      'ADA'  => 'Cardano',
      'DASH' => 'Dash',
      'DGB'  => 'DigiByte',
      'MAZA' => 'Maza',
      'XVG'  => 'Verge',
      'BNB'  => 'BNB',
      'MATIC'=> 'Polygon',
    ];

    return $map[$c] ?? ($coin !== '' ? $coin : $c);
  }
}

/**
 * Thank-you label formatter (Short network name)
 */
if (!function_exists('corexa_network_short')) {
  function corexa_network_short(string $network): string {
    $n = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $network));

    $map = [
      'TRON' => 'TRC20',
      'TRC'  => 'TRC20',
      'ETH'  => 'ERC20',
      'ERC'  => 'ERC20',
      'BSC'  => 'BEP20',
      'BEP'  => 'BEP20',
    ];

    return $map[$n] ?? ($n !== '' ? $n : $network);
  }
}


