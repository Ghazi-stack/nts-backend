<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;

class OrderPlacedMail extends Mailable
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

        $sid = $storeId ?? ($order->store_id ?? 10);

        $this->store = Store::find($sid);

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
        $orderNumber = is_array($this->order) ? ($this->order['orderNumber'] ?? $this->order['order_number'] ?? '') : ($this->order->order_number ?? '');

        return $this->subject('Your ' . ($this->store?->name ?? 'Store') . ' Order Confirmation (' . $orderNumber . ')')
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