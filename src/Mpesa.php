<?php

namespace Bmunyoki\Mpesa;

use Bmunyoki\Mpesa\Exceptions\MpesaException;
use Bmunyoki\Mpesa\Http\DarajaClient;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class Mpesa {
    public function __construct(
        private DarajaClient $client,
        private string $profile = 'default'
    ) {
    }

    public function using(string $profile): self {
        if (! preg_match('/^[a-z0-9_-]+$/i', $profile)) {
            throw new InvalidArgumentException('Invalid M-Pesa credential profile.');
        }

        $instance = clone $this;
        $instance->profile = $profile;

        return $instance;
    }

    public function getAccessToken(): string {
        return $this->client->getAccessToken($this->profile);
    }

    public function setCred(string $initiatorPassword): string {
        $this->required($initiatorPassword, 'Initiator password');

        $certificate = $this->client->environment() === 'sandbox'
            ? __DIR__.'/cert/sandbox.cer'
            : __DIR__.'/cert/production.cer';

        if (! is_readable($certificate)) {
            throw new MpesaException('M-Pesa public certificate is not readable.');
        }

        $publicKey = openssl_pkey_get_public(
            (string) file_get_contents($certificate)
        );

        if (
            $publicKey === false ||
            ! openssl_public_encrypt(
                $initiatorPassword,
                $encrypted,
                $publicKey,
                OPENSSL_PKCS1_PADDING
            )
        ) {
            throw new MpesaException('Unable to encrypt the M-Pesa initiator credential.');
        }

        return base64_encode($encrypted);
    }

    public function stkPush(
        string $phone,
        int $amount,
        string $reference = 'Payment',
        ?string $callbackUrl = null,
        string $description = 'Payment',
        string $transactionType = 'CustomerPayBillOnline',
        ?string $shortcode = null,
        ?string $partyB = null,
        ?string $passkey = null
    ): array {
        $this->positiveAmount($amount);
        $this->phone($phone);

        if (! in_array($transactionType, [
            'CustomerPayBillOnline',
            'CustomerBuyGoodsOnline'
        ], true)) {
            throw new InvalidArgumentException('Invalid STK transaction type.');
        }

        if (
            $reference === '' ||
            strlen($reference) > 12 ||
            $description === '' ||
            strlen($description) > 13
        ) {
            throw new InvalidArgumentException(
                'STK reference must be 1-12 characters and description 1-13 characters.'
            );
        }

        $buyGoods = $transactionType === 'CustomerBuyGoodsOnline';

        $shortcode ??= $this->option(
            'shortcode',
            $buyGoods ? 'store_number' : 'paybill'
        );

        $partyB ??= $this->option(
            'party_b',
            $buyGoods ? 'till' : 'paybill'
        );

        $passkey ??= $this->option('passkey', 'passkey');
        $callbackUrl ??= $this->option('callback_url', 'lnmo_callback_url');

        $this->shortcode($shortcode);
        $this->shortcode($partyB);
        $this->required($passkey, 'STK passkey');
        $this->callbackUrl($callbackUrl);

        $timestamp = (new DateTimeImmutable(
            'now',
            new DateTimeZone('Africa/Nairobi')
        ))->format('YmdHis');

        return $this->request('mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $shortcode,
            'Password' => base64_encode($shortcode.$passkey.$timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $transactionType,
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $partyB,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $reference,
            'TransactionDesc' => $description,
        ]);
    }

    // Custom STK method signature.
    public function stkRequest(
        string $paybillOrTill,
        string $storeNumber,
        string $passKey,
        int $amount,
        string $phone,
        string $callbackUrl,
        string $transactionType,
        string $ref = 'Payment',
        string $desc = 'Payment'
    ): array {
        return $this->stkPush(
            phone: $phone,
            amount: $amount,
            reference: $ref,
            callbackUrl: $callbackUrl,
            description: $desc,
            transactionType: $transactionType,
            shortcode: $storeNumber,
            partyB: $paybillOrTill,
            passkey: $passKey
        );
    }

    public function stkQuery(
        string $checkoutRequestId,
        ?string $shortcode = null,
        ?string $passkey = null
    ): array {
        $this->required($checkoutRequestId, 'CheckoutRequestID');

        $shortcode ??= $this->option('shortcode', 'paybill');
        $passkey ??= $this->option('passkey', 'passkey');

        $this->shortcode($shortcode);
        $this->required($passkey, 'STK passkey');

        $timestamp = (new DateTimeImmutable(
            'now',
            new DateTimeZone('Africa/Nairobi')
        ))->format('YmdHis');

        $version = $this->endpointVersion('stk_query', ['v1', 'v2']);

        return $this->request("mpesa/stkpushquery/{$version}/query", [
            'BusinessShortCode' => $shortcode,
            'Password' => base64_encode($shortcode.$passkey.$timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }

    public function c2bRegisterUrls(
        string $paybillOrTill,
        string $confirmationUrl,
        string $validationUrl
    ): array {
        $this->shortcode($paybillOrTill);
        $this->callbackUrl($confirmationUrl);
        $this->callbackUrl($validationUrl);

        $version = $this->endpointVersion('c2b', ['v1', 'v2']);

        return $this->request("mpesa/c2b/{$version}/registerurl", [
            'ShortCode' => $paybillOrTill,
            'ResponseType' => 'Completed',
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL' => $validationUrl,
        ]);
    }

    public function simulateC2B(
        string $paybillOrTill,
        int $amount,
        string $msisdn,
        string $ref
    ): array {
        if ($this->client->environment() !== 'sandbox') {
            throw new MpesaException(
                'C2B simulation is available only in the sandbox.'
            );
        }

        $this->shortcode($paybillOrTill);
        $this->positiveAmount($amount);
        $this->phone($msisdn);
        $this->required($ref, 'C2B reference');

        $version = $this->endpointVersion('c2b', ['v1', 'v2']);

        return $this->request("mpesa/c2b/{$version}/simulate", [
            'ShortCode' => $paybillOrTill,
            'CommandID' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'Msisdn' => $msisdn,
            'BillRefNumber' => $ref,
        ]);
    }

    public function b2c(
        string $paybillOrTill,
        string $initiatorUsername,
        string $initiatorPassword,
        int $amount,
        string $phone,
        string $commandId,
        string $remarks,
        string $b2cTimeoutUrl,
        string $b2cResultUrl
    ): array {
        $this->shortcode($paybillOrTill);
        $this->positiveAmount($amount);
        $this->phone($phone);
        $this->required($initiatorUsername, 'Initiator username');
        $this->required($commandId, 'B2C CommandID');
        $this->required($remarks, 'B2C remarks');
        $this->callbackUrl($b2cTimeoutUrl);
        $this->callbackUrl($b2cResultUrl);

        $version = $this->endpointVersion('b2c', ['v1', 'v3']);

        return $this->request("mpesa/b2c/{$version}/paymentrequest", [
            'InitiatorName' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'CommandID' => $commandId,
            'Amount' => $amount,
            'PartyA' => $paybillOrTill,
            'PartyB' => $phone,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => $b2cTimeoutUrl,
            'ResultURL' => $b2cResultUrl,
            'Occasion' => '',
        ]);
    }

    public function b2b(
        string $initiatorUsername,
        string $initiatorPassword,
        int $amount,
        string $paybillOrTill,
        string $shortcode,
        string $reference,
        string $timeoutUrl,
        string $resultUrl
    ): array {
        $this->shortcode($paybillOrTill);
        $this->shortcode($shortcode);
        $this->positiveAmount($amount);
        $this->required($initiatorUsername, 'Initiator username');
        $this->required($reference, 'B2B reference');
        $this->callbackUrl($timeoutUrl);
        $this->callbackUrl($resultUrl);

        return $this->request('mpesa/b2b/v1/paymentrequest', [
            'Initiator' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'CommandID' => 'BusinessToBusinessTransfer',
            'SenderIdentifierType' => '4',
            'RecieverIdentifierType' => '4',
            'Amount' => $amount,
            'PartyA' => $paybillOrTill,
            'PartyB' => $shortcode,
            'AccountReference' => $reference,
            'Remarks' => 'B2B payment',
            'QueueTimeOutURL' => $timeoutUrl,
            'ResultURL' => $resultUrl,
        ]);
    }

    public function checkBalance(
        string $paybillOrTill,
        string $initiatorUsername,
        string $initiatorPassword,
        string $timeoutUrl,
        string $resultUrl
    ): array {
        $this->shortcode($paybillOrTill);
        $this->required($initiatorUsername, 'Initiator username');
        $this->callbackUrl($timeoutUrl);
        $this->callbackUrl($resultUrl);

        return $this->request('mpesa/accountbalance/v1/query', [
            'CommandID' => 'AccountBalance',
            'PartyA' => $paybillOrTill,
            'IdentifierType' => '4',
            'Remarks' => 'Account balance query',
            'Initiator' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'QueueTimeOutURL' => $timeoutUrl,
            'ResultURL' => $resultUrl,
        ]);
    }

    public function statusRequest(
        string $paybillOrTill,
        string $initiatorUsername,
        string $initiatorPassword,
        string $timeoutUrl,
        string $resultUrl,
        string $transactionId
    ): array {
        $this->shortcode($paybillOrTill);
        $this->required($initiatorUsername, 'Initiator username');
        $this->required($transactionId, 'TransactionID');
        $this->callbackUrl($timeoutUrl);
        $this->callbackUrl($resultUrl);

        return $this->request('mpesa/transactionstatus/v1/query', [
            'CommandID' => 'TransactionStatusQuery',
            'PartyA' => $paybillOrTill,
            'IdentifierType' => 4,
            'Remarks' => 'Transaction status query',
            'Initiator' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'QueueTimeOutURL' => $timeoutUrl,
            'ResultURL' => $resultUrl,
            'TransactionID' => $transactionId,
            'Occasion' => 'Status query',
        ]);
    }

    public function reverseTransaction(
        string $initiatorUsername,
        string $initiatorPassword,
        string $timeoutUrl,
        string $resultUrl,
        string $receiver,
        string $transactionId,
        int $amount,
        int $receiverIdentifierType = 4
    ): array {
        $this->required($initiatorUsername, 'Initiator username');
        $this->required($receiver, 'ReceiverParty');
        $this->required($transactionId, 'TransactionID');
        $this->positiveAmount($amount);
        $this->callbackUrl($timeoutUrl);
        $this->callbackUrl($resultUrl);

        if (! in_array($receiverIdentifierType, [1, 2, 4], true)) {
            throw new InvalidArgumentException(
                'Receiver identifier type must be 1, 2 or 4.'
            );
        }

        return $this->request('mpesa/reversal/v1/request', [
            'CommandID' => 'TransactionReversal',
            'ReceiverParty' => $receiver,
            'RecieverIdentifierType' => $receiverIdentifierType,
            'Remarks' => 'Transaction reversal',
            'Amount' => $amount,
            'Initiator' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'QueueTimeOutURL' => $timeoutUrl,
            'ResultURL' => $resultUrl,
            'TransactionID' => $transactionId,
        ]);
    }

    public function pullRegisterUrl(
        string $nominatedNumber,
        string $storeNumber,
        string $callbackUrl
    ): array {
        $this->phone($nominatedNumber);
        $this->shortcode($storeNumber);
        $this->callbackUrl($callbackUrl);

        return $this->request('pulltransactions/v1/register', [
            'ShortCode' => $storeNumber,
            'RequestType' => 'Pull',
            'NominatedNumber' => $nominatedNumber,
            'CallBackURL' => $callbackUrl,
        ]);
    }

    public function pullAPI(
        string $storeNumber,
        string $start,
        string $end,
        int $offset = 0
    ): array {
        $this->shortcode($storeNumber);
        $this->required($start, 'StartDate');
        $this->required($end, 'EndDate');

        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must not be negative.');
        }

        return $this->request('pulltransactions/v1/query', [
            'ShortCode' => $storeNumber,
            'StartDate' => $start,
            'EndDate' => $end,
            'OffSetValue' => (string) $offset,
        ]);
    }

    private function request(string $path, array $payload): array {
        return $this->client->post($path, $payload, $this->profile);
    }

    private function option(string $accountKey, string $rootKey): string {
        $value = $this->profile === 'default'
            ? null
            : config("mpesa.accounts.{$this->profile}.{$accountKey}");

        return (string) (
            ($value !== null && $value !== '')
                ? $value
                : config("mpesa.{$rootKey}", '')
        );
    }

    private function endpointVersion(string $name, array $allowed): string {
        $value = (string) config(
            "mpesa.endpoint_versions.{$name}",
            $allowed[0]
        );

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid Daraja endpoint version: {$name}."
            );
        }

        return $value;
    }

    private function required(string $value, string $label): void {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }
    }

    private function positiveAmount(int $amount): void {
        if ($amount < 1) {
            throw new InvalidArgumentException(
                'Amount must be a positive whole number in KES.'
            );
        }
    }

    private function phone(string $number): void {
        if (! preg_match('/^254[71][0-9]{8}$/', $number)) {
            throw new InvalidArgumentException(
                'Phone must be in the format 2547XXXXXXXX or 2541XXXXXXXX.'
            );
        }
    }

    private function shortcode(string $number): void {
        if (! preg_match('/^[0-9]{5,10}$/', $number)) {
            throw new InvalidArgumentException(
                'Shortcode must be a 5-10 digit number.'
            );
        }
    }

    private function callbackUrl(string $url): void {
        if (
            ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        ) {
            throw new InvalidArgumentException(
                'Callback URL must be a valid HTTPS URL without embedded credentials.'
            );
        }
    }
}
