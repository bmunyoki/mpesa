<?php

namespace Bmunyoki\Mpesa\Exceptions;

class DarajaHttpException extends MpesaException {

    public function __construct(
        private string $operation,
        private int $httpStatus,
        private ?string $providerErrorCode = null,
        private ?string $providerRequestId = null,
        private ?string $providerErrorMessage = null
    ) {
        $message = sprintf(
            'Daraja %s failed (HTTP %d).',
            $operation,
            $httpStatus
        );

        if ($providerErrorCode !== null) {
            $message .= ' Provider code: ' .
                $providerErrorCode . '.';
        }

        parent::__construct(
            $message,
            $httpStatus
        );
    }

    public function getOperation(): string {
        return $this->operation;
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }

    public function getProviderErrorCode(): ?string {
        return $this->providerErrorCode;
    }

    public function getProviderRequestId(): ?string {
        return $this->providerRequestId;
    }

    public function getProviderErrorMessage(): ?string {
        return $this->providerErrorMessage;
    }

    public function context(): array {
        return [
            'operation' => $this->operation,
            'http_status' => $this->httpStatus,
            'provider_error_code' => $this->providerErrorCode,
            'provider_request_id' => $this->providerRequestId,
            'provider_error_message' => $this->providerErrorMessage,
        ];
    }
}