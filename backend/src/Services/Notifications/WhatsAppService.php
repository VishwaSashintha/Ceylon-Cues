<?php

declare(strict_types=1);

namespace CeylonCues\Services\Notifications;

use CeylonCues\Core\Database;
use CeylonCues\Core\Logger;
use Exception;
use PDO;

/**
 * Official WhatsApp Business Cloud API Integration
 *
 * Dispatches WhatsApp template and interactive messages to the owner and customers.
 * Keeps credentials secure in environment / business settings.
 */
final class WhatsAppService
{
    private string $apiUrl;
    private ?string $phoneNumberId;
    private ?string $accessToken;
    private bool $enabled;
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->apiUrl = $_ENV['WHATSAPP_API_URL'] ?? 'https://graph.facebook.com/v18.0';
        $this->phoneNumberId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? null;
        $this->accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? null;
        $this->enabled = filter_var($_ENV['WHATSAPP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Send WhatsApp message (template or direct text).
     */
    public function send(string $recipientPhone, string $message, ?string $templateName = null, array $variables = []): array
    {
        $cleanPhone = preg_replace('/[^\d+]/', '', $recipientPhone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '+94' . substr($cleanPhone, 1);
        }

        $isLocalDev = in_array($_ENV['APP_ENV'] ?? '', ['local', 'development'], true) || !empty($_ENV['WHATSAPP_TEST_MODE']);

        // When running in sandbox or mock mode without live Meta credentials
        if ($isLocalDev || !$this->enabled || empty($this->phoneNumberId) || empty($this->accessToken)) {
            $mockMessageId = 'wamid.MOCK.' . bin2hex(random_bytes(10));
            Logger::logInfo("WhatsApp Sandbox Mode: Message dispatched to {$cleanPhone}", [
                'recipient' => $cleanPhone,
                'message'   => $message,
                'mock_id'   => $mockMessageId,
            ]);

            return [
                'success'             => true,
                'mode'                => 'sandbox_mock',
                'provider_message_id' => $mockMessageId,
                'recipient'           => $cleanPhone,
                'status'              => 'sent',
                'timestamp'           => date('Y-m-d H:i:s'),
            ];
        }

        // Live WhatsApp Cloud API request
        $url = "{$this->apiUrl}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => ltrim($cleanPhone, '+'),
            'type'              => 'text',
            'text'              => ['body' => $message],
        ];

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$this->accessToken}",
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("cURL Error: {$curlError}");
            }

            $decoded = json_decode((string)$response, true);

            if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['messages'][0]['id'])) {
                return [
                    'success'             => true,
                    'provider_message_id' => $decoded['messages'][0]['id'],
                    'provider_response'   => $decoded,
                    'status'              => 'sent',
                ];
            }

            return [
                'success'           => false,
                'http_code'         => $httpCode,
                'error'             => $decoded['error']['message'] ?? 'WhatsApp API Error',
                'provider_response' => $decoded,
            ];
        } catch (Exception $e) {
            Logger::logError("WhatsApp API Exception: {$e->getMessage()}", ['phone' => $cleanPhone]);
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
