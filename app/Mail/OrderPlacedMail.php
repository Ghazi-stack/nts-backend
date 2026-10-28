<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class OrderPlacedMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $order;
    public $customer;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct($order, $customer, $storeId = null)
    {
        $this->order = $order;
        $this->customer = $customer;

        $sid = $storeId ?? ($order->store_id ?? null);

        if (!$sid) {
            throw new \Exception('Store ID not provided.');
        }

        $this->store = Store::where('legacy_store_id', $sid)->first();

        if (!$this->store) {
            throw new \Exception("Store not found for legacy_store_id: {$sid}");
        }

        $logo = Logo::where('store_id', $sid)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    public function build()
    {
        $orderNumber = is_array($this->order) ? ($this->order['orderNumber'] ?? $this->order['order_number'] ?? '') : ($this->order->order_number ?? '');

        return $this->setStoreSender($this->store)->subject('Your ' . ($this->store?->name ?? 'Store') . ' Order Confirmation (' . $orderNumber . ')')
            ->view('emails.order_placed')
            ->with([
                'order'     => $this->order,
                'customer'  => $this->customer,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
