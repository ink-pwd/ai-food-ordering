<?php

namespace App\DTO;

final readonly class OtpChallengeData
{
    public function __construct(
        public string $sessionId,
        public string $phone,
        public string $codeHash,
        public int $attemptsRemaining,
        public string $expiresAt,
        public string $resendAvailableAt,
    ) {
    }

    /**
     * @param  array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string}  $challenge
     */
    public static function fromArray(array $challenge): self
    {
        return new self(
            sessionId: $challenge['session_id'],
            phone: $challenge['phone'],
            codeHash: $challenge['code_hash'],
            attemptsRemaining: $challenge['attempts_remaining'],
            expiresAt: $challenge['expires_at'],
            resendAvailableAt: $challenge['resend_available_at'],
        );
    }

    public function withAttemptsRemaining(int $attemptsRemaining): self
    {
        return new self(
            sessionId: $this->sessionId,
            phone: $this->phone,
            codeHash: $this->codeHash,
            attemptsRemaining: $attemptsRemaining,
            expiresAt: $this->expiresAt,
            resendAvailableAt: $this->resendAvailableAt,
        );
    }

    /**
     * @return array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string}
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'phone' => $this->phone,
            'code_hash' => $this->codeHash,
            'attempts_remaining' => $this->attemptsRemaining,
            'expires_at' => $this->expiresAt,
            'resend_available_at' => $this->resendAvailableAt,
        ];
    }
}
