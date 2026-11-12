<?php

namespace Bmunyoki\Mpesa\Tests;

use Bmunyoki\Mpesa\Exceptions\MpesaException;
use Bmunyoki\Mpesa\Facades\Mpesa;
use Bmunyoki\Mpesa\MpesaServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;

class MpesaTest extends TestCase {
    protected function getPackageProviders($app): array {
        return [MpesaServiceProvider::class];
    }

    protected function setUp(): void {
        parent::setUp();

        config()->set('mpesa.mpesa_env', 'sandbox');
        config()->set('mpesa.consumer_key', 'test-key');
        config()->set('mpesa.consumer_secret', 'test-secret');
        config()->set('mpesa.paybill', '174379');
        config()->set('mpesa.passkey', 'test-passkey');
        config()->set(
            'mpesa.lnmo_callback_url',
            'https://example.com/webhooks/stk'
        );

        Cache::flush();
    }

    private function fakeDaraja(
        array $apiResponse = ['ResponseCode' => '0']
    ): void {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),

            'https://sandbox.safaricom.co.ke/*' => Http::response($apiResponse),
        ]);
    }

    public function test_stk_push_sends_paybill_payload_and_returns_array(): void {
        $this->fakeDaraja([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_test',
        ]);

        $result = Mpesa::stkPush(
            '254712345678',
            100,
            'INV123'
        );

        $this->assertSame(
            'ws_CO_test',
            $result['CheckoutRequestID']
        );

        Http::assertSent(function (Request $request): bool {
            return str_contains(
                $request->url(),
                '/mpesa/stkpush/v1/processrequest'
            )
                && $request['BusinessShortCode'] === '174379'
                && $request['PartyB'] === '174379'
                && $request['TransactionType'] === 'CustomerPayBillOnline'
                && $request['Amount'] === 100
                && $request['PhoneNumber'] === '254712345678'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer test-token'
                );
        });
    }

    public function test_token_is_cached_across_requests(): void {
        $this->fakeDaraja();

        Mpesa::stkPush('254712345678', 100, 'INV123');
        Mpesa::stkPush('254712345678', 100, 'INV124');

        // One OAuth request and two payment requests.
        Http::assertSentCount(3);
    }

    public function test_till_profile_uses_till_credentials_and_store_shortcode(): void {
        config()->set('mpesa.accounts.till.consumer_key', 'till-key');
        config()->set('mpesa.accounts.till.consumer_secret', 'till-secret');
        config()->set('mpesa.accounts.till.shortcode', '7085771');
        config()->set('mpesa.accounts.till.party_b', '123456');

        $this->fakeDaraja();

        Mpesa::using('till')->stkPush(
            '254712345678',
            100,
            'INV123',
            transactionType: 'CustomerBuyGoodsOnline'
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), 'oauth/v1/generate')
            && $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode('till-key:till-secret')
            )
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/stkpush/v1/processrequest')
            && $request['BusinessShortCode'] === '7085771'
            && $request['PartyB'] === '123456'
        );
    }

    public function test_stk_query_is_public(): void {
        $this->fakeDaraja(['ResultCode' => '0']);

        $result = Mpesa::stkQuery('ws_CO_test');

        $this->assertSame('0', $result['ResultCode']);

        Http::assertSent(fn (Request $request): bool =>
            str_contains(
                $request->url(),
                '/mpesa/stkpushquery/v1/query'
            )
            && $request['CheckoutRequestID'] === 'ws_CO_test'
        );
    }

    public function test_rejects_invalid_phone_before_network_request(): void {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            Mpesa::stkPush('0712345678', 100, 'INV123');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rejects_production_c2b_simulation(): void {
        config()->set('mpesa.mpesa_env', 'production');

        $this->expectException(MpesaException::class);

        Mpesa::simulateC2B(
            '600000',
            100,
            '254712345678',
            'INV123'
        );
    }

    public function test_http_failure_throws_sanitized_exception(): void {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/*' => Http::response([
                'access_token' => 'test-token',
            ]),

            'https://sandbox.safaricom.co.ke/*' => Http::response([
                'errorCode' => '400.001',
                'errorMessage' => 'sensitive-data',
            ], 400),
        ]);

        try {
            Mpesa::stkPush('254712345678', 100, 'INV123');

            $this->fail('Expected an exception.');
        } catch (MpesaException $exception) {
            $this->assertStringContainsString(
                'HTTP 400',
                $exception->getMessage()
            );

            $this->assertStringNotContainsString(
                'sensitive-data',
                $exception->getMessage()
            );
        }
    }
}
