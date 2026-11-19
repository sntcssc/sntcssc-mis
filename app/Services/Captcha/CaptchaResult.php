<?php

namespace App\Services\Captcha;

class CaptchaResult
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public ?string $errorCode = null,
        public ?float $score = null,
        public ?string $hostname = null,
        public array $raw = []
    ) {}

    public static function success(?string $message = null, ?float $score = 1.0, array $raw = []): self
    {
        return new self(
            success: true,
            message: $message ?? 'Captcha verified successfully.',
            errorCode: null,
            score: $score,
            raw: $raw
        );
    }

    public static function failure(string $message, ?string $errorCode = 'invalid-captcha', ?float $score = 0.0, array $raw = []): self
    {
        return new self(
            success: false,
            message: $message,
            errorCode: $errorCode,
            score: $score,
            raw: $raw
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'score' => $this->score,
            'hostname' => $this->hostname,
            'raw' => $this->raw,
        ];
    }
}
