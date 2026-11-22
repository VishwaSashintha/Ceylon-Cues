<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Erp;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Reports\ReportService;

/**
 * ERP Dashboard Controller
 */
final class DashboardController
{
    private ReportService $reports;

    public function __construct()
    {
        $this->reports = new ReportService();
    }

    /**
     * GET /api/v1/erp/dashboard
     */
    public function index(Request $request): void
    {
        $data = $this->reports->getExecutiveMetrics();
        Response::json($data, 'ERP Dashboard overview retrieved');
    }
}
