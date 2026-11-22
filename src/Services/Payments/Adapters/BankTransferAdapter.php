<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments\Adapters;

use CeylonCues\Services\Payments\PaymentGatewayInterface;

/**
 * Bank Transfer Offline Payment Adapter
 */
final class BankTransferAdapter implements PaymentGatewayInterface
{
    public function getIdentifier(): string
    {
        return 'bank_transfer';
    }

    public function process(array $order, array $params = []): array
    {
        return [
            'status'         => 'awaiting_verification',
            'requires_proof' => true,
            'message'        => 'Please transfer the order total to our Commercial Bank of Ceylon account and upload your payment slip in order tracking.',
            'instructions'   => [
                'bank_name'      => 'Commercial Bank of Ceylon',
                'account_name'   => 'Ceylon Cues (Pvt) Ltd',
                'account_number' => '8012345678',
                'branch'         => 'Kollupitiya Branch',
                'currency'       => 'LKR',
                'amount'         => (float)$order['total_amount'],
                'reference'      => $order['reference_number'],
            ],
        ];
    }

    public function verify(array $payload): array
    {
        return ['verified' => true, 'status' => 'pending'];
    }

    public function refund(string $transactionId, float $amount, string $reason): array
    {
        return [
            'success'   => true,
            'refund_id' => 'REF-' . bin2hex(random_bytes(6)),
            'message'   => 'Bank transfer refund recorded for offline settlement',
        ];
    }
}
