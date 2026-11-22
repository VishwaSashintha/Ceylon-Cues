<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments;

use CeylonCues\Core\Database;
use PDO;
use RuntimeException;

final class CodService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function listRemittances(): array
    {
        return $this->db->query("
            SELECT * FROM cod_remittances ORDER BY remittance_date DESC, created_at DESC
        ")->fetchAll();
    }

    public function createRemittance(array $data): array
    {
        $orderIds       = $data['order_ids'] ?? [];
        $amount         = (float)($data['amount'] ?? 0);
        $remittanceDate = $data['remittance_date'] ?? date('Y-m-d');
        $notes          = $data['notes'] ?? null;

        if (empty($orderIds)) {
            throw new RuntimeException('At least one order ID is required');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cod_remittances (amount, remittance_date, notes, order_count, created_at)
                VALUES (:amount, :rdate, :notes, :count, NOW())
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':rdate'  => $remittanceDate,
                ':notes'  => $notes,
                ':count'  => count($orderIds),
            ]);

            $remittanceId = (int)$this->db->lastInsertId();

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $updateStmt   = $this->db->prepare("
                UPDATE orders SET cod_remitted = 1, cod_remittance_id = {$remittanceId}
                WHERE id IN ({$placeholders})
            ");
            $updateStmt->execute(array_values($orderIds));

            $this->db->commit();

            return $this->getRemittance($remittanceId) ?? [];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getRemittance(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cod_remittances WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $orderStmt = $this->db->prepare("
            SELECT id, reference_number, customer_name, total_amount FROM orders
            WHERE cod_remittance_id = :rid
        ");
        $orderStmt->execute([':rid' => $id]);
        $row['orders'] = $orderStmt->fetchAll();

        return $row;
    }
}
