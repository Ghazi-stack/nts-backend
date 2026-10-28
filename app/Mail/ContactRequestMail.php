<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\ContactRequest;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class ContactRequestMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $contactRequest;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(ContactRequest $contactRequest)
    {
        $this->contactRequest = $contactRequest;

        $storeId = $contactRequest->store_id ?? null;

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
        return $this->setStoreSender($this->store)->subject('New Contact Request from ' . ($this->contactRequest->full_name ?? 'Customer'))
            ->view('emails.contact-request')
            ->with([
                'contact'   => $this->contactRequest,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
