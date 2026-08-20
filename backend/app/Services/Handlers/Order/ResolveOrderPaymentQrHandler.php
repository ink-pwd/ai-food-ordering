<?php

namespace App\Services\Handlers\Order;

use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveOrderPaymentQrHandler
{
    private const string DISK = 'local';

    private const string DIRECTORY = 'payment-qr';

    public function __construct(
        private readonly OrderRepository $orders,
    ) {
    }

    /** @return array{contents: string, order: Order} */
    public function handle(Order $order): array
    {
        $checkoutUrl = $order->payment_checkout_url;

        if (! $this->isValidCheckoutUrl($checkoutUrl)) {
            throw ValidationException::withMessages([
                'payment' => ['Payment checkout URL is not ready.'],
            ]);
        }

        /** @var string $checkoutUrl */
        $checkoutUrl = $checkoutUrl;

        $fingerprint = $this->fingerprint($checkoutUrl);

        if ($this->hasReusableQr($order, $fingerprint)) {
            /** @var string $paymentQrPath */
            $paymentQrPath = $order->payment_qr_path;
            $contents = Storage::disk(self::DISK)->get($paymentQrPath);

            if ($this->isValidPng($contents)) {
                /** @var string $contents */
                return ['contents' => $contents, 'order' => $order];
            }
        }

        return $this->generateAndStore($order, $checkoutUrl, $fingerprint);
    }

    private function isValidCheckoutUrl(mixed $checkoutUrl): bool
    {
        return is_string($checkoutUrl)
            && trim($checkoutUrl) !== ''
            && filter_var($checkoutUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($checkoutUrl, PHP_URL_SCHEME) === 'https';
    }

    private function fingerprint(string $checkoutUrl): string
    {
        return hash('sha256', $checkoutUrl);
    }

    private function hasReusableQr(Order $order, string $fingerprint): bool
    {
        return is_string($order->payment_qr_path)
            && $order->payment_qr_path !== ''
            && $order->payment_qr_fingerprint === $fingerprint
            && Storage::disk(self::DISK)->exists($order->payment_qr_path);
    }

    /** @return array{contents: string, order: Order} */
    private function generateAndStore(Order $order, string $checkoutUrl, string $fingerprint): array
    {
        try {
            $contents = $this->generatePng($checkoutUrl);

            if (! $this->isValidPng($contents)) {
                throw new RuntimeException('Generated QR PNG is empty or invalid.');
            }

            $path = $this->path($order);

            if (! Storage::disk(self::DISK)->put($path, $contents)) {
                throw new RuntimeException('Unable to write QR PNG to storage.');
            }

            $order = $this->orders->markPaymentQrReady($order, $path, $fingerprint);

            return ['contents' => $contents, 'order' => $order];
        } catch (RuntimeException $exception) {
            Log::error('Payment QR generation failed.', [
                'order_id' => $order->id,
                'reason' => $exception->getMessage(),
            ]);

            throw new HttpException(502, 'Payment QR is temporarily unavailable.', $exception);
        }
    }

    private function generatePng(string $checkoutUrl): string
    {
        $qrCode = new QrCode(
            data: $checkoutUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 320,
            margin: 16,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }

    private function isValidPng(?string $contents): bool
    {
        return is_string($contents)
            && strlen($contents) > 8
            && str_starts_with($contents, "\x89PNG\r\n\x1a\n");
    }

    private function path(Order $order): string
    {
        return self::DIRECTORY.'/'.$order->id.'.png';
    }
}
