<?php

namespace App\Mail;

use App\Services\TransactionalEmailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class WelcomeLoginMail extends StoreMailable
{
    use Queueable, SerializesModels;

    /** store_id used by transactional_email_templates (legacy store id) */
    protected $templateStoreId;

    public $userName;
    public $userEmail;
    public $resetLink;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct($userName, $userEmail, $resetLink, $storeId)
    {
        $this->userName = $userName;
        $this->userEmail = $userEmail;
        $this->resetLink = $resetLink;
        $this->templateStoreId = $storeId;

        $this->store = $this->findStoreByLegacyId($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        // Dynamic URL from stores table
        $this->storePath = rtrim($this->store?->base_url ?? '#', '/');
    }

    public function build()
    {
        // Store's dashboard template ("Account Created"), else the default Blade email.
        if ($this->buildFromStoreTemplate($this->store, $this->templateStoreId, 'account_created', function () {
            return TransactionalEmailContext::base($this->store, $this->logoUrl) + [
                'customer' => TransactionalEmailContext::customer(null, $this->userEmail, $this->userName),
                'links'    => ['set_password' => (string) $this->resetLink, 'account' => $this->storePath],
            ];
        })) {
            return $this;
        }
        return $this->setStoreSender($this->store)->subject('Your Login Details - Set Your Password')
            ->view('emails.welcome_login')
            ->with([
                'userName'  => $this->userName,
                'userEmail' => $this->userEmail,
                'resetLink' => $this->resetLink,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
