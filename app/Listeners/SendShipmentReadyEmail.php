<?php

namespace App\Listeners;

use App\Events\ShipmentCreated;
use App\Mail\ShipmentReadyMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendShipmentReadyEmail
{
    public function handle(ShipmentCreated $event)
    {
        $shipment = $event->shipment;
        $order = $shipment->order;

        Log::info("📦 Event: ShipmentCreated triggered for Order ID: {$order->id}");

        if ($order && $order->email) {
            Log::info("📧 Sending email to {$order->email}");
            Mail::to($order->email)->send(new ShipmentReadyMail($shipment));
            Log::info("✅ Email sent successfully");
        } else {
            Log::warning("❌ Email not sent: order or email missing");
        }
    }
}
