<?php

namespace App\Listeners;

use App\Events\ShipmentCreated;
use App\Mail\ShipmentReadyMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class SendShipmentReadyEmail
{
    public function handle(ShipmentCreated $event)
    {
        $shipment = $event->shipment;
        $order = $shipment->order;

        try {
            if ($order && $order->email) {
                Mail::to($order->email)->send(new ShipmentReadyMail($shipment));
                Cache::put('shipment_email_status_' . $shipment->id, 'success', now()->addMinutes(1));
            } else {
                Cache::put('shipment_email_status_' . $shipment->id, 'no_email', now()->addMinutes(1));
            }
        } catch (\Exception $e) {
            Cache::put('shipment_email_status_' . $shipment->id, 'fail', now()->addMinutes(1));
        }
    }
}
