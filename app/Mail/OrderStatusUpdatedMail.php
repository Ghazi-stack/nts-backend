<?php

namespace App\Mail;

use App\Services\TransactionalEmailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class OrderStatusUpdatedMail extends StoreMailable
{
    use Queueable, SerializesModels;

    /** store_id used by transactional_email_templates (legacy store id) */
    protected $templateStoreId;

    public $order;
    public $newStatus;
    public $logoUrl;
    public $storePath;
    public $store;
    public $shipment;   // ← naya

    public function __construct($order, $newStatus, $storeId = null, $shipment = null)
    {
        $this->order = $order;
        $this->newStatus = $newStatus;
        $this->shipment = $shipment;

        $sid = $storeId ?? ($order->store_id ?? null);
        $this->templateStoreId = $sid;

        $this->store = $this->findStoreByLegacyId($sid);

        $logo = Logo::where('store_id', $sid)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    /**
     * Which dashboard template controls the email for this status.
     * "Awaiting Pickup" uses "Order ready for pickup", like BigCommerce.
     */
    public static function templateKeyFor(string $status): string
    {
        return $status === 'Awaiting Pickup' ? 'order_ready_for_pickup' : 'order_status_update';
    }

    public function build()
    {
        // Store's dashboard template ("Order Status Update", or "Order ready for
        // pickup" for Awaiting Pickup), else the default Blade email.
        if ($this->buildFromStoreTemplate($this->store, $this->templateStoreId, self::templateKeyFor((string) $this->newStatus), function () {
            $order = TransactionalEmailContext::order($this->order);
            $billing = $order['billing_address'] ?? [];

            return TransactionalEmailContext::base($this->store, $this->logoUrl) + [
                'order'    => $order,
                'status'   => TransactionalEmailContext::status((string) $this->newStatus),
                'shipment' => TransactionalEmailContext::shipment($this->shipment),
                'customer' => TransactionalEmailContext::customer(
                    $billing['first_name'] ?? null,
                    $billing['email'] ?? data_get($this->order, 'email'),
                    $billing['full_name'] ?? null
                ),
                'links'    => ['order_status' => $this->storePath, 'account' => $this->storePath],
            ];
        })) {
            return $this;
        }
        $orderNumber = $this->order->order_number ?? '';

        return $this->setStoreSender($this->store)->subject(
            'Order #' . $orderNumber . ' Status Updated to ' . $this->newStatus . ' - ' . ($this->store?->name ?? 'Store')
        )
            ->view('emails.order-status-updated')
            ->with([
                'order'      => $this->order,
                'newStatus'  => $this->newStatus,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
                'shipment'   => $this->shipment,   // ← naya
            ]);
    }
}
