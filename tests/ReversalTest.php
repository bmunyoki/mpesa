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

class ReversalTest extends TestCase {
    protected function getPackageProviders($app): array {
        return [MpesaServiceProvider::class];
    }

    protected function setUp(): void {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();

        config()->set('mpesa.mpesa_env', 'sandbox');
        config()->set('mpesa.consumer_key', 'default-key');
        config()->set('mpesa.consumer_secret', 'default-secret');
        config()->set('mpesa.accounts.reversal', [
            'consumer_key' => 'reversal-key',
            'consumer_secret' => 'reversal-secret',
            'shortcode' => '600000',
            'initiator_name' => 'testapi',
            'initiator_password' => 'test-password',
            'receiver_identifier_type' => 4,
            'remarks' => 'Test reversal',
            'occasion' => 'Cancelled order',
            'result_url' => 'https://callbacks.example.com/reversal/result',
            'timeout_url' => 'https://callbacks.example.com/reversal/timeout',
            'allowed_callback_hosts' => [
                'callbacks.example.com',
                'alternate.example.com',
            ],
        ]);
    }

    private function fakeDaraja(array $response = ['ResponseCode' => '0']): void {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'reversal-test-token',
                'expires_in' => 3600,
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/reversal/v1/request' => Http::response($response),
        ]);
    }

    public function test_reversal_uses_profile_and_default_urls(): void {
        $this->fakeDaraja();

        $response = Mpesa::using('reversal')->sendReversal(
            transactionId: 'LGR019G3J2',
            amount: 500
        );

        $this->assertSame('0', $response['ResponseCode']);

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/oauth/v1/generate')
            && $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode('reversal-key:reversal-secret')
            )
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/mpesa/reversal/v1/request')
            && $request->hasHeader('Authorization', 'Bearer reversal-test-token')
            && $request['CommandID'] === 'TransactionReversal'
            && $request['TransactionID'] === 'LGR019G3J2'
            && $request['Amount'] === 500
            && $request['ReceiverParty'] === '600000'
            && $request['RecieverIdentifierType'] === '4'
            && $request['ResultURL'] === 'https://callbacks.example.com/reversal/result'
            && $request['QueueTimeOutURL'] === 'https://callbacks.example.com/reversal/timeout'
            && $request['Remarks'] === 'Test reversal'
            && $request['Occasion'] === 'Cancelled order'
            && is_string($request['SecurityCredential'])
            && $request['SecurityCredential'] !== 'test-password'
        );

        Http::assertSentCount(2);
    }

    public function test_result_url_can_be_overridden_independently(): void {
        $this->fakeDaraja();

        Mpesa::using('reversal')->sendReversal(
            'LGR019G3J2',
            500,
            resultUrl: 'https://alternate.example.com/result'
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/mpesa/reversal/v1/request')
            && $request['ResultURL'] === 'https://alternate.example.com/result'
            && $request['QueueTimeOutURL'] === 'https://callbacks.example.com/reversal/timeout'
        );
    }

    public function test_timeout_url_can_be_overridden_independently(): void {
        $this->fakeDaraja();

        Mpesa::using('reversal')->sendReversal(
            'LGR019G3J2',
            500,
            timeoutUrl: 'https://alternate.example.com/timeout'
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/mpesa/reversal/v1/request')
            && $request['ResultURL'] === 'https://callbacks.example.com/reversal/result'
            && $request['QueueTimeOutURL'] === 'https://alternate.example.com/timeout'
        );
    }

    public function test_both_urls_and_request_fields_can_be_overridden(): void {
        $this->fakeDaraja();

        Mpesa::using('reversal')->sendReversal(
            transactionId: 'LGR019G3J2',
            amount: '125.50',
            receiverParty: '123456',
            receiverIdentifierType: 11,
            remarks: 'Incorrect payment',
            occasion: 'Customer refund',
            resultUrl: 'https://alternate.example.com/result',
            timeoutUrl: 'https://alternate.example.com/timeout'
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/mpesa/reversal/v1/request')
            && $request['Amount'] === '125.50'
            && $request['ReceiverParty'] === '123456'
            && $request['RecieverIdentifierType'] === '11'
            && $request['Remarks'] === 'Incorrect payment'
            && $request['Occasion'] === 'Customer refund'
            && $request['ResultURL'] === 'https://alternate.example.com/result'
            && $request['QueueTimeOutURL'] === 'https://alternate.example.com/timeout'
        );
    }

    public function test_untrusted_callback_host_is_rejected_before_any_request(): void {
        Http::fake();

        try {
            Mpesa::using('reversal')->sendReversal(
                transactionId: 'LGR019G3J2',
                amount: 500,
                resultUrl: 'https://untrusted.example.net/result'
            );

            $this->fail('Expected invalid callback host.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('allowlisted', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_empty_override_does_not_fall_back_to_default(): void {
        Http::fake();

        try {
            Mpesa::using('reversal')->sendReversal(
                'LGR019G3J2',
                500,
                timeoutUrl: ''
            );

            $this->fail('Expected invalid callback URL.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_invalid_amount_is_rejected_before_http(): void {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            Mpesa::using('reversal')->sendReversal('LGR019G3J2', '0.00');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_nonzero_acknowledgement_is_not_treated_as_success(): void {
        $this->fakeDaraja(['ResponseCode' => '1001']);

        try {
            Mpesa::using('reversal')->sendReversal('LGR019G3J2', 500);

            $this->fail('Expected reversal acknowledgement exception.');
        } catch (MpesaException $exception) {
            $this->assertStringContainsString('reconcile', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_only_reversal_profile_can_use_high_level_method(): void {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            Mpesa::sendReversal('LGR019G3J2', 500);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_legacy_reverse_transaction_signature_remains_supported(): void {
        $this->fakeDaraja();

        $response = Mpesa::using('reversal')->reverseTransaction(
            'testapi',
            'test-password',
            'https://callbacks.example.com/reversal/timeout',
            'https://callbacks.example.com/reversal/result',
            '600000',
            'LGR019G3J2',
            500
        );

        $this->assertSame('0', $response['ResponseCode']);
    }
}
