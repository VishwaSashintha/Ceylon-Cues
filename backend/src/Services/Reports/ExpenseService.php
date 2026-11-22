<?php

declare(strict_types=1);

namespace CeylonCues\Services\Reports;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Operating Expense Management Service
 */
final class ExpenseService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function recordExpense(array $data, int $createdBy): array
    {
        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE reference_number LIKE 'CC-EXP-{$year}-%'")->fetchColumn() + 1;
        $refNumber = sprintf('CC-EXP-%s-%06d', $year, $count);
        $uuid = Uuid::v4();

        $stmt = $this->db->prepare("
            INSERT INTO expenses (
                uuid, reference_number, category, description, amount, expense_date, receipt_path, notes, created_by
            ) VALUES (
                :uuid, :ref, :cat, :desc, :amount, :edate, :rpath, :notes, :by
            )
        ");

        $stmt->execute([
            ':uuid'   => $uuid,
            ':ref'    => $refNumber,
            ':cat'    => $data['category'],
            ':desc'   => trim($data['description']),
            ':amount' => (float)$data['amount'],
            ':edate'  => $data['expense_date'] ?? date('Y-m-d'),
            ':rpath'  => $data['receipt_path'] ?? null,
            ':notes'  => $data['notes'] ?? null,
            ':by'     => $createdBy,
        ]);

        return $this->getExpense((int)$this->db->lastInsertId());
    }

    public function getExpense(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM expenses WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listExpenses(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = :cat';
            $params[':cat'] = $filters['category'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'expense_date >= :from';
            $params[':from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'expense_date <= :to';
            $params[':to'] = $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT * FROM expenses WHERE {$whereSql} ORDER BY expense_date DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
