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

    public function getSalesReport(string $start, string $end, ?string $channel = null): array
    {
        $where = ["o.payment_status = 'paid'", "o.created_at BETWEEN :start AND :end"];
        $params = [':start' => "{$start} 00:00:00", ':end' => "{$end} 23:59:59"];

        if ($channel) {
            $where[] = "o.channel = :chan";
            $params[':chan'] = $channel;
        }

        $whereClause = implode(' AND ', $where);

        $stmtTotals = $this->db->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(o.total_amount), 0) as total_revenue,
                COALESCE(SUM(o.subtotal), 0) as subtotal,
                COALESCE(SUM(o.discount_amount), 0) as total_discounts,
                COALESCE(SUM(o.delivery_charge), 0) as total_delivery_charges
            FROM orders o
            WHERE {$whereClause}
        ");
        $stmtTotals->execute($params);
        $totals = $stmtTotals->fetch();

        $stmtDaily = $this->db->prepare("
            SELECT 
                DATE(o.created_at) as date,
                COUNT(*) as orders_count,
                COALESCE(SUM(o.total_amount), 0) as revenue
            FROM orders o
            WHERE {$whereClause}
            GROUP BY DATE(o.created_at)
            ORDER BY date ASC
        ");
        $stmtDaily->execute($params);
        $daily = $stmtDaily->fetchAll();

        $stmtChannels = $this->db->prepare("
            SELECT 
                o.channel,
                COUNT(*) as orders_count,
                COALESCE(SUM(o.total_amount), 0) as revenue
            FROM orders o
            WHERE {$whereClause}
            GROUP BY o.channel
        ");
        $stmtChannels->execute($params);
        $channels = $stmtChannels->fetchAll();

        $orderCount = (int)$totals['total_orders'];
        $rev = (float)$totals['total_revenue'];
        $aov = $orderCount > 0 ? round($rev / $orderCount, 2) : 0.00;

        return [
            'period'               => ['start' => $start, 'end' => $end],
            'total_revenue'        => $rev,
            'total_orders'         => $orderCount,
            'subtotal'             => (float)$totals['subtotal'],
            'total_discounts'      => (float)$totals['total_discounts'],
            'total_shipping'       => (float)$totals['total_delivery_charges'],
            'average_order_value'  => $aov,
            'daily_breakdown'      => $daily,
            'channel_breakdown'    => $channels,
            'currency'             => 'LKR',
        ];
    }

    public function getProductSalesReport(string $start, string $end, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.id as product_id,
                p.name,
                p.sku,
                c.name as category_name,
                SUM(oi.quantity) as total_units_sold,
                SUM(oi.total_price) as total_revenue,
                ROUND(AVG(oi.unit_price), 2) as average_price
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE o.payment_status = 'paid'
              AND o.created_at BETWEEN :start AND :end
            GROUP BY p.id
            ORDER BY total_units_sold DESC, total_revenue DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':start', "{$start} 00:00:00");
        $stmt->bindValue(':end', "{$end} 23:59:59");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        return [
            'period'   => ['start' => $start, 'end' => $end],
            'products' => $items,
        ];
    }

    public function getInventoryReport(): array
    {
        $stmtLocations = $this->db->query("
            SELECT 
                l.id, l.name, l.type,
                COUNT(DISTINCT s.product_id) as total_skus,
                COALESCE(SUM(s.quantity), 0) as total_quantity,
                COALESCE(SUM(s.reserved_quantity), 0) as total_reserved,
                COALESCE(SUM(s.quantity * p.cost_price), 0) as cost_valuation,
                COALESCE(SUM(s.quantity * p.retail_price), 0) as retail_valuation
            FROM inventory_locations l
            LEFT JOIN inventory_stock s ON l.id = s.location_id
            LEFT JOIN products p ON s.product_id = p.id AND p.deleted_at IS NULL
            GROUP BY l.id
        ");
        $locations = $stmtLocations->fetchAll();

        $stmtLowStock = $this->db->query("
            SELECT 
                p.id, p.name, p.sku, l.name as location_name,
                s.quantity, s.reorder_level
            FROM inventory_stock s
            JOIN products p ON s.product_id = p.id
            JOIN inventory_locations l ON s.location_id = l.id
            WHERE s.quantity <= s.reorder_level AND p.deleted_at IS NULL
            ORDER BY s.quantity ASC
        ");
        $lowStock = $stmtLowStock->fetchAll();

        return [
            'locations' => $locations,
            'low_stock' => $lowStock,
        ];
    }

    public function getPurchasingReport(string $start, string $end): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.id as supplier_id,
                s.company_name,
                COUNT(po.id) as total_orders,
                COALESCE(SUM(po.total_amount), 0) as total_spend,
                COALESCE(SUM(CASE WHEN po.status = 'received' THEN po.total_amount ELSE 0 END), 0) as received_amount
            FROM suppliers s
            LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.created_at BETWEEN :start AND :end
            GROUP BY s.id
            ORDER BY total_spend DESC
        ");
        $stmt->execute([':start' => "{$start} 00:00:00", ':end' => "{$end} 23:59:59"]);
        $suppliers = $stmt->fetchAll();

        return [
            'period'    => ['start' => $start, 'end' => $end],
            'suppliers' => $suppliers,
        ];
    }

    public function getCustomerReport(): array
    {
        $totalCustomers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND deleted_at IS NULL")->fetchColumn();
        $totalWholesale = (int)$this->db->query("SELECT COUNT(*) FROM wholesale_enquiries WHERE status = 'accepted'")->fetchColumn();

        $stmtTop = $this->db->query("
            SELECT 
                u.id, u.first_name, u.last_name, u.email, u.phone,
                COUNT(o.id) as orders_count,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total_amount ELSE 0 END), 0) as total_spent
            FROM users u
            JOIN orders o ON u.id = o.user_id
            WHERE u.role = 'customer'
            GROUP BY u.id
            ORDER BY total_spent DESC
            LIMIT 10
        ");
        $topCustomers = $stmtTop->fetchAll();

        return [
            'total_customers' => $totalCustomers,
            'total_wholesale' => $totalWholesale,
            'top_customers'   => $topCustomers,
        ];
    }

    public function getPosReport(string $start, string $end): array
    {
        $stmtCashiers = $this->db->prepare("
            SELECT 
                u.id as cashier_id,
                u.first_name,
                u.last_name,
                COUNT(pt.id) as transactions_count,
                COALESCE(SUM(o.total_amount), 0) as total_sales,
                COALESCE(SUM(CASE WHEN pt.payment_method = 'cash' THEN o.total_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN pt.payment_method = 'card' THEN o.total_amount ELSE 0 END), 0) as card_sales
            FROM pos_transactions pt
            JOIN users u ON pt.cashier_id = u.id
            JOIN orders o ON pt.order_id = o.id
            WHERE pt.created_at BETWEEN :start AND :end
            GROUP BY u.id
        ");
        $stmtCashiers->execute([':start' => "{$start} 00:00:00", ':end' => "{$end} 23:59:59"]);
        $cashiers = $stmtCashiers->fetchAll();

        return [
            'period'   => ['start' => $start, 'end' => $end],
            'cashiers' => $cashiers,
        ];
    }
}
