<?php

namespace App\Events;

use App\Models\Shipment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class ShipmentCreated
{
    use Dispatchable, SerializesModels;

    public $shipment;

    public function __construct(Shipment $shipment)
    {
        $this->shipment = $shipment;
    }
}
