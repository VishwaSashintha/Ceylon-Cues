<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Settings;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Settings\BackupService;
use CeylonCues\Services\Settings\SettingService;
use Exception;

/**
 * Business Settings REST API Controller (Owner Only)
 */
final class SettingController
{
    private SettingService $settings;
    private BackupService $backup;

    public function __construct()
    {
        $this->settings = new SettingService();
        $this->backup = new BackupService();
    }

    /**
     * GET /api/v1/settings/public (Public settings: name, contact, currency)
     */
    public function getPublic(Request $request): void
    {
        $all = $this->settings->getAllSettings(true);
        Response::json($all, 'Public business settings retrieved');
    }

    /**
     * GET /api/v1/settings (ERP Owner)
     */
    public function index(Request $request): void
    {
        $all = $this->settings->getAllSettings(false);
        Response::json($all, 'Business settings retrieved');
    }

    /**
     * PUT /api/v1/settings (ERP Owner)
     */
    public function update(Request $request): void
    {
        $data = $request->getBody();
        foreach ($data as $key => $val) {
            $this->settings->set($key, (string)$val);
        }
        Response::json(null, 'Business settings updated');
    }

    /**
     * POST /api/v1/settings/backup (ERP Owner)
     */
    public function createBackup(Request $request): void
    {
        try {
            $result = $this->backup->createBackup();
            Response::json($result, 'Database backup dump created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
