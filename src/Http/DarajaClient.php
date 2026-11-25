<?php

namespace Bmunyoki\Mpesa\Http;

use Bmunyoki\Mpesa\Exceptions\MpesaException;
use Bmunyoki\Mpesa\Exceptions\DarajaHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DarajaClient {
    public function environment(): string {
        $environment = (string) config('mpesa.mpesa_env', 'sandbox');

        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('MPESA_ENV must be sandbox or production.');
        }

        return $environment;
    }

    public function getAccessToken(string $profile = 'default'): string {
        [$key, $secret] = $this->credentials($profile);

        $cacheKey = 'bmunyoki.mpesa.token.'.hash(
            'sha256',
            $this->environment().':'.$profile.':'.$key.':'.$secret
        );

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->withBasicAuth($key, $secret)
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->get($this->baseUrl().'/oauth/v1/generate', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            throw new MpesaException(
                'Unable to connect to Daraja authentication.',
                0,
                $exception
            );
        }

        $this->assertSuccessful($response, 'authentication');

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new MpesaException('Daraja authentication returned no access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put(
            $cacheKey,
            $token,
            max(1, min(3000, $expiresIn - 60))
        );

        return $token;
    }

    public function post(string $path, array $payload, string $profile = 'default'): array {
        // Does automatically retry money-moving requests.
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($this->getAccessToken($profile))
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->post(
                    $this->baseUrl().'/'.ltrim($path, '/'),
                    $payload
                );
        } catch (ConnectionException $exception) {
            throw new MpesaException(
                'Unable to connect to the Daraja API. Payment outcome may be unknown.',
                0,
                $exception
            );
        }

        $operation = match (true) {
            str_starts_with(ltrim($path, '/'), 'mpesa/b2c/') => 'b2c',
            str_starts_with(ltrim($path, '/'), 'mpesa/reversal/') => 'reversal',
            default => 'request',
        };

        $this->assertSuccessful($response, $operation);

        $data = $response->json();

        if (! is_array($data)) {
            throw new MpesaException('Daraja returned an invalid JSON response.');
        }

        return $data;
    }

    private function credentials(string $profile): array {
        if ($profile === 'default') {
            $key = config('mpesa.consumer_key');
            $secret = config('mpesa.consumer_secret');
        } else {
            if (! preg_match('/^[a-z0-9_-]+$/i', $profile)) {
                throw new InvalidArgumentException('Invalid M-Pesa credential profile.');
            }

            $key = config("mpesa.accounts.{$profile}.consumer_key");
            $secret = config("mpesa.accounts.{$profile}.consumer_secret");
        }

        if (
            ! is_string($key) || trim($key) === ''
            || ! is_string($secret) || trim($secret) === ''
        ) {
            throw new MpesaException(
                "Daraja credentials are not configured for profile: {$profile}."
            );
        }

        return [$key, $secret];
    }

    private function safeProviderErrorMessage(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Only retain recognized provider validation messages.
        // Do not include arbitrary values echoed from the request.
        if (preg_match(
            '/\A(?:Bad Request\s*-\s*)?Invalid\s+([A-Za-z][A-Za-z0-9_]{0,63})\.?\z/i',
            $value,
            $matches
        )) {
            $allowedFields = [
                'Amount',
                'TransactionID',
                'ReceiverParty',
                'RecieverIdentifierType',
                'ReceiverIdentifierType',
                'Initiator',
                'InitiatorName',
                'SecurityCredential',
                'CommandID',
                'ResultURL',
                'QueueTimeOutURL',
                'Remarks',
                'Occasion',
                'BusinessShortCode',
                'ShortCode',
                'PhoneNumber',
            ];

            foreach ($allowedFields as $field) {
                if (strcasecmp($field, $matches[1]) === 0) {
                    return 'Invalid ' . $field;
                }
            }
        }

        $allowedMessages = [
            'Bad Request',
            'Bad Request - Invalid Initiator Information',
            'Invalid Initiator Information',
            'Unauthorized',
            'Forbidden',
            'Internal Server Error',
            'Service Unavailable',
        ];

        if (in_array($value, $allowedMessages, true)) {
            return $value;
        }

        return 'Unclassified provider error description.';
    }

    private function baseUrl(): string {
        return $this->environment() === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function connectTimeout(): int {
        return max(1, (int) config('mpesa.http.connect_timeout', 5));
    }

    private function timeout(): int {
        return max(1, (int) config('mpesa.http.timeout', 20));
    }

    
    private function assertSuccessful(Response $response, string $operation): void {
        if ($response->successful()) {
            return;
        }

        $providerCode = $response->json('errorCode');
        $safeProviderCode = null;

        if (is_string($providerCode) || is_int($providerCode)) {
            $value = (string) $providerCode;

            if (preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $value)) {
                $safeProviderCode = $value;
            }
        }

        $requestId = $response->header('X-Request-ID');
        $safeRequestId = null;

        if (
            is_string($requestId)
            && preg_match('/\A[a-zA-Z0-9._-]{1,128}\z/', $requestId)
        ) {
            $safeRequestId = $requestId;
        }

        throw new DarajaHttpException(
            operation: $operation,
            httpStatus: $response->status(),
            providerErrorCode: $safeProviderCode,
            providerRequestId: $safeRequestId,
            providerErrorMessage: $this->safeProviderErrorMessage(
                $response->json('errorMessage')
            )
        );
    }


    private function safeProviderIdentifier(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        if (!preg_match(
            '/\A[a-zA-Z0-9._-]{1,150}\z/',
            $value
        )) {
            return null;
        }

        return $value;
    }
}
