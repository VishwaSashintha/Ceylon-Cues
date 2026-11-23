<?php

declare(strict_types=1);

namespace CeylonCues\Services\Notifications;

use CeylonCues\Core\Database;
use CeylonCues\Core\Logger;
use PDO;
use RuntimeException;

/**
 * Background Notification Queue & Dispatcher
 *
 * Independent message queue guaranteeing that events are logged, scheduled,
 * retried on failure, and processed by background workers (cron / daemon).
 */
final class NotificationQueueService
{
    private PDO $db;
    private WhatsAppService $whatsapp;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->whatsapp = new WhatsAppService();
    }

    /**
     * Get the configured owner WhatsApp notification phone number.
     */
    public function getOwnerPhoneNumber(): string
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM business_settings WHERE setting_key = 'owner_whatsapp' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val ?: ($_ENV['WHATSAPP_OWNER_NUMBER'] ?? '+94770000000');
    }

    /**
     * Queue a business event notification (e.g. order.created, payment.bank_transfer_submitted).
     */
    public function queueEvent(string $eventType, array $data, ?string $recipient = null, int $priority = 5): int
    {
        // If recipient is null, default to owner
        if (!$recipient) {
            $recipient = $this->getOwnerPhoneNumber();
        }

        // Fetch template
        $stmt = $this->db->prepare("SELECT * FROM notification_templates WHERE event_type = :event AND is_active = 1 LIMIT 1");
        $stmt->execute([':event' => $eventType]);
        $template = $stmt->fetch();

        // Render template body
        $messageBody = $template ? $this->renderTemplate($template['template_body'], $data) : json_encode($data);

        // Generate idempotency key to prevent duplicate notifications
        $idempotencyKey = hash('sha256', $eventType . '|' . $recipient . '|' . ($data['order_ref'] ?? '') . '|' . ($data['timestamp'] ?? date('Y-m-d H:i')));

        $stmtQueue = $this->db->prepare("
            INSERT INTO notification_queue (
                channel, recipient, template_id, event_type, payload,
                status, priority, idempotency_key
            ) VALUES (
                'whatsapp', :recipient, :tmpl_id, :event_type, :payload,
                'pending', :prio, :ikey
            )
            ON DUPLICATE KEY UPDATE attempts = attempts
        ");

        $stmtQueue->execute([
            ':recipient'  => $recipient,
            ':tmpl_id'    => $template['id'] ?? null,
            ':event_type' => $eventType,
            ':payload'    => json_encode(array_merge($data, ['rendered_body' => $messageBody]), JSON_UNESCAPED_UNICODE),
            ':prio'       => $priority,
            ':ikey'       => $idempotencyKey,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * GET /api/v1/notifications/queue — list pending items for ERP display
     */
    public function getPendingItems(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT id, event_type, recipient_phone, status, attempts, priority, created_at, scheduled_at
            FROM notification_queue
            WHERE status IN ('pending', 'failed')
            ORDER BY priority DESC, created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Process pending messages in the queue (run by CLI worker or cron).
     */
    public function processPendingQueue(int $batchSize = 20): int
    {
        $stmt = $this->db->prepare("
            SELECT * FROM notification_queue
            WHERE status IN ('pending', 'failed')
              AND attempts < max_attempts
              AND (scheduled_at IS NULL OR scheduled_at <= NOW())
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY priority DESC, created_at ASC
            LIMIT :limit
            FOR UPDATE
        ");
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $processedCount = 0;

        foreach ($items as $item) {
            $queueId = (int)$item['id'];
            $payload = json_decode($item['payload'], true);
            $messageText = $payload['rendered_body'] ?? 'Ceylon Cues notification';

            // Mark as processing
            $this->db->prepare("UPDATE notification_queue SET status = 'processing', attempts = attempts + 1 WHERE id = :id")
                ->execute([':id' => $queueId]);

            // Dispatch via WhatsApp
            $res = $this->whatsapp->send($item['recipient'], $messageText);

            if ($res['success']) {
                $this->db->prepare("
                    UPDATE notification_queue
                    SET status = 'sent', provider_message_id = :mid, provider_response = :resp, sent_at = NOW()
                    WHERE id = :id
                ")->execute([
                    ':mid'  => $res['provider_message_id'] ?? null,
                    ':resp' => json_encode($res, JSON_UNESCAPED_UNICODE),
                    ':id'   => $queueId,
                ]);

                // Write audit log
                $this->db->prepare("
                    INSERT INTO notification_logs (queue_id, channel, recipient, event_type, status, provider_message_id)
                    VALUES (:qid, 'whatsapp', :rec, :event, 'sent', :mid)
                ")->execute([
                    ':qid'   => $queueId,
                    ':rec'   => $item['recipient'],
                    ':event' => $item['event_type'],
                    ':mid'   => $res['provider_message_id'] ?? null,
                ]);

                $processedCount++;
            } else {
                // Retry backoff (exponential backoff: 2min, 10min, 30min)
                $attempts = (int)$item['attempts'] + 1;
                $backoffMinutes = match ($attempts) {
                    1 => 2,
                    2 => 10,
                    default => 30,
                };
                $nextRetry = date('Y-m-d H:i:s', time() + ($backoffMinutes * 60));

                $this->db->prepare("
                    UPDATE notification_queue
                    SET status = 'failed', error_message = :err, next_retry_at = :retry
                    WHERE id = :id
                ")->execute([
                    ':err'   => $res['error'] ?? 'WhatsApp delivery failed',
                    ':retry' => $nextRetry,
                    ':id'    => $queueId,
                ]);

                // Log failure
                $this->db->prepare("
                    INSERT INTO notification_logs (queue_id, channel, recipient, event_type, status, error_message)
                    VALUES (:qid, 'whatsapp', :rec, :event, 'failed', :err)
                ")->execute([
                    ':qid'   => $queueId,
                    ':rec'   => $item['recipient'],
                    ':event' => $item['event_type'],
                    ':err'   => $res['error'] ?? 'Delivery failure',
                ]);
            }
        }

        return $processedCount;
    }

    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $val) {
            $template = str_replace('{{' . $key . '}}', (string)$val, $template);
        }
        return $template;
    }
}
