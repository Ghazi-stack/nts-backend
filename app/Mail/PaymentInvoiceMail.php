<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class PaymentInvoiceMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $order;
    public $pdf;
    public $logoBase64;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct($order, $pdf, $logoBase64, $storeId = null)
    {
        $this->order = $order;
        $this->pdf = $pdf;
        $this->logoBase64 = $logoBase64;

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
        $storeName = $this->store?->name ?? 'Store';

        return $this->setStoreSender($this->store)->subject('Your Payment Invoice from ' . $storeName)
            ->view('emails.payment_invoice')
            ->with([
                'order'      => $this->order,
                'logoBase64' => $this->logoBase64,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
            ])
            ->attachData($this->pdf->output(), 'invoice-' . $this->order->order_number . '.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
