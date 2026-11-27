<?php

namespace App\Mail;

use App\Services\TransactionalEmailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class GuestAccountMail extends StoreMailable
{
    use Queueable, SerializesModels;

    /** store_id used by transactional_email_templates (legacy store id) */
    protected $templateStoreId;

    public $userName;
    public $userEmail;
    public $registerLink;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct($userName, $userEmail, $registerLink, $storeId)
    {
        $this->userName = $userName;
        $this->userEmail = $userEmail;
        $this->registerLink = $registerLink;
        $this->templateStoreId = $storeId;

        $this->store = $this->findStoreByLegacyId($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        $this->storePath = rtrim($this->store?->base_url ?? '#', '/');
    }

    public function build()
    {
        // Store's dashboard template ("Guest Account Created"), else the default Blade email.
        if ($this->buildFromStoreTemplate($this->store, $this->templateStoreId, 'guest_account_created', function () {
            return TransactionalEmailContext::base($this->store, $this->logoUrl) + [
                'customer' => TransactionalEmailContext::customer($this->userName, $this->userEmail),
                'links'    => ['register' => (string) $this->registerLink, 'account' => $this->storePath],
            ];
        })) {
            return $this;
        }
        $storeName = $this->store?->name ?? 'Store';

        return $this->setStoreSender($this->store)->subject('Create Your Account - ' . $storeName)
            ->view('emails.guest-account')
            ->with([
                'userName'     => $this->userName,
                'userEmail'    => $this->userEmail,
                'registerLink' => $this->registerLink,
                'logoUrl'      => $this->logoUrl,
                'storePath'    => $this->storePath,
                'store'        => $this->store,
            ]);
    }
}
