<?php

namespace App\Telegram\Checkout;

use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\OrderData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Formatting\OrderMessageFormatter;
use App\Telegram\Keyboards\OrderKeyboard;
use App\Telegram\TelegramMessageEditor;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

final readonly class CheckoutOrderPresenter
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderMessageFormatter $orderFormatter,
        private OrderKeyboard $orderKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function orderFlow(
        Nutgram $bot,
        OrderData $order,
        string $sessionToken,
        string $context,
    ): void {
        if ($order->status === 'creating' || $order->status === 'failed') {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->orderFormatter->format($order),
                keyboard: $this->orderKeyboard->order(
                    $order->status,
                    $context,
                ),
            );

            return;
        }

        if ($order->paymentType !== 2) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->orderFormatter->format($order),
                keyboard: $this->orderKeyboard->order(
                    $order->status,
                    $context,
                ),
            );

            return;
        }

        $payment = new CurrentPaymentData(
            status: $order->payment->status,
            checkoutUrl: $order->payment->checkoutUrl,
            paymentReceivedAt: $order->payment->paymentReceivedAt,
            httpStatus: 200,
        );

        $this->payment(
            $bot,
            $payment,
            $sessionToken,
            $context,
            $this->orderFormatter->format($order),
        );
    }

    public function payment(
        Nutgram $bot,
        CurrentPaymentData $payment,
        string $sessionToken,
        string $context,
        ?string $orderText = null,
    ): void {
        if ($payment->paymentReceivedAt !== null) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText(
                    $orderText,
                    $this->orderFormatter->payment($payment),
                ),
                keyboard: $this->orderKeyboard->paymentReady(
                    '',
                    $context,
                    includePayButton: false,
                ),
            );

            return;
        }

        if ($payment->status !== 'ready') {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText(
                    $orderText,
                    $this->orderFormatter->payment($payment),
                ),
                keyboard: $this->orderKeyboard->paymentPending($context),
            );

            return;
        }

        $checkoutUrl = $payment->checkoutUrl;

        if (! $this->isTrustedCheckoutUrl($checkoutUrl)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText(
                    $orderText,
                    '⚠️ Платіжні дані тимчасово недоступні. Оновіть оплату трохи пізніше.',
                ),
                keyboard: $this->orderKeyboard->paymentPending($context),
            );

            return;
        }

        /** @var string $checkoutUrl */
        $paymentText = $this->combineText(
            $orderText,
            $this->orderFormatter->payment($payment),
        );

        if ($this->sendPaymentQr(
            $bot,
            $sessionToken,
            $paymentText,
            $checkoutUrl,
            $context,
        )) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->combineText(
                $orderText,
                $this->orderFormatter->payment(
                    $payment,
                    '⚠️ QR-код тимчасово недоступний. Скористайтеся кнопкою оплати.',
                ),
            ),
            keyboard: $this->orderKeyboard->paymentReady(
                $checkoutUrl,
                $context,
            ),
        );
    }

    private function sendPaymentQr(
        Nutgram $bot,
        string $sessionToken,
        string $caption,
        string $checkoutUrl,
        string $context,
    ): bool {
        try {
            $qr = $this->backend->currentPaymentQr($sessionToken);
        } catch (OrderingBackendException $exception) {
            Log::error('QR DEBUG: backend exception', [
                'message' => $exception->getMessage(),
                'status' => $exception->statusCode(),
            ]);

            return false;
        }

        Log::info('QR DEBUG: backend response', [
            'status' => $qr['status'] ?? null,
            'has_contents' => isset($qr['contents']),
            'contents_size' => isset($qr['contents'])
                ? strlen($qr['contents'])
                : null,
        ]);

        if (($qr['status'] ?? null) !== 'ready') {
            Log::warning('QR DEBUG: QR is not ready');

            return false;
        }

        $stream = fopen('php://temp', 'rb+');

        if ($stream === false) {
            Log::error('QR DEBUG: failed to open temp stream');

            return false;
        }

        try {
            fwrite($stream, $qr['contents']);
            rewind($stream);

            Log::info('QR DEBUG: sending photo to Telegram', [
                'contents_size' => strlen($qr['contents']),
            ]);

            $bot->sendPhoto(
                photo: InputFile::make($stream, 'payment-qr.png'),
                caption: $caption,
                reply_markup: $this->orderKeyboard->paymentReady(
                    $checkoutUrl,
                    $context,
                ),
            );

            Log::info('QR DEBUG: photo sent successfully');
        } finally {
            fclose($stream);
        }

        return true;
    }

    private function combineText(
        ?string $orderText,
        string $paymentText,
    ): string {
        if ($orderText === null) {
            return $paymentText;
        }

        return $orderText."\n\n".$paymentText;
    }

    private function isTrustedCheckoutUrl(
        ?string $checkoutUrl,
    ): bool {
        return is_string($checkoutUrl)
            && filter_var($checkoutUrl, FILTER_VALIDATE_URL) !== false
            && str_starts_with($checkoutUrl, 'https://');
    }
}
