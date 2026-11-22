<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Notifications;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Notifications\NotificationQueueService;

/**
 * Notifications Management Controller (ERP)
 */
final class NotificationController
{
    private NotificationQueueService $queue;

    public function __construct()
    {
        $this->queue = new NotificationQueueService();
    }

    /**
     * POST /api/v1/notifications/process-queue (Trigger worker manually or via cron webhook)
     */
    public function process(Request $request): void
    {
        $batch = (int)$request->getParam('batch', 20);
        $count = $this->queue->processPendingQueue($batch);
        Response::json(['processed' => $count], "Processed {$count} queued notification(s)");
    }

    /**
     * POST /api/v1/notifications/test (Owner WhatsApp test alert)
     */
    public function testAlert(Request $request): void
    {
        $phone = $this->queue->getOwnerPhoneNumber();
        $id = $this->queue->queueEvent('order.created', [
            'order_ref'       => 'CC-ORD-TEST-000001',
            'customer_name'   => 'Test Customer',
            'customer_phone'  => '+94771234567',
            'item_count'      => 1,
            'subtotal'        => '45,000.00',
            'delivery_charge' => '350.00',
            'total_amount'    => '45,350.00',
            'payment_method'  => 'Bank Transfer',
            'payment_status'  => 'Awaiting Verification',
            'delivery_method' => 'Home Delivery',
        ], $phone, 10);

        Response::json(['queue_id' => $id, 'recipient' => $phone], 'Test WhatsApp alert queued successfully');
    }
}
