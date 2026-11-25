# Changelog

## [5.0.1]

### Added

- Dedicated B2C credential profile.
- High-level `sendB2C()` method.
- Automatic initiator credential encryption for B2C.
- Configurable B2C v1 and v3 endpoints.
- Merchant-generated OriginatorConversationID for v3.
- Request-level B2C result URL override.
- Request-level B2C timeout URL override.
- Optional callback hostname allowlisting.
- Configurable B2C command ID and occasion.
- B2C unit tests.
- Expanded B2C documentation.

### Changed

- B2C v3 is the default for new integrations.
- B2C response acknowledgement is checked before returning.
- Callback overrides use configured defaults only when omitted.

### Security

- Missing or invalid initiator credentials are rejected.
- Callback URLs must pass HTTPS validation.
- An enabled callback allowlist restricts allowed destinations.
- No automatic retry is performed on B2C payment requests.
- Application-level payout reconciliation remains mandatory.

## [5.0.0]

### Changed

- Modernized the Laravel package architecture.
- Introduced the Laravel HTTP client.
- Added OAuth token caching and credential profiles.
- Improved validation and exception handling.
- Standardized API response types.
- Added automated testing infrastructure.
- Removed the demo route.
