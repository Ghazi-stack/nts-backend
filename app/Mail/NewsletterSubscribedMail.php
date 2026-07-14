<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Newsletter;
use App\Models\Store;
use App\Models\Logo;

class NewsletterSubscribedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $newsletter;
    public $logoUrl;
    public $storePath;
    public $store;
    public $isAdmin;

    /**
     * Create a new message instance.
     */
    public function __construct(Newsletter $newsletter, $isAdmin = false)
    {
        $this->newsletter = $newsletter;
        $this->isAdmin = $isAdmin;

        $storeId = $newsletter->store_id ?? 10;

        $this->store = Store::find($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    /**
     * Build the message.
     */
    public function build()
    {
        if ($this->isAdmin) {

            return $this->subject(
                'New Newsletter Subscription - ' . ($this->store?->name ?? 'Store')
            )
                ->view('emails.newsletter-admin')
                ->with([
                    'newsletter' => $this->newsletter,
                    'logoUrl'    => $this->logoUrl,
                    'storePath'  => $this->storePath,
                    'store'      => $this->store,
                ]);
        }

        return $this->subject(
            'Thank you for subscribing to ' . ($this->store?->name ?? 'Our Store')
        )
            ->view('emails.newsletter-subscribed')
            ->with([
                'newsletter' => $this->newsletter,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
            ]);
    }
}
