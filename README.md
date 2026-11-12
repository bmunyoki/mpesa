# bmunyoki/mpesa

A Laravel integration package for Safaricom's M-Pesa Daraja API.

Version 5.0 modernizes the original SDK with PHP 8.2+, Laravel's HTTP client, OAuth token caching, credential profiles, input validation and exception handling.

## Features

- STK Push (Paybill and Buy Goods)
- STK Push status query
- C2B URL registration and sandbox simulation
- B2C payments
- B2B payments
- Account balance queries
- Transaction status queries
- Transaction reversals
- Pull transaction registration and queries
- Multiple Daraja credential profiles

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12 or 13
- OpenSSL extension
- Safaricom Daraja application credentials

## Installation

Install with Composer:

```bash
composer require bmunyoki/mpesa:^5.0
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=mpesa-config
```

## Configuration

Add the following to the Laravel application's `.env`:

```dotenv
MPESA_ENV=sandbox
MPESA_CONSUMER_KEY=your-consumer-key
MPESA_CONSUMER_SECRET=your-consumer-secret
MPESA_PAYBILL=174379
MPESA_PASSKEY=your-passkey
MPESA_LNMO_CALLBACK=https://example.com/webhooks/stk
```

Supported environments:

- `sandbox`
- `production`

Never commit live credentials into source control.

## STK Push (Paybill)

```php
use Bmunyoki\Mpesa\Facades\Mpesa;

$response = Mpesa::stkPush(
    phone: '254712345678',
    amount: 100,
    reference: 'INV123'
);

$checkoutRequestId = $response['CheckoutRequestID'] ?? null;
```

An accepted STK request is not proof of payment.

Persist the CheckoutRequestID with an internal pending payment record and reconcile the asynchronous outcome before granting goods or services.

## STK Status Query

```php
$response = Mpesa::stkQuery('ws_CO_your_checkout_request_id');
```

Use this method to investigate pending payments where the callback has not arrived.

## STK Push (Till / Buy Goods)

Configure the Till credentials:

```dotenv
MPESA_TILL_CONSUMER_KEY=your-key
MPESA_TILL_CONSUMER_SECRET=your-secret
MPESA_STORE_NUMBER=your-store-number
MPESA_TILL=your-till-number
MPESA_TILL_PASSKEY=your-passkey
```

Then:

```php
$response = Mpesa::using('till')->stkPush(
    phone: '254712345678',
    amount: 100,
    reference: 'INV123',
    transactionType: 'CustomerBuyGoodsOnline'
);
```

The BusinessShortCode is the store number, while PartyB is the Till number.

## C2B URL Registration

```php
$response = Mpesa::c2bRegisterUrls(
    '600000',
    'https://example.com/webhooks/c2b/confirmation',
    'https://example.com/webhooks/c2b/validation'
);
```

## Simulate C2B

Sandbox only:

```php
$response = Mpesa::simulateC2B(
    '600000',
    100,
    '254712345678',
    'INV123'
);
```

## B2C Payment

```php
$response = Mpesa::b2c(
    '600000',
    'api-initiator',
    $initiatorPassword,
    100,
    '254712345678',
    'BusinessPayment',
    'Payout',
    'https://example.com/webhooks/b2c/timeout',
    'https://example.com/webhooks/b2c/result'
);
```

The SDK encrypts the initiator password using the appropriate bundled public certificate.

Retrieve the initiator password from a secure secret source.

## Error Handling

```php
use Bmunyoki\Mpesa\Exceptions\MpesaException;

try {
    $response = Mpesa::stkPush(
        '254712345678',
        100,
        'INV123'
    );
} catch (MpesaException $exception) {
    // Handle an API or transport failure.
} catch (\InvalidArgumentException $exception) {
    // Handle an invalid request.
}
```

A transport failure may leave a payment outcome unknown. Reconcile before trying again.

## Security

The package does not automatically register webhook routes or change payment statuses.

Consuming applications must:

- Validate incoming callback payloads.
- Correlate callbacks with pending payment records.
- Compare expected and reported amounts.
- Enforce idempotent payment processing.
- Reconcile final payment status independently when needed.
- Protect credentials and personally identifiable information.
- Restrict payment initiation and reversal permissions.
- Avoid logging raw credentials, tokens or sensitive payment payloads.

Daraja callbacks must not be assumed to carry a verifiable cryptographic signature.

## Testing

```bash
composer update
composer test
```

## Upgrading from v4.1

See [UPGRADE.md](UPGRADE.md).

Version 5.0 changes response types and some method signatures.

## Contributing

Issues and pull requests are welcome on GitHub.

Security vulnerabilities should be reported privately to:

munyokibenjamin@gmail.com

## License

MIT License. See [LICENSE.md](LICENSE.md).
