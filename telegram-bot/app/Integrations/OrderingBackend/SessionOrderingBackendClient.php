<?php

namespace App\Integrations\OrderingBackend;

use App\Exceptions\OrderingBackendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

final readonly class SessionOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
        private LoggerInterface $logger,
    ) {
    }

    public function createTelegramSession(
        string $externalSessionId,
    ): string {
        try {
            $response = $this->transport
                ->request()
                ->post('api/sessions', [
                    'channel' => 'telegram',
                    'external_session_id' => $externalSessionId,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->transport->requestException(
                operation: 'create_telegram_session',
                message: 'Unable to create an ordering backend session.',
                exception: $exception,
            );
        }

        $sessionToken = $response->json('data.session_token');

        if (
            ! is_string($sessionToken)
            || trim($sessionToken) === ''
        ) {
            $this->logger->error(
                'Ordering backend returned an invalid response.',
                [
                    'operation' => 'create_telegram_session',
                    'status' => $response->status(),
                ],
            );

            throw new OrderingBackendException(
                'Ordering backend response did not contain a session token.',
            );
        }

        return $sessionToken;
    }

    public function updateCurrentSessionContact(
        string $sessionToken,
        string $name,
        string $phone,
    ): void {
        try {
            $response = $this->transport
                ->request($sessionToken)
                ->put(
                    'api/sessions/current/contact',
                    [
                        'name' => $name,
                        'phone' => $phone,
                    ],
                )
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->transport->requestException(
                operation: 'update_current_session_contact',
                message: 'Unable to update the ordering backend contact.',
                exception: $exception,
            );
        }

        if (! $this->isValidContactResponse($response)) {
            $this->logger->error(
                'Ordering backend returned an invalid response.',
                [
                    'operation' => 'update_current_session_contact',
                    'status' => $response->status(),
                ],
            );

            throw new OrderingBackendException(
                'Ordering backend response did not contain valid contact data.',
            );
        }
    }

    /**
     * @return array{session_id: string, payment_type: int}
     */
    public function updateCurrentSessionPayment(
        string $sessionToken,
        int $paymentType,
    ): array {
        $response = $this->transport->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/payment',
            operation: 'update_current_session_payment',
            message: 'Unable to update the ordering backend payment method.',
            data: [
                'payment_type' => $paymentType,
            ],
        );

        $data = $this->transport->responseData(
            $response,
            'update_current_session_payment',
            'Ordering backend returned malformed payment data.',
        );

        if (! $this->isValidPaymentData($data)) {
            throw $this->transport->invalidResponse(
                $response,
                'update_current_session_payment',
                'Ordering backend returned malformed payment data.',
            );
        }

        /** @var array{session_id: string, payment_type: int} $data */
        return [
            'session_id' => $data['session_id'],
            'payment_type' => $data['payment_type'],
        ];
    }

    /**
     * @return array{session_id: string, status: string}
     */
    public function deleteCurrentSession(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: 'api/sessions/current',
            operation: 'delete_current_session',
            message: 'Unable to close the current ordering backend session.',
        );

        $data = $this->transport->responseData(
            $response,
            'delete_current_session',
            'Ordering backend returned malformed session data.',
        );

        if (! $this->isValidDeletedSessionData($data)) {
            throw $this->transport->invalidResponse(
                $response,
                'delete_current_session',
                'Ordering backend returned malformed session data.',
            );
        }

        /** @var array{session_id: string, status: string} $data */
        return [
            'session_id' => $data['session_id'],
            'status' => $data['status'],
        ];
    }

    /**
     * @return array{expires_in: int, resend_available_in: int, code: string}
     */
    public function requestCurrentSessionOtp(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/otp',
            operation: 'request_current_session_otp',
            message: 'Unable to request an ordering backend OTP challenge.',
        );

        $data = $this->transport->responseData(
            $response,
            'request_current_session_otp',
            'Ordering backend returned malformed OTP data.',
        );

        if (! $this->isValidOtpData($data)) {
            throw $this->transport->invalidResponse(
                $response,
                'request_current_session_otp',
                'Ordering backend returned malformed OTP data.',
            );
        }

        /** @var array{expires_in: int, resend_available_in: int, code: string} $data */
        return [
            'expires_in' => $data['expires_in'],
            'resend_available_in' => $data['resend_available_in'],
            'code' => $data['code'],
        ];
    }

    /**
     * @return array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}
     */
    public function verifyCurrentSessionOtp(
        string $sessionToken,
        string $code,
    ): array {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/otp/verify',
            operation: 'verify_current_session_otp',
            message: 'Unable to verify the ordering backend OTP challenge.',
            data: [
                'code' => $code,
            ],
        );

        return $this->contactFromResponse(
            $response,
            'verify_current_session_otp',
        );
    }

    /**
     * @return array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}
     */
    private function contactFromResponse(
        Response $response,
        string $operation,
    ): array {
        $invalidMessage =
            'Ordering backend returned malformed contact data.';

        $data = $this->transport->responseData(
            $response,
            $operation,
            $invalidMessage,
        );

        if (! $this->isValidContactEnvelope($data)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{session_id: string, contact: array<string, mixed>} $data */
        $contact = $data['contact'];

        if (! $this->isValidContact($contact)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{name: string, phone: string, phone_verified: bool} $contact */
        return [
            'session_id' => $data['session_id'],
            'contact' => [
                'name' => $contact['name'],
                'phone' => $contact['phone'],
                'phone_verified' => $contact['phone_verified'],
            ],
        ];
    }

    private function isValidPaymentData(mixed $data): bool
    {
        return is_array($data)
            && $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
            && $this->transport->isPositiveInteger(
                $data['payment_type'] ?? null,
            );
    }

    private function isValidDeletedSessionData(
        mixed $data,
    ): bool {
        return is_array($data)
            && $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $data['status'] ?? null,
            );
    }

    private function isValidOtpData(mixed $data): bool
    {
        return is_array($data)
            && $this->transport->isNonNegativeInteger(
                $data['expires_in'] ?? null,
            )
            && $this->transport->isNonNegativeInteger(
                $data['resend_available_in'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $data['code'] ?? null,
            );
    }

    private function isValidContactEnvelope(
        mixed $data,
    ): bool {
        return is_array($data)
            && $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
            && is_array($data['contact'] ?? null);
    }

    private function isValidContact(mixed $contact): bool
    {
        return is_array($contact)
            && $this->transport->isNonEmptyString(
                $contact['name'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $contact['phone'] ?? null,
            )
            && is_bool(
                $contact['phone_verified'] ?? null,
            );
    }

    private function isValidContactResponse(
        Response $response,
    ): bool {
        $contact = $response->json('data.contact');

        return is_string(
            $response->json('data.session_id'),
        )
            && is_array($contact)
            && is_string($contact['name'] ?? null)
            && is_string($contact['phone'] ?? null)
            && is_bool(
                $contact['phone_verified'] ?? null,
            );
    }
}
