<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;

class OrderStatusUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $newStatus;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct($order, $newStatus, $storeId = null)
    {
        $this->order = $order;
        $this->newStatus = $newStatus;

        $sid = $storeId ?? ($order->store_id ?? 10);

        $this->store = Store::find($sid);

        $logo = Logo::where('store_id', $sid)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        $storeUrls = [
            4  => 'https://new-town-spares.vercel.app',
            10 => 'https://server-blink.vercel.app',
        ];

        if ($this->store && !empty($this->store->base_url)) {
            $this->storePath = rtrim($this->store->base_url, '/');
        } else {
            $this->storePath = $storeUrls[$sid] ?? 'https://server-blink.vercel.app';
        }
    }

    public function build()
    {
        $orderNumber = $this->order->order_number ?? '';

        return $this->subject('Order #' . $orderNumber . ' Status Updated to ' . $this->newStatus . ' - ' . ($this->store?->name ?? 'Store'))
            ->view('emails.order-status-updated')
            ->with([
                'order'     => $this->order,
                'newStatus' => $this->newStatus,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
