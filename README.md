# bmunyoki/mpesa

[![Latest Stable Version](https://poser.pugx.org/bmunyoki/mpesa/v/stable)](https://packagist.org/packages/bmunyoki/mpesa)
[![Total Downloads](https://poser.pugx.org/bmunyoki/mpesa/downloads)](https://packagist.org/packages/bmunyoki/mpesa)
[![License](https://poser.pugx.org/bmunyoki/mpesa/license)](https://packagist.org/packages/bmunyoki/mpesa)

A Laravel integration package for the Safaricom M-Pesa Daraja API.

The package provides a convenient interface for M-Pesa payment collection, disbursements, transaction queries and related operations.

## Features

- STK Push (Paybill and Buy Goods)
- STK Push status queries
- C2B URL registration and simulation
- B2C payouts (v1 and v3)
- B2B payments
- Transaction status queries
- Account balance queries
- Transaction reversals
- Pull transaction registration and queries
- Multiple Daraja credential profiles
- OAuth token caching
- Laravel HTTP client
- Exception handling and input validation
- Configurable B2C callback URLs
- Optional callback hostname allowlisting

## Requirements

- PHP 8.2+
- A supported Laravel installation
- PHP OpenSSL extension
- Safaricom Daraja API credentials

## Installation

Once v5.0.1 is published, install using Composer:

```bash
composer require bmunyoki/mpesa:^5.0.1
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=mpesa-config
```

This creates:

```text
config/mpesa.php
```

Set the appropriate environment:

```dotenv
MPESA_ENV=sandbox
```

Use `production` when your live Daraja credentials and products are activated.

## Configuration

The package supports separate profiles for Paybill, Till and B2C.

Example:

```dotenv
MPESA_ENV=sandbox

# Default Daraja credentials
MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=

# Paybill
MPESA_PAYBILL_CONSUMER_KEY=
MPESA_PAYBILL_CONSUMER_SECRET=
MPESA_PAYBILL=
MPESA_PAYBILL_PASSKEY=

# Buy Goods
MPESA_TILL_CONSUMER_KEY=
MPESA_TILL_CONSUMER_SECRET=
MPESA_STORE_NUMBER=
MPESA_TILL=
MPESA_TILL_PASSKEY=

# B2C
MPESA_B2C_CONSUMER_KEY=
MPESA_B2C_CONSUMER_SECRET=
MPESA_B2C_SHORTCODE=
MPESA_B2C_INITIATOR_NAME=
MPESA_B2C_INITIATOR_PASSWORD=
MPESA_B2C_COMMAND_ID=BusinessPayment
MPESA_B2C_RESULT_URL=https://example.com/api/mpesa/b2c/result
MPESA_B2C_TIMEOUT_URL=https://example.com/api/mpesa/b2c/timeout
MPESA_B2C_ALLOWED_CALLBACK_HOSTS=example.com
```

Only configure the products you intend to use.

Never commit live credentials to Git.

## B2C — Business to Customer

B2C transfers money from a Safaricom-enabled business shortcode to an M-Pesa customer.

Supported commands:

- `BusinessPayment`
- `SalaryPayment`
- `PromotionPayment`

The merchant must have an activated B2C product and authorized initiator credentials.

### B2C credentials

The B2C profile needs:

- Consumer key and secret
- B2C shortcode
- Initiator username
- Initiator password
- Result callback URL
- Timeout callback URL

The initiator password is RSA-encrypted using the appropriate bundled Safaricom public certificate.

The package includes certificates in `src/cert/`.

### Default B2C payout

```php
use Bmunyoki\Mpesa\Facades\Mpesa;

$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Customer payout',
    originatorConversationId: $payout->originator_conversation_id
);
```

The example assumes `$payout` is an existing, persisted payout record.

The package uses the result and timeout URLs configured in `config/mpesa.php`.

### Override the result URL

```php
$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Customer payout',
    originatorConversationId: $payout->originator_conversation_id,
    resultUrl: 'https://example.com/api/custom/result'
);
```

The timeout URL still uses the configured default.

### Override the timeout URL

```php
$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Customer payout',
    originatorConversationId: $payout->originator_conversation_id,
    timeoutUrl: 'https://example.com/api/custom/timeout'
);
```

The result URL still uses the configured default.

### Override both URLs

```php
$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Customer payout',
    originatorConversationId: $payout->originator_conversation_id,
    resultUrl: 'https://example.com/api/custom/result',
    timeoutUrl: 'https://example.com/api/custom/timeout'
);
```

Each override is independent.

Passing `null` selects the configured default. Passing an empty string is an error.

If neither a configured value nor an override is available for a required URL, the package throws an exception.

### Salary payments

```php
$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 15000,
    remarks: 'Monthly salary',
    originatorConversationId: $payout->originator_conversation_id,
    commandId: 'SalaryPayment'
);
```

### Promotional payouts

```php
$response = Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Promotional reward',
    originatorConversationId: $payout->originator_conversation_id,
    commandId: 'PromotionPayment',
    occasion: 'Customer reward'
);
```

### B2C v3 correlation ID

The `originatorConversationId` must be unique for each payout.

Generate it in the consuming application and persist it before contacting Safaricom.

For example:

```php
use Illuminate\Support\Str;

$originatorId = (string) Str::uuid();
```

Store the identifier with the recipient, amount and internal payout reference.

Do not generate a different identifier simply because a request timed out.

### B2C API response

An accepted request typically includes fields such as:

```json
{
    "ConversationID": "AG_example",
    "OriginatorConversationID": "PAYOUT_10001",
    "ResponseCode": "0",
    "ResponseDescription": "Accept the service request successfully"
}
```

The values above are illustrative.

**ResponseCode 0 indicates request acceptance, not completed payment.**

Safaricom processes B2C transactions asynchronously.

Store the response identifiers and wait for the final transaction result.

### B2C result callback

Safaricom sends the final outcome to `ResultURL`.

The response commonly includes:

- `Result.ResultCode`
- `Result.ResultDesc`
- `Result.OriginatorConversationID`
- `Result.ConversationID`
- `Result.TransactionID`
- `Result.ResultParameters`

The consuming application must correlate the callback with its stored payout and independently validate the expected recipient, amount and transaction.

Do not grant funds or mark a payout completed solely from an unverified incoming HTTP request.

### B2C timeout callback

Safaricom may send timeout notifications to `QueueTimeOutURL`.

A timeout does not establish that the transaction failed.

Mark the payout as unresolved and reconcile its status before initiating another disbursement.

### Callback hostname allowlist

You can restrict B2C callbacks to trusted hostnames:

```dotenv
MPESA_B2C_ALLOWED_CALLBACK_HOSTS=example.com,callbacks.example.com
```

Both configured and overridden URLs must use HTTPS.

If the allowlist is configured, each URL must use one of the specified hostnames.

Avoid passing user-controlled URLs directly to the SDK.

### Legacy B2C method

The lower-level `b2c()` method remains available for existing integrations:

```php
$response = Mpesa::using('b2c')->b2c(
    paybillOrTill: '600000',
    initiatorUsername: 'testapi',
    initiatorPassword: $password,
    amount: 500,
    phone: '254712345678',
    commandId: 'BusinessPayment',
    remarks: 'Customer payout',
    b2cTimeoutUrl: 'https://example.com/b2c/timeout',
    b2cResultUrl: 'https://example.com/b2c/result',
    originatorConversationId: 'PAYOUT_10001'
);
```

The high-level `sendB2C()` method is recommended for new integrations because sensitive credentials are read from the configured profile.

### B2C endpoint versions

The default is B2C v3.

To use the older v1 endpoint, configure:

```php
'endpoint_versions' => [
    'stk_query' => 'v1',
    'c2b' => 'v2',
    'b2c' => 'v1',
],
```

B2C v1 does not use the merchant-generated v3 conversation ID in the same way.

The application must still persist the returned request identifiers and reconcile its payouts.

## STK Push (Paybill)

```php
$response = Mpesa::using('paybill')->stkPush(
    phone: '254712345678',
    amount: 100,
    reference: 'INV123'
);
```

## STK Push (Buy Goods)

```php
$response = Mpesa::using('till')->stkPush(
    phone: '254712345678',
    amount: 100,
    reference: 'INV123',
    transactionType: 'CustomerBuyGoodsOnline'
);
```

## STK Status Query

```php
$response = Mpesa::using('paybill')->stkQuery(
    'ws_CO_checkout_request_id'
);
```

## C2B URL Registration

```php
$response = Mpesa::c2bRegisterUrls(
    '600000',
    'https://example.com/c2b/confirmation',
    'https://example.com/c2b/validation'
);
```

## C2B Simulation

Sandbox only:

```php
$response = Mpesa::simulateC2B(
    '600000',
    100,
    '254712345678',
    'INV123'
);
```

## Other APIs

The package also provides:

- `b2b()` for business-to-business transfers
- `checkBalance()` for account balance requests
- `statusRequest()` for transaction status queries
- `reverseTransaction()` for supported reversals
- `pullRegisterUrl()` for Pull API registration
- `pullAPI()` for transaction retrieval

These methods remain available through the package facade.

## Error Handling

```php
use Bmunyoki\Mpesa\Exceptions\MpesaException;

try {
    $response = Mpesa::using('b2c')->sendB2C(
        phone: '254712345678',
        amount: 500,
        remarks: 'Customer payout',
        originatorConversationId: $payout->originator_conversation_id
    );
} catch (\InvalidArgumentException $exception) {
    // Invalid configuration or request input.
} catch (MpesaException $exception) {
    // Daraja or transport failure.
    // Reconcile the outcome before retrying.
}
```

A network failure can leave the payout outcome unknown.

## Security Responsibilities

The package handles outbound API authentication and requests.

It does not automatically register webhook endpoints, maintain payment tables, authenticate incoming callbacks or mark payments as successful.

Consuming applications are responsible for:

1. Persisting payment requests before sending.
2. Maintaining unique payout identifiers and database constraints.
3. Processing callbacks idempotently.
4. Comparing expected and returned transaction details.
5. Reconciling uncertain payout outcomes.
6. Protecting internal payout initiation endpoints.
7. Validating webhook authenticity using controls appropriate to the integration.
8. Keeping initiator passwords and access tokens secret.
9. Restricting who can initiate or approve payouts.

Never assume a callback is authentic solely because it reached the designated URL.

## Testing

```bash
composer update
composer test
```

Tests mock Daraja HTTP calls and must not initiate real disbursements.

## Upgrading

See [UPGRADE.md](UPGRADE.md).

## Contributing

Contributions and pull requests are welcome.

Repository:
https://github.com/bmunyoki/mpesa

For security vulnerabilities, contact:

munyokibenjamin@gmail.com

## License

MIT. See [LICENSE.md](LICENSE.md).