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

class B2cTest extends TestCase {
    protected function getPackageProviders($app): array {
        return [MpesaServiceProvider::class];
    }

    protected function setUp(): void {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();

        config()->set('mpesa.mpesa_env', 'sandbox');
        config()->set('mpesa.endpoint_versions.b2c', 'v3');

        config()->set('mpesa.accounts.b2c', [
            'consumer_key' => 'b2c-key',
            'consumer_secret' => 'b2c-secret',
            'shortcode' => '600000',
            'initiator_name' => 'testapi',
            'initiator_password' => 'test-password',
            'command_id' => 'BusinessPayment',
            'result_url' => 'https://callbacks.example.com/result',
            'timeout_url' => 'https://callbacks.example.com/timeout',
            'allowed_callback_hosts' => [
                'callbacks.example.com',
                'alternate.example.com',
            ],
        ]);
    }

    private function fakeDaraja(
        array $response = ['ResponseCode' => '0']
    ): void {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'b2c-token',
                'expires_in' => '3600',
            ]),

            'https://sandbox.safaricom.co.ke/mpesa/b2c/*' => Http::response(
                $response
            ),
        ]);
    }

    public function test_defaults_and_b2c_credentials(): void {
        $this->fakeDaraja();

        $response = Mpesa::using('b2c')->sendB2C(
            '254712345678',
            500,
            'Customer payout',
            'PAYOUT_10001'
        );

        $this->assertSame('0', $response['ResponseCode']);

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/oauth/v1/generate')
            && $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode('b2c-key:b2c-secret')
            )
        );

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/mpesa/b2c/v3/paymentrequest')
            && $request['ResultURL'] === 'https://callbacks.example.com/result'
            && $request['QueueTimeOutURL'] === 'https://callbacks.example.com/timeout'
            && $request['OriginatorConversationID'] === 'PAYOUT_10001'
            && $request['PartyA'] === '600000'
            && $request['PartyB'] === '254712345678'
            && $request['Amount'] === 500
            && is_string($request['SecurityCredential'])
            && $request['SecurityCredential'] !== 'test-password'
        );

        Http::assertSentCount(2);
    }

    public function test_result_override_preserves_timeout_default(): void {
        $this->fakeDaraja();

        Mpesa::using('b2c')->sendB2C(
            '254712345678',
            500,
            'Customer payout',
            'PAYOUT_10002',
            resultUrl: 'https://alternate.example.com/result'
        );

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/mpesa/b2c/')
            && $request['ResultURL'] === 'https://alternate.example.com/result'
            && $request['QueueTimeOutURL'] === 'https://callbacks.example.com/timeout'
        );
    }

    public function test_timeout_override_preserves_result_default(): void {
        $this->fakeDaraja();

        Mpesa::using('b2c')->sendB2C(
            '254712345678',
            500,
            'Customer payout',
            'PAYOUT_10003',
            timeoutUrl: 'https://alternate.example.com/timeout'
        );

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/mpesa/b2c/')
            && $request['ResultURL'] === 'https://callbacks.example.com/result'
            && $request['QueueTimeOutURL'] === 'https://alternate.example.com/timeout'
        );
    }

    public function test_untrusted_override_is_rejected_before_http(): void {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            Mpesa::using('b2c')->sendB2C(
                '254712345678',
                500,
                'Customer payout',
                'PAYOUT_10004',
                resultUrl: 'https://attacker.example.net/receive'
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_override_is_not_replaced_by_default(): void {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            Mpesa::using('b2c')->sendB2C(
                '254712345678',
                500,
                'Customer payout',
                'PAYOUT_10005',
                resultUrl: ''
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_nonzero_acknowledgement_throws_without_retry(): void {
        $this->fakeDaraja(['ResponseCode' => '1005']);

        try {
            Mpesa::using('b2c')->sendB2C(
                '254712345678',
                500,
                'Customer payout',
                'PAYOUT_10006'
            );

            $this->fail('Expected B2C acknowledgement exception.');
        } catch (MpesaException $exception) {
            $this->assertStringContainsString(
                'Reconcile',
                $exception->getMessage()
            );
        }

        Http::assertSentCount(2);
    }

    public function test_v1_endpoint_remains_available(): void {
        config()->set('mpesa.endpoint_versions.b2c', 'v1');

        $this->fakeDaraja();

        Mpesa::using('b2c')->sendB2C(
            '254712345678',
            500,
            'Customer payout',
            'PAYOUT_10007',
            occasion: 'Refund'
        );

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/mpesa/b2c/v1/paymentrequest')
            && ! array_key_exists(
                'OriginatorConversationID',
                $request->data()
            )
            && $request['Occasion'] === 'Refund'
        );
    }
}
