<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Feature;

use CeylonCues\Core\Database;
use CeylonCues\Services\Pos\PosService;
use PHPUnit\Framework\TestCase;

final class PosFeatureTest extends TestCase
{
    private \PDO $db;
    private PosService $pos;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->pos = new PosService();
    }

    public function testCashFloatAndMovement(): void
    {
        $stmt = $this->db->query("SELECT id FROM pos_sessions LIMIT 1");
        $sessionId = $stmt->fetchColumn();

        if (!$sessionId) {
            $this->markTestSkipped('No POS sessions found in database.');
        }

        $float = $this->pos->getCashFloat((int)$sessionId);
        $this->assertArrayHasKey('opening_float', $float);
        $this->assertArrayHasKey('total_cash_in', $float);
        $this->assertArrayHasKey('total_cash_out', $float);

        $this->pos->recordCashMovement((int)$sessionId, 'in', 500.00, 'Test Float Cash In');
        $updatedFloat = $this->pos->getCashFloat((int)$sessionId);
        $this->assertGreaterThanOrEqual($float['total_cash_in'], $updatedFloat['total_cash_in']);
    }
}
