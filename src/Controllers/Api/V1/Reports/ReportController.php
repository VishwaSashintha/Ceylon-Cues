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
}
