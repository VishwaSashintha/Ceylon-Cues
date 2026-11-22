<?php

declare(strict_types=1);

namespace CeylonCues\Services\Support;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Customer Support & Help Desk Service
 */
final class SupportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a new customer support ticket.
     */
    public function createTicket(array $data, ?int $userId = null): array
    {
        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM support_tickets WHERE reference_number LIKE 'CC-TCK-{$year}-%'")->fetchColumn() + 1;
        $refNumber = sprintf('CC-TCK-%s-%06d', $year, $count);
        $uuid = Uuid::v4();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO support_tickets (
                    uuid, reference_number, user_id, order_id, subject, category, priority,
                    status, customer_name, customer_email, customer_phone
                ) VALUES (
                    :uuid, :ref, :uid, :oid, :subj, :cat, :prio,
                    'open', :cname, :cemail, :cphone
                )
            ");

            $stmt->execute([
                ':uuid'   => $uuid,
                ':ref'    => $refNumber,
                ':uid'    => $userId,
                ':oid'    => $data['order_id'] ?? null,
                ':subj'   => trim($data['subject']),
                ':cat'    => $data['category'] ?? 'general',
                ':prio'   => $data['priority'] ?? 'medium',
                ':cname'  => trim($data['customer_name'] ?? 'Customer'),
                ':cemail' => !empty($data['customer_email']) ? trim($data['customer_email']) : null,
                ':cphone' => !empty($data['customer_phone']) ? trim($data['customer_phone']) : null,
            ]);

            $ticketId = (int)$this->db->lastInsertId();

            // Initial message
            if (!empty($data['message'])) {
                $this->db->prepare("
                    INSERT INTO support_messages (ticket_id, user_id, sender_name, message, is_internal)
                    VALUES (:tid, :uid, :name, :msg, 0)
                ")->execute([
                    ':tid'  => $ticketId,
                    ':uid'  => $userId,
                    ':name' => trim($data['customer_name'] ?? 'Customer'),
                    ':msg'  => trim($data['message']),
                ]);
            }

            $this->db->commit();
            return $this->getTicket($ticketId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Add a message/reply to a ticket.
     */
    public function replyToTicket(int $ticketId, string $message, string $senderName, ?int $userId = null, bool $isInternal = false): array
    {
        $this->db->prepare("
            INSERT INTO support_messages (ticket_id, user_id, sender_name, message, is_internal)
            VALUES (:tid, :uid, :name, :msg, :internal)
        ")->execute([
            ':tid'      => $ticketId,
            ':uid'      => $userId,
            ':name'     => $senderName,
            ':msg'      => trim($message),
            ':internal' => $isInternal ? 1 : 0,
        ]);

        if (!$isInternal) {
            $this->db->prepare("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $ticketId]);
        }

        return $this->getTicket($ticketId);
    }

    public function getTicket(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, o.reference_number as order_reference
            FROM support_tickets t
            LEFT JOIN orders o ON t.order_id = o.id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return null;
        }

        $msgStmt = $this->db->prepare("SELECT * FROM support_messages WHERE ticket_id = :tid ORDER BY created_at ASC");
        $msgStmt->execute([':tid' => $id]);
        $ticket['messages'] = $msgStmt->fetchAll();

        return $ticket;
    }

    public function listTickets(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :uid';
            $params[':uid'] = $filters['user_id'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT * FROM support_tickets WHERE {$whereSql} ORDER BY created_at DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
