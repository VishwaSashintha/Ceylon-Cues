<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments;

/**
 * Payment Gateway Adapter Contract
 */
interface PaymentGatewayInterface
{
    /**
     * Get unique identifier for the provider adapter.
     */
    public function getIdentifier(): string;

    /**
     * Initiate or process payment for an order.
     */
    public function process(array $order, array $params = []): array;

    /**
     * Verify payment status or webhook callback payload.
     */
    public function verify(array $payload): array;

    /**
     * Process a refund.
     */
    public function refund(string $transactionId, float $amount, string $reason): array;
}
