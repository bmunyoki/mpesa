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
