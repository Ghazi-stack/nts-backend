<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class ShipmentTrackingMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $order;
    public $shipment;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(Order $order, $shipment)
    {
        $this->order = $order;
        $this->shipment = $shipment;

        $storeId = $order->store_id ?? null;

        $this->store = $this->findStoreByLegacyId($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    public function build()
    {
        return $this->setStoreSender($this->store)->subject(
            'Tracking Number Updated - Order #' . $this->order->order_number
        )
            ->view('emails.tracking-updated')
            ->with([
                'order'      => $this->order,
                'shipment'   => $this->shipment,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
            ]);
    }
}
