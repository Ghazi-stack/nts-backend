<?php

namespace App\Mail;

use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ShipmentReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public $shipment;

    public function __construct(Shipment $shipment)
    {
        $this->shipment = $shipment;
    }

    public function build()
    {
        return $this->subject('Your Shipment is Ready')
                    ->view('emails.shipment-ready')
                    ->with([
                        'shipment' => $this->shipment,
                        'order' => $this->shipment->order,
                    ]);
    }
}
