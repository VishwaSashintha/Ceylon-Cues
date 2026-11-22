<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments\Adapters;

use CeylonCues\Services\Payments\PaymentGatewayInterface;

/**
 * KOKO Pay Buy-Now-Pay-Later Adapter (Sri Lanka)
 */
final class KokoAdapter implements PaymentGatewayInterface
{
    public function getIdentifier(): string
    {
        return 'koko';
    }

    public function process(array $order, array $params = []): array
    {
        $txId = 'KOKO-' . strtoupper(bin2hex(random_bytes(8)));
        return [
            'status'                 => 'completed',
            'provider'               => 'KOKO_PAY',
            'provider_transaction_id'=> $txId,
            'message'                => 'KOKO BNPL installment payment approved in sandbox mode',
        ];
    }

    public function verify(array $payload): array
    {
        return ['verified' => true, 'status' => 'completed'];
    }

    public function refund(string $transactionId, float $amount, string $reason): array
    {
        return ['success' => true, 'refund_id' => 'RFD-KOKO-' . bin2hex(random_bytes(4)), 'amount' => $amount];
    }
}
