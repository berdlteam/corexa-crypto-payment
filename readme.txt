=== Corexa crypto payment ===
Contributors: nazebinan
Tags: cryptocurrency, crypto, woocommerce, crypto payments, bitcoin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce using your own wallet addresses, with automatic payment verification.

== Description ==

Accept crypto payments directly to your own wallets - no third parties getting in the way. 

If you've been looking for a crypto payment gateway WordPress solution that puts you in control, you've found it. Corexa lets you accept cryptocurrency payments in WooCommerce using your own personal crypto wallet addresses. 

No middlemen, No extra fees, No KYC and No annoying sign-up forms.

Just add your wallet addresses and start receiving payments from customers all over the world.

Whether you’re selling products, services, or memberships, this plugin turns your WooCommerce store into a powerful crypto payment system in minutes.

### Key Advantages

* Multi-Coin support - Accept BTC, ETH, USDT, SOL, XRP, BNB, PEPE, USDC, TRON, DOGE — and almost any other cryptocurrency your wallet supports.
* Smart checkout - Customers choose their favorite network easily.
* QR codes - Display wallet addresses and QR codes for quick scanning.
* Live updates - Automatic order status changes after cryptocurrency transactions are confirmed.
* Custom notes - Add friendly instructions for your buyers.
* Privacy first - We don't store or handle your funds. Payments go directly from customer to merchant.
* Crypto payment gateway api - No API needed — the process is simple and beginner-friendly.


### How it works

Setting up Woocommerce crypto payments has never been this simple. You don't need to be a tech expert to get started.


1.Connect your wallet: Add your cryptocurrency wallet addresses in the settings. (Already know how to open crypto wallet accounts? Just copy and paste your address!)
2.Customer checkout: Shoppers select crypto payments with their preferred coin at checkout.
3.Send crypto: Your customer sends crypto from their end.
4.Auto-Confirm: Corexa checks the blockchain automatically. Once confirmed, the order status updates instantly.

== External services ==

These services are only used to query public blockchain data required to verify incoming payments.

This plugin connects to external blockchain APIs and public RPC/explorer services to verify incoming cryptocurrency payments and update WooCommerce orders automatically after payment confirmation.

The plugin does not transmit personal customer data. Only blockchain addresses and transaction lookup requests required for payment verification may be sent to these services. Depending on the selected cryptocurrency/network, the plugin may send the configured wallet address, transaction lookup parameters, and related blockchain query data required to check whether a payment was received and confirmed.

The plugin may connect to the following services:

1. Etherscan
Used for Ethereum blockchain transaction verification.
Data sent: wallet address and transaction lookup requests required to detect incoming transactions.
When sent: during automatic payment verification for ERC20 / Ethereum payments.
Terms: https://etherscan.io/terms
Privacy: https://etherscan.io/privacyPolicy

2. BscScan
Used for Binance Smart Chain transaction verification.
Data sent: wallet address and transaction lookup requests required to detect incoming transactions.
When sent: during automatic payment verification for BEP20 payments.
Terms: https://bscscan.com/terms
Privacy: https://bscscan.com/privacyPolicy

3. PolygonScan
Used for Polygon blockchain transaction verification.
Data sent: wallet address and transaction lookup requests required to detect incoming transactions.
When sent: during automatic payment verification for Polygon payments.
Terms: https://polygonscan.com/terms
Privacy: https://polygonscan.com/privacyPolicy

4. BaseScan
Used for Base network transaction verification.
Data sent: wallet address and transaction lookup requests required to detect incoming transactions.
When sent: during automatic payment verification for Base network payments.
Terms: https://basescan.org/terms
Privacy: https://basescan.org/privacyPolicy

5. TronGrid
Used for TRON blockchain transaction verification.
Data sent: wallet address and transaction lookup requests required to detect incoming TRC20 transactions.
When sent: during automatic payment verification for TRON payments.
Terms: https://developers.tron.network/page/terms-of-use
Privacy: https://developers.tron.network/page/privacy-policy

6. Stellar Horizon
Used for Stellar blockchain transaction verification.
Data sent: wallet address, transaction lookup requests, and memo checks required for payment verification.
When sent: during automatic payment verification for Stellar payments.
Terms: https://stellar.org/terms-of-service
Privacy: https://stellar.org/privacy-policy

7. XRPSCAN
Used to verify XRP payments.
Data sent: wallet address and transaction lookup requests required to detect incoming XRP payments.
When sent: during automatic payment verification for XRP payments.
Terms: https://docs.xrpscan.com/help/terms-of-service
Privacy: https://docs.xrpscan.com/help/privacy-policy

8. Solana public RPC endpoint
Used to query the Solana blockchain for payment verification.
Data sent: wallet address and transaction lookup requests via JSON-RPC.
When sent: during automatic payment verification for Solana payments.
Terms: https://solana.com/tos
Privacy: https://solana.com/privacy-policy

9. Blockfrost
Used to verify Cardano payments via the Cardano blockchain API.
Data sent: wallet address and transaction lookup requests required to detect incoming payments.
When sent: during automatic payment verification for Cardano payments.
Terms: https://blockfrost.io/terms
Privacy: https://blockfrost.io/privacy

10. NOWNodes
Used for Verge (XVG) Blockbook blockchain queries.
Data sent: wallet address and transaction lookup requests required for blockchain verification.
When sent: during automatic payment verification for Verge payments.
Terms: https://nownodes.io/assets/data/service-agreement.pdf
Privacy: https://nownodes.io/assets/data/privacy-pol.pdf

11. Tatum
Used for UTXO blockchain transaction verification for supported coins such as BTC, LTC, BCH, DOGE, and DASH.
Data sent: wallet address and transaction lookup requests required to detect incoming transactions and confirmations.
When sent: during automatic payment verification for supported UTXO coins.
Terms: https://tatum.io/terms-of-use
Privacy: https://tatum.io/privacy-policy

12. CoinGecko
Used for cryptocurrency exchange rate lookup in USD so the plugin can calculate the expected payment amount for supported coins.
Data sent: the list of supported coin identifiers required to request current USD exchange rates.
When sent: when the plugin fetches cryptocurrency rates for checkout/payment verification.
Terms: https://www.coingecko.com/en/terms
Privacy: https://www.coingecko.com/en/privacy

== Installation ==

1. Upload the plugin ZIP file to your WordPress site
2. Activate the plugin
3. Ensure WooCommerce is installed and active
4. Go to WooCommerce → Settings → Payments
5. Enable **Corexa crypto payment** and configure your wallets

== Frequently Asked Questions ==

= Does this plugin verify payments automatically? =
Yes. The plugin checks the blockchain and confirms payments automatically (using public blockchain explorer APIs / provider endpoints).

= Do I need a third-party crypto service? =
No. You use your own wallet addresses. The plugin does not act as a payment processor.

= Can I accept multiple cryptocurrencies? =
Yes. You can configure multiple coins and networks.

= Does the plugin hold customer funds? =
No. Payments go directly to your own wallets.

= Are there any fees? =
The plugin does not charge fees. Any costs, limits, or requirements depend on the API/explorer/provider used for blockchain checks.

== Changelog ==

= 1.0.0 =
* Initial release

== Screenshots ==
1. Gateway settings panel in WooCommerce admin.
2. Wallet configuration interface.
3. Advanced payment settings (QR, timer, icons).
4. Crypto selection on checkout page.
5. Thank you page with QR code and payment details.