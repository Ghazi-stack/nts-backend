<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Newsletter;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class NewsletterSubscribedMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $newsletter;
    public $logoUrl;
    public $storePath;
    public $store;
    public $isAdmin;

    public function __construct(Newsletter $newsletter, $isAdmin = false)
    {
        $this->newsletter = $newsletter;
        $this->isAdmin = $isAdmin;

        $storeId = $newsletter->store_id ?? null;

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
        $subject = $this->isAdmin
            ? 'New Newsletter Subscription - ' . ($this->store?->name ?? 'Store')
            : 'Thank You For Subscribing to ' . ($this->store?->name ?? 'Our Store');

        return $this->setStoreSender($this->store)->subject($subject)
            ->view('emails.newsletter-subscribed')
            ->with([
                'newsletter' => $this->newsletter,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
                'isAdmin'    => $this->isAdmin,
            ]);
    }
}
