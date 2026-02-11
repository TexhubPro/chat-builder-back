<?php

namespace TexHub\AlifBank;

use Illuminate\Support\Facades\Http;
use TexHub\AlifBank\Models\AlifBankLog;
use Throwable;

class Client
{
    public function __construct(private array $config = [])
    {
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * Build checkout form payload.
     */
    public function buildCheckoutPayload(
        string $orderId,
        float $amount,
        ?string $callbackUrl = null,
        ?string $returnUrl = null,
        ?string $email = null,
        ?string $name = null,
        ?string $phone = null
    ): ?array {
        $key = $this->config['key'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($password) || $password === '') {
            $this->logError('AlifBank build payload failed', [
                'error' => 'Missing key or password.',
            ]);

            return null;
        }

        $amountFormatted = number_format($amount, 2, '.', '');
        $callback = $callbackUrl ?: ($this->config['callback_url'] ?? null);
        $return = $returnUrl ?: ($this->config['return_url'] ?? null);

        $gate = $this->config['gate'] ?? 'km';
        $token = $this->buildToken($key, $password, $orderId, $amountFormatted, $callback);

        return [
            'key' => $key,
            'order_id' => $orderId,
            'amount' => $amountFormatted,
            'callback_url' => $callback,
            'return_url' => $return,
            'gate' => $gate,
            'token' => $token,
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
        ];
    }

    /**
     * Checkout form action URL.
     */
    public function checkoutUrl(): string
    {
        $env = $this->config['environment'] ?? 'test';
        $base = $env === 'production'
            ? ($this->config['base_url'] ?? 'https://web.alif.tj/')
            : ($this->config['test_base_url'] ?? 'https://test-web.alif.tj/');

        return rtrim($base, '/');
    }

    /**
     * Verify webhook/callback signature.
     */
    public function verifyCallback(array $payload): bool
    {
        $password = $this->config['password'] ?? null;
        $key = $this->config['key'] ?? null;

        if (! is_string($password) || $password === '' || ! is_string($key) || $key === '') {
            $this->logError('AlifBank callback verify failed', [
                'error' => 'Missing key or password.',
            ]);

            return false;
        }

        $orderId = (string) ($payload['order_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $token = (string) ($payload['token'] ?? '');

        if ($orderId === '' || $status === '' || $transactionId === '' || $token === '') {
            return false;
        }

        $hashPassword = $this->hashPassword($key, $password);
        $expected = hash_hmac('sha256', $orderId . $status . $transactionId, $hashPassword);

        return hash_equals($expected, $token);
    }

    /**
     * Check transaction status.
     */
    public function checkStatus(string $orderId): ?array
    {
        $key = $this->config['key'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($password) || $password === '') {
            $this->logError('AlifBank check status failed', [
                'error' => 'Missing key or password.',
            ]);

            return null;
        }

        $hashPassword = $this->hashPassword($key, $password);
        $token = hash_hmac('sha256', $key . $orderId, $hashPassword);

        $base = $this->checkoutUrl();
        $path = $this->config['check_status_path'] ?? '/checktxn';
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');

        try {
            $response = Http::asForm()->post($url, [
                'key' => $key,
                'order_id' => $orderId,
                'token' => $token,
            ]);

            if (! $response->successful()) {
                $this->logError('AlifBank check status failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $exception) {
            $this->logError('AlifBank check status failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function buildToken(string $key, string $password, string $orderId, string $amount, ?string $callbackUrl): string
    {
        $hashPassword = $this->hashPassword($key, $password);
        $callback = $callbackUrl ?? '';

        return hash_hmac('sha256', $key . $orderId . $amount . $callback, $hashPassword);
    }

    private function hashPassword(string $key, string $password): string
    {
        return hash_hmac('sha256', $key, $password);
    }

    private function logError(string $title, array $data): void
    {
        try {
            AlifBankLog::create([
                'title' => $title,
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // ignore log errors
        }
    }
}
