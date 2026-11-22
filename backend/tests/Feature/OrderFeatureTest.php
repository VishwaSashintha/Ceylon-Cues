<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Feature;

use CeylonCues\Core\Database;
use CeylonCues\Services\Orders\OrderService;
use PHPUnit\Framework\TestCase;

final class OrderFeatureTest extends TestCase
{
    private \PDO $db;
    private OrderService $orders;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->orders = new OrderService();
    }

    public function testTaxInvoiceGeneration(): void
    {
        $stmt = $this->db->query("SELECT id FROM orders LIMIT 1");
        $orderId = $stmt->fetchColumn();

        if (!$orderId) {
            $this->markTestSkipped('No orders found in database to test tax invoice.');
        }

        $invNumber = $this->orders->generateTaxInvoiceNumber((int)$orderId);
        $this->assertMatchesRegularExpression('/^CC-TAX-INV-\d{4}-\d{5}$/', $invNumber);

        $taxData = $this->orders->getTaxInvoiceData((int)$orderId);
        $this->assertSame($invNumber, $taxData['tax_invoice_number']);
        $this->assertArrayHasKey('order', $taxData);
        $this->assertArrayHasKey('items', $taxData);
    }
}
