# Commerce ArionPay

Crypto payments (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) for Drupal Commerce through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

For Drupal 10.3 / 11 with Commerce 2.40+ or 3.x, PHP 8.1+. Tested on Drupal 11 with Commerce 3.3.14 and PHP 8.3. The tests covered guest checkout, coming back before and after paying, a lost webhook, paying later, payment tolerance, Payment Radar, late payments, webhook checks, currencies and the settings page.

## Install

With Composer (once the module is published on drupal.org): `composer require drupal/commerce_arionpay`, then enable **Commerce ArionPay**.

From the zip: unzip `commerce_arionpay-1.0.0.zip` into `web/modules/contrib/` (it brings its own copy of the ArionPay PHP library), then enable **Commerce ArionPay** in *Extend*.

## Set up

*Commerce > Configuration > Payment gateways > Add payment gateway*, choose **ArionPay (crypto)**:

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |
| Mode | **Test** makes test invoices (no real money); **Live** for real payments | |

The secrets are never shown again on the settings page; leave the fields empty to keep them. Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (the address is shown on the gateway page after you save).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

Keep secrets out of exported configuration if you use config sync: set them in `settings.php` instead, for example:

```php
$config['commerce_payment.commerce_payment_gateway.arionpay']['configuration']['api_secret'] = getenv('ARIONPAY_API_SECRET');
$config['commerce_payment.commerce_payment_gateway.arionpay']['configuration']['webhook_secret'] = getenv('ARIONPAY_WEBHOOK_SECRET');
```

## How orders change

- At **Review**, the customer presses *Pay and complete purchase* and goes to the ArionPay page. The order stays a draft (locked) until it is paid.
- When ArionPay confirms the full payment, the module records one **completed** payment, whose remote id is the ArionPay invoice id. Commerce then places the order.
- When the customer comes back, the module asks ArionPay directly:
  - if the invoice is paid, the order is completed (this also covers a lost webhook);
  - if it isn't paid yet, the customer goes back to the ArionPay page, which shows the payment's progress. An order is never placed unpaid.
- A customer who left can come back and pay; the same ArionPay page opens while it is still open.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide. If you accept it, the payment is recorded here too.
- A late payment is still recorded.
- A test invoice is never recorded while the gateway is in Live mode, nor a real one in Test mode.
- A second paid invoice for an already paid order is still recorded (the money did arrive), with a warning in the log: refund it from your wallet.

## Good to know

- ArionPay takes USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR. For other currencies the gateway is not offered.
- Refunds are made from your own wallet; then record the refund on the order by hand.
- The module sends ArionPay the order number, amount, currency and store name. It does not send the customer's name or email.
- Details are in *Reports > Recent log messages* (type `commerce_arionpay`). Keys are never logged.
