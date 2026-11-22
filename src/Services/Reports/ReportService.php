<?php

declare(strict_types=1);

namespace CeylonCues\Services\Reports;

use CeylonCues\Core\Database;
use PDO;

/**
 * Business Reporting & Financial Intelligence Service
 *
 * Computes sales summaries, inventory valuations, profit and loss,
 * and cashier audits across channels.
 */
final class ReportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get high-level executive dashboard metrics for the owner.
     */
    public function getExecutiveMetrics(): array
    {
        // Total revenues by channel
        $stmtRev = $this->db->query("
            SELECT 
                channel,
                COUNT(*) as order_count,
                COALESCE(SUM(total_amount), 0) as total_revenue
            FROM orders
            WHERE payment_status = 'paid'
            GROUP BY channel
        ");
        $channels = $stmtRev->fetchAll();

        // Total inventory physical valuation
        $stmtVal = $this->db->query("
            SELECT 
                COALESCE(SUM(s.quantity * p.cost_price), 0) as cost_valuation,
                COALESCE(SUM(s.quantity * p.retail_price), 0) as retail_valuation,
                COALESCE(SUM(s.quantity), 0) as total_units
            FROM inventory_stock s
            JOIN products p ON s.product_id = p.id
            WHERE p.deleted_at IS NULL
        ");
        $inventory = $stmtVal->fetch();

        // Total expenses
        $totalExpenses = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();

        // Low stock count
        $lowStockCount = (int)$this->db->query("
            SELECT COUNT(*) FROM inventory_stock s
            JOIN products p ON s.product_id = p.id
            WHERE s.quantity <= s.reorder_level AND p.deleted_at IS NULL
        ")->fetchColumn();

        // Pending bank transfer verification count
        $pendingTransfers = (int)$this->db->query("SELECT COUNT(*) FROM bank_transfer_submissions WHERE verification_status = 'pending'")->fetchColumn();

        return [
            'revenue_by_channel' => $channels,
            'inventory_valuation' => [
                'cost_value'   => (float)$inventory['cost_valuation'],
                'retail_value' => (float)$inventory['retail_valuation'],
                'total_units'  => (int)$inventory['total_units'],
            ],
            'total_expenses'     => $totalExpenses,
            'low_stock_alerts'   => $lowStockCount,
            'pending_transfers'  => $pendingTransfers,
            'currency'           => 'LKR',
        ];
    }

    /**
     * Profit & Loss Summary for a date range.
     */
    public function getProfitAndLoss(string $startDate, string $endDate): array
    {
        // Revenue and Cost of Goods Sold (COGS) from order items
        $stmtSales = $this->db->prepare("
            SELECT 
                COALESCE(SUM(oi.total_price), 0) as total_sales,
                COALESCE(SUM(oi.quantity * oi.cost_price_snapshot), 0) as total_cogs,
                COALESCE(SUM(o.discount_amount), 0) as total_discounts,
                COALESCE(SUM(o.delivery_charge), 0) as total_delivery_collected
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            WHERE o.payment_status = 'paid'
              AND o.created_at BETWEEN :start AND :end
        ");
        $stmtSales->execute([':start' => "{$startDate} 00:00:00", ':end' => "{$endDate} 23:59:59"]);
        $sales = $stmtSales->fetch();

        // Expenses
        $stmtExp = $this->db->prepare("
            SELECT category, COALESCE(SUM(amount), 0) as amount
            FROM expenses
            WHERE expense_date BETWEEN :start AND :end
            GROUP BY category
        ");
        $stmtExp->execute([':start' => $startDate, ':end' => $endDate]);
        $expensesByCategory = $stmtExp->fetchAll();

        $totalExpenses = 0.00;
        foreach ($expensesByCategory as $exp) {
            $totalExpenses += (float)$exp['amount'];
        }

        $grossRevenue = (float)$sales['total_sales'];
        $cogs = (float)$sales['total_cogs'];
        $grossProfit = $grossRevenue - $cogs;
        $netProfit = $grossProfit - $totalExpenses;

        return [
            'period'               => ['start' => $startDate, 'end' => $endDate],
            'gross_revenue'        => $grossRevenue,
            'cogs'                 => $cogs,
            'gross_profit'         => $grossProfit,
            'gross_margin_percent' => $grossRevenue > 0 ? round(($grossProfit / $grossRevenue) * 100, 2) : 0,
            'operating_expenses'   => $totalExpenses,
            'expense_breakdown'    => $expensesByCategory,
            'net_profit'           => $netProfit,
            'net_margin_percent'   => $grossRevenue > 0 ? round(($netProfit / $grossRevenue) * 100, 2) : 0,
            'currency'             => 'LKR',
        ];
    }
}
