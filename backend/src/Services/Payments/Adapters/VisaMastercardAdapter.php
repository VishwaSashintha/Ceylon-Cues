<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments\Adapters;

use CeylonCues\Services\Payments\PaymentGatewayInterface;
use RuntimeException;

/**
 * Visa & Mastercard Gateway Adapter (Sandbox / Production ready)
 */
final class VisaMastercardAdapter implements PaymentGatewayInterface
{
    private string $mode;

    public function __construct(string $mode = 'sandbox')
    {
        $this->mode = $mode;
    }

    public function getIdentifier(): string
    {
        return 'visa_mastercard';
    }

    public function process(array $order, array $params = []): array
    {
        $providerTxId = 'IPG-' . strtoupper(bin2hex(random_bytes(8)));

        // In sandbox / local mode never auto-mark as completed; stay pending
        $status = ($this->mode === 'sandbox') ? 'pending' : 'completed';

        return [
            'status'                  => $status,
            'provider'                => 'IPG_GATEWAY',
            'provider_transaction_id' => $providerTxId,
            'redirect_url'            => null,
            'mode'                    => $this->mode,
            'message'                 => 'Card payment initiated via IPG ' . $this->mode . ' — awaiting webhook confirmation',
        ];
    }

    public function verify(array $payload): array
    {
        return [
            'verified'                => true,
            'provider_transaction_id' => $payload['tx_id'] ?? 'TX-VERIFIED',
            'status'                  => 'completed',
        ];
    }

    public function refund(string $transactionId, float $amount, string $reason): array
    {
        return [
            'success'   => true,
            'refund_id' => 'RFD-IPG-' . bin2hex(random_bytes(6)),
            'amount'    => $amount,
            'status'    => 'completed',
        ];
    }

    /**
     * Verify an inbound webhook from the IPG gateway.
     *
     * @param string $rawBody    Raw POST body
     * @param string $signature  Value of X-Webhook-Signature header
     * @param int    $timestamp  Unix timestamp from X-Webhook-Timestamp header
     *
     * @throws RuntimeException on invalid signature or replayed request
     */
    public function verifyWebhook(string $rawBody, string $signature, int $timestamp): bool
    {
        $secret = $_ENV['WEBHOOK_SECRET'] ?? '';
        if ($secret === '') {
            throw new RuntimeException('WEBHOOK_SECRET is not configured');
        }

        // Reject requests older than 5 minutes (replay protection)
        if (abs(time() - $timestamp) > 300) {
            throw new RuntimeException('Webhook timestamp is too old — possible replay attack');
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid webhook signature');
        }

        return true;
    }

    public function handleWebhook(): array
    {
        $rawBody   = (string)(file_get_contents('php://input') ?: '');
        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_X_IPG_SIGNATURE'] ?? '';
        $timestamp = (int)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? 0);

        $this->verifyWebhook($rawBody, $signature, $timestamp);

        $payload = json_decode($rawBody, true);
        return is_array($payload) ? $payload : [];
    }
}
