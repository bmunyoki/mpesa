<?php

namespace Bmunyoki\Mpesa\Http;

use Bmunyoki\Mpesa\Exceptions\MpesaException;
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

        $this->assertSuccessful($response, 'request');

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

        $code = $response->json('errorCode');

        $safeCode = is_string($code)
            && preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $code)
                ? " Provider code: {$code}."
                : '';

        throw new MpesaException(
            "Daraja {$operation} failed (HTTP {$response->status()}).{$safeCode}"
        );
    }
}
