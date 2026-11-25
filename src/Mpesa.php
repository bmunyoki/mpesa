<?php

namespace Bmunyoki\Mpesa;

use Bmunyoki\Mpesa\Exceptions\MpesaException;
use Bmunyoki\Mpesa\Exceptions\DarajaAcknowledgementException;
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
        string $b2cResultUrl,
        ?string $originatorConversationId = null,
        ?string $occasion = null
    ): array {
        $this->shortcode($paybillOrTill);
        $this->phone($phone);
        $this->positiveAmount($amount);

        $this->required($initiatorUsername, 'B2C initiator name');
        $this->required($initiatorPassword, 'B2C initiator password');

        $this->validateB2cCallbackUrl($b2cTimeoutUrl);
        $this->validateB2cCallbackUrl($b2cResultUrl);

        if (! in_array($commandId, [
            'BusinessPayment',
            'SalaryPayment',
            'PromotionPayment',
        ], true)) {
            throw new InvalidArgumentException(
                'Invalid B2C CommandID.'
            );
        }

        $remarks = trim($remarks);

        if (strlen($remarks) < 2 || strlen($remarks) > 100) {
            throw new InvalidArgumentException(
                'B2C remarks must be 2-100 characters.'
            );
        }

        if (
            $occasion !== null
            && (trim($occasion) === '' || strlen($occasion) > 100)
        ) {
            throw new InvalidArgumentException(
                'B2C occasion must be 1-100 characters.'
            );
        }

        $version = $this->endpointVersion('b2c', ['v1', 'v3']);

        if (
            $version === 'v3'
            && (
                ! is_string($originatorConversationId)
                || ! preg_match(
                    '/^[A-Za-z0-9_-]{8,64}$/',
                    $originatorConversationId
                )
            )
        ) {
            throw new InvalidArgumentException(
                'B2C v3 requires a unique OriginatorConversationID (8-64 characters).'
            );
        }

        $data = [
            'InitiatorName' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'CommandID' => $commandId,
            'Amount' => $amount,
            'PartyA' => $paybillOrTill,
            'PartyB' => $phone,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => $b2cTimeoutUrl,
            'ResultURL' => $b2cResultUrl,
        ];

        if ($version === 'v3') {
            $data['OriginatorConversationID'] = $originatorConversationId;

            if ($occasion !== null) {
                $data['Occassion'] = $occasion;
            }
        } elseif ($occasion !== null) {
            $data['Occasion'] = $occasion;
        }

        $response = $this->request(
            "mpesa/b2c/{$version}/paymentrequest",
            $data
        );

        $responseCode = $response['ResponseCode'] ?? null;

        if ((string) $responseCode !== '0') {
            $safeResponseCode = null;

            if (
                is_string($responseCode) ||
                is_int($responseCode)
            ) {
                $value = (string) $responseCode;

                if (preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $value)) {
                    $safeResponseCode = $value;
                }
            }

            throw new DarajaAcknowledgementException(
                operation: 'b2c',
                responseCode: $safeResponseCode
            );
        }

        return $response;

    }
    
    public function sendB2C(
        string $phone,
        int $amount,
        string $remarks,
        string $originatorConversationId,
        ?string $resultUrl = null,
        ?string $timeoutUrl = null,
        ?string $commandId = null,
        ?string $occasion = null
    ): array {
        if ($this->profile !== 'b2c') {
            throw new InvalidArgumentException(
                'Select B2C using Mpesa::using("b2c").'
            );
        }

        $account = config('mpesa.accounts.b2c', []);

        if (! is_array($account)) {
            throw new MpesaException(
                'The B2C account configuration is invalid.'
            );
        }

        // Only null triggers fallback. An empty override is invalid.
        $resultUrl ??= $account['result_url'] ?? null;
        $timeoutUrl ??= $account['timeout_url'] ?? null;

        if (! is_string($resultUrl) || ! is_string($timeoutUrl)) {
            throw new InvalidArgumentException(
                'B2C result and timeout URLs must be configured or supplied.'
            );
        }

        $this->validateB2cCallbackUrl($resultUrl);
        $this->validateB2cCallbackUrl($timeoutUrl);

        return $this->b2c(
            paybillOrTill: (string) ($account['shortcode'] ?? ''),
            initiatorUsername: (string) ($account['initiator_name'] ?? ''),
            initiatorPassword: (string) ($account['initiator_password'] ?? ''),
            amount: $amount,
            phone: $phone,
            commandId: $commandId ?? (string) ($account['command_id'] ?? 'BusinessPayment'),
            remarks: $remarks,
            b2cTimeoutUrl: $timeoutUrl,
            b2cResultUrl: $resultUrl,
            originatorConversationId: $originatorConversationId,
            occasion: $occasion
        );
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
        int|string $amount,
        int $receiverIdentifierType = 4,
        string $remarks = 'Transaction reversal',
        ?string $occasion = null
    ): array {
        $this->required($initiatorUsername, 'Reversal initiator username');
        $this->required($initiatorPassword, 'Reversal initiator password');

        if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $transactionId)) {
            throw new InvalidArgumentException('Invalid original M-Pesa TransactionID.');
        }

        if (is_int($amount)) {
            $this->positiveAmount($amount);
        } elseif (
            ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $amount)
            || preg_match('/^0(?:\.0{1,2})?$/', $amount)
        ) {
            throw new InvalidArgumentException(
                'Reversal amount must be a positive KES amount with at most two decimal places.'
            );
        }

        if (! in_array($receiverIdentifierType, [1, 2, 4, 11], true)) {
            throw new InvalidArgumentException('Invalid reversal receiver identifier type.');
        }

        if ($receiverIdentifierType === 1) {
            $this->phone($receiver);
        } else {
            $this->shortcode($receiver);
        }

        if (trim($remarks) === '' || strlen($remarks) > 100) {
            throw new InvalidArgumentException('Reversal remarks must be 1-100 characters.');
        }

        if ($occasion !== null && (trim($occasion) === '' || strlen($occasion) > 100)) {
            throw new InvalidArgumentException('Reversal occasion must be 1-100 characters.');
        }

        $this->validateReversalCallbackUrl($timeoutUrl);
        $this->validateReversalCallbackUrl($resultUrl);

        $payload = [
            'Initiator' => $initiatorUsername,
            'SecurityCredential' => $this->setCred($initiatorPassword),
            'CommandID' => 'TransactionReversal',
            'TransactionID' => $transactionId,
            'Amount' => $amount,
            'ReceiverParty' => $receiver,
            'RecieverIdentifierType' => (string) $receiverIdentifierType,
            'ResultURL' => $resultUrl,
            'QueueTimeOutURL' => $timeoutUrl,
            'Remarks' => $remarks,
        ];

        if ($occasion !== null) {
            $payload['Occasion'] = $occasion;
        }

        $response = $this->request('mpesa/reversal/v1/request', $payload);

        $responseCode = $response['ResponseCode'] ?? null;

        if ((string) $responseCode !== '0') {
            $safeResponseCode = null;

            if (
                is_string($responseCode) ||
                is_int($responseCode)
            ) {
                $value = (string) $responseCode;

                if (preg_match(
                    '/\A[a-zA-Z0-9._-]{1,64}\z/',
                    $value
                )) {
                    $safeResponseCode = $value;
                }
            }

            throw new DarajaAcknowledgementException(
                operation: 'reversal',
                responseCode: $safeResponseCode
            );
        }

        return $response;
    }


    public function sendReversal(
        string $transactionId,
        int|string $amount,
        ?string $receiverParty = null,
        ?int $receiverIdentifierType = null,
        ?string $remarks = null,
        ?string $occasion = null,
        ?string $resultUrl = null,
        ?string $timeoutUrl = null
    ): array {
        if ($this->profile !== 'reversal') {
            throw new InvalidArgumentException(
                'Select the reversal profile using Mpesa::using("reversal").'
            );
        }

        $account = config('mpesa.accounts.reversal', []);

        if (! is_array($account)) {
            throw new MpesaException('The reversal account configuration is invalid.');
        }

        $receiverParty ??= $account['shortcode'] ?? '';
        $receiverIdentifierType ??= (int) ($account['receiver_identifier_type'] ?? 4);
        $remarks ??= $account['remarks'] ?? 'Transaction reversal';
        $occasion ??= $account['occasion'] ?? null;
        $resultUrl ??= $account['result_url'] ?? '';
        $timeoutUrl ??= $account['timeout_url'] ?? '';

        return $this->reverseTransaction(
            initiatorUsername: (string) ($account['initiator_name'] ?? ''),
            initiatorPassword: (string) ($account['initiator_password'] ?? ''),
            timeoutUrl: $timeoutUrl,
            resultUrl: $resultUrl,
            receiver: $receiverParty,
            transactionId: $transactionId,
            amount: $amount,
            receiverIdentifierType: $receiverIdentifierType,
            remarks: $remarks,
            occasion: $occasion
        );
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


    private function validateB2cCallbackUrl(string $url): void {
        $this->callbackUrl($url);

        $allowedHosts = config(
            'mpesa.accounts.b2c.allowed_callback_hosts',
            []
        );

        if (! is_array($allowedHosts)) {
            throw new MpesaException(
                'B2C allowed callback hosts configuration must be an array.'
            );
        }

        if ($allowedHosts === []) {
            return;
        }

        $hostname = strtolower(rtrim(
            (string) parse_url($url, PHP_URL_HOST),
            '.'
        ));

        $hosts = array_map(
            fn ($host) => strtolower(rtrim((string) $host, '.')),
            $allowedHosts
        );

        if (! in_array($hostname, $hosts, true)) {
            throw new InvalidArgumentException(
                'B2C callback host is not allowlisted.'
            );
        }
    }

    
    private function validateReversalCallbackUrl(string $url): void {
        $this->callbackUrl($url);

        $allowedHosts = config('mpesa.accounts.reversal.allowed_callback_hosts', []);

        if (! is_array($allowedHosts)) {
            throw new MpesaException('Reversal callback host allowlist must be an array.');
        }

        if ($allowedHosts === []) {
            return;
        }

        $hostname = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $hosts = array_map(
            fn ($host) => strtolower(rtrim((string) $host, '.')),
            $allowedHosts
        );

        if (! in_array($hostname, $hosts, true)) {
            throw new InvalidArgumentException('Reversal callback host is not allowlisted.');
        }
    }


}
