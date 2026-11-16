## Upgrading from 5.0.0 to 5.0.1

Version 5.0.1 expands B2C support.

### B2C Configuration

Add the `b2c` profile under `accounts` in `config/mpesa.php`.

Configure the following environment variables:

```dotenv
MPESA_B2C_CONSUMER_KEY=
MPESA_B2C_CONSUMER_SECRET=
MPESA_B2C_SHORTCODE=
MPESA_B2C_INITIATOR_NAME=
MPESA_B2C_INITIATOR_PASSWORD=
MPESA_B2C_COMMAND_ID=BusinessPayment
MPESA_B2C_RESULT_URL=
MPESA_B2C_TIMEOUT_URL=
MPESA_B2C_ALLOWED_CALLBACK_HOSTS=
```

Do not overwrite unrelated published configuration values.

### Recommended B2C Usage

Use:

```php
Mpesa::using('b2c')->sendB2C(
    phone: '254712345678',
    amount: 500,
    remarks: 'Customer payout',
    originatorConversationId: $payout->originator_conversation_id
);
```

### Dynamic Callback URLs

The following optional arguments are now supported:

```php
resultUrl: 'https://example.com/result',
timeoutUrl: 'https://example.com/timeout'
```

Each argument can be supplied independently.

When omitted, the package uses the configured value.

### Legacy B2C Method

The lower-level `b2c()` method retains its original nine positional arguments, with additional optional arguments for v3 and occasion.

When the endpoint is configured for v3, OriginatorConversationID is required.

### Endpoint Version

B2C v3 is the default.

Existing integrations that require v1 should explicitly configure `b2c` as `v1` under `endpoint_versions`.

### Response Handling

A successful acknowledgement is not final payout confirmation.

Applications must process the final B2C result, enforce idempotency and reconcile unknown outcomes.

### Production Deployment

After updating the published configuration:

```bash
php artisan optimize:clear
php artisan config:cache
```

Restart queue workers and long-running application processes as appropriate.

Test the B2C workflow in sandbox before using live credentials.

# Upgrading from v4.1 to v5.0

Version 5.0 is a major release. Test every integration in the Daraja sandbox before updating a production application.

## Requirements

PHP 8.2 or higher and a supported Laravel version.

## Response changes

All successful API HTTP requests return associative arrays.

Version 4:

```php
$response = Mpesa::stkRequest(...);
$checkoutId = $response->CheckoutRequestID;
```

Version 5:

```php
$response = Mpesa::stkRequest(...);
$checkoutId = $response['CheckoutRequestID'] ?? null;
```

## Exceptions

The SDK now throws exceptions for invalid input, configuration problems, transport failures and unsuccessful HTTP responses instead of returning false.

## STK Push

The old `stkRequest()` method is retained.

New applications should use `stkPush()`.

## STK Query

Use the new public `stkQuery()` method.

## Transaction Status

`statusRequest()` now requires the transaction ID.

The hardcoded sample transaction ID has been removed.

## Reversals

`reverseTransaction()` now uses the receiver argument as the actual ReceiverParty.

The eighth argument represents ReceiverIdentifierType, defaulting to shortcode type 4.

The obsolete ninth `$testMsisdn` argument has been removed.

## Dependency Injection

Direct construction with `new Mpesa()` without arguments is no longer supported.

Use the Facade or Laravel's service container.

## Credential Profiles

Use:

```php
Mpesa::using('paybill')->stkPush(...);
Mpesa::using('till')->stkPush(...);
```

Each named profile must have its own valid consumer key and secret.

## Configuration

Republish the configuration and review the new values.

The original environment variable names remain available.

## Callback Routes

The demo route has been removed.

The package does not create application callbacks automatically.

Create appropriate authenticated or secured webhook controllers in the consuming application.

## Endpoint Versions

STK query defaults to v1.

C2B defaults to v2.

B2C defaults to v1, with v3 selectable in configuration.

Confirm support using the actual activated Daraja product.

## Certificates

The sandbox and production certificate files remain unchanged.

Verify that both certificates remain appropriate for the current Daraja environment.

## Production Upgrade

Before deploying:

1. Run automated tests.
2. Test STK Push and STK Query in sandbox.
3. Verify C2B registration and simulation.
4. Validate B2C and B2B request payloads.
5. Test account balance and transaction status.
6. Test reversal handling with an authorized test transaction.
7. Verify callback correlation and idempotency.
8. Verify production credentials and permissions.
9. Update application code to use associative response arrays.
10. Deploy only after successful sandbox verification.
