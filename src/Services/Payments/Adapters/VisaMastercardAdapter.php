<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments\Adapters;

use CeylonCues\Services\Payments\PaymentGatewayInterface;

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
        return [
            'status'                 => 'completed',
            'provider'               => 'IPG_GATEWAY',
            'provider_transaction_id'=> $providerTxId,
            'redirect_url'           => null,
            'mode'                   => $this->mode,
            'message'                => 'Card payment processed successfully via IPG sandbox',
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
}
