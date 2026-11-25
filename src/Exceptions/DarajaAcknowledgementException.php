<?php

namespace Bmunyoki\Mpesa\Exceptions;

class DarajaAcknowledgementException extends MpesaException {

    public function __construct(
        private string $operation,
        private ?string $responseCode = null
    ) {
        parent::__construct(
            'Daraja did not acknowledge the ' .
            $operation .
            ' request as accepted. Reconciliation is required.'
        );
    }

    public function getOperation(): string {
        return $this->operation;
    }

    public function getResponseCode(): ?string {
        return $this->responseCode;
    }

    public function context(): array {
        return [
            'operation' => $this->operation,
            'provider_response_code' => $this->responseCode,
        ];
    }
}