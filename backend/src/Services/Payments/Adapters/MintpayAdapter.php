<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments\Adapters;

use CeylonCues\Services\Payments\PaymentGatewayInterface;

/**
 * Mintpay Buy-Now-Pay-Later Adapter (Sri Lanka)
 */
final class MintpayAdapter implements PaymentGatewayInterface
{
    public function getIdentifier(): string
    {
        return 'mintpay';
    }

    public function process(array $order, array $params = []): array
    {
        $txId = 'MINTPAY-' . strtoupper(bin2hex(random_bytes(8)));
        return [
            'status'                 => 'completed',
            'provider'               => 'MINTPAY',
            'provider_transaction_id'=> $txId,
            'message'                => 'Mintpay installment payment approved in sandbox mode',
        ];
    }

    public function verify(array $payload): array
    {
        return ['verified' => true, 'status' => 'completed'];
    }

    public function refund(string $transactionId, float $amount, string $reason): array
    {
        return ['success' => true, 'refund_id' => 'RFD-MINT-' . bin2hex(random_bytes(4)), 'amount' => $amount];
    }
}
