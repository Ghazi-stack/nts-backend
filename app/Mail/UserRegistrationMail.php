<?php

namespace App\Mail;

use App\Services\TransactionalEmailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Mail\StoreMailable;
use App\Models\Logo;

class UserRegistrationMail extends StoreMailable
{
    use Queueable, SerializesModels;

    /** store_id used by transactional_email_templates (legacy store id) */
    protected $templateStoreId;

    public $user;
    public $store;
    public $logoUrl;
    public $storePath;

    public function __construct($user, $storeId)
    {
        $this->user = $user;
        $this->templateStoreId = $storeId;

        $this->store = $this->findStoreByLegacyId($storeId);

        // Logo from logos table
        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        // Dynamic Store URL
        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    public function build()
    {
        // Customers only: store's dashboard template ("Account Created"), else
        // the default Blade email. Store owners registering a store keep the Blade email.
        if ((int) data_get($this->user, 'userRole') === 2
            && $this->buildFromStoreTemplate($this->store, $this->templateStoreId, 'account_created', function () {
                return TransactionalEmailContext::base($this->store, $this->logoUrl) + [
                    'customer' => TransactionalEmailContext::customer(null, data_get($this->user, 'email'), data_get($this->user, 'name')),
                    'links'    => ['set_password' => '', 'account' => $this->storePath],
                ];
            })) {
            return $this;
        }
        return $this->setStoreSender($this->store)->subject('Thanks for registering at ' . ($this->store?->name ?? 'Our Store'))
            ->view('emails.user_registration')
            ->with([
                'user'      => $this->user,
                'store'     => $this->store,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
            ]);
    }
}
