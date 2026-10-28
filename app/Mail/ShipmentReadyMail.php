<?php

namespace App\Mail;

use App\Models\Shipment;
use App\Models\Store;
use App\Models\Logo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Mail\StoreMailable;

class ShipmentReadyMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $shipment;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(Shipment $shipment, $storeId = null)
    {
        $this->shipment = $shipment;

        $sid = $storeId ?? ($shipment->order->store_id ?? null);

        $this->store = $this->findStoreByLegacyId($sid);

        $logo = Logo::where('store_id', $sid)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if ($this->store && !empty($this->store->base_url)) {
            $this->storePath = rtrim($this->store->base_url, '/');
        } else {
            $this->storePath = '#';
        }
    }

    public function build()
    {
        $storeName = $this->store?->name ?? 'Store';

        return $this->setStoreSender($this->store)->subject('Your ' . $storeName . ' Order is Ready to Ship')
            ->view('emails.shipment-ready')
            ->with([
                'shipment'  => $this->shipment,
                'order'     => $this->shipment->order,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
