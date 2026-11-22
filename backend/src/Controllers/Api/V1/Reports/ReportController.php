<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Reports;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Reports\ReportService;

/**
 * Financial & Business Reports REST API Controller
 */
final class ReportController
{
    private ReportService $reports;

    public function __construct()
    {
        $this->reports = new ReportService();
    }

    /**
     * GET /api/v1/reports/executive-metrics (Owner/Finance)
     */
    public function metrics(Request $request): void
    {
        $metrics = $this->reports->getExecutiveMetrics();
        Response::json($metrics, 'Executive business metrics retrieved');
    }

    /**
     * GET /api/v1/reports/profit-and-loss (Owner/Finance)
     */
    public function profitAndLoss(Request $request): void
    {
        $start = $request->getParam('start_date', date('Y-m-01'));
        $end = $request->getParam('end_date', date('Y-m-d'));

        $pnl = $this->reports->getProfitAndLoss($start, $end);
        Response::json($pnl, 'Profit & Loss statement generated');
    }

    public function sales(Request $request): void
    {
        $start = $request->getParam('start_date', date('Y-m-01'));
        $end = $request->getParam('end_date', date('Y-m-d'));
        $channel = $request->getParam('channel');

        $report = $this->reports->getSalesReport($start, $end, $channel);
        Response::json($report, 'Sales report generated');
    }

    public function productSales(Request $request): void
    {
        $start = $request->getParam('start_date', date('Y-m-01'));
        $end = $request->getParam('end_date', date('Y-m-d'));
        $limit = min(100, max(1, (int)$request->getParam('limit', 20)));

        $report = $this->reports->getProductSalesReport($start, $end, $limit);
        Response::json($report, 'Product sales performance report generated');
    }

    public function inventory(Request $request): void
    {
        $report = $this->reports->getInventoryReport();
        Response::json($report, 'Inventory valuation and stock report generated');
    }

    public function purchases(Request $request): void
    {
        $start = $request->getParam('start_date', date('Y-m-01'));
        $end = $request->getParam('end_date', date('Y-m-d'));

        $report = $this->reports->getPurchasingReport($start, $end);
        Response::json($report, 'Purchasing and supplier spend report generated');
    }

    public function customers(Request $request): void
    {
        $report = $this->reports->getCustomerReport();
        Response::json($report, 'Customer lifetime value and accounts report generated');
    }

    public function pos(Request $request): void
    {
        $start = $request->getParam('start_date', date('Y-m-01'));
        $end = $request->getParam('end_date', date('Y-m-d'));

        $report = $this->reports->getPosReport($start, $end);
        Response::json($report, 'POS register audit report generated');
    }
}
