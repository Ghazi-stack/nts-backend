<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\BulkInquiry;
use App\Models\Store;
use App\Models\Logo;

class BulkInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public $bulkInquiry;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(BulkInquiry $bulkInquiry)
    {
        $this->bulkInquiry = $bulkInquiry;

        $storeId = $bulkInquiry->store_id ?? 10;
        $this->store = Store::find($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        $storeUrls = [
            4  => 'https://new-town-spares.vercel.app',
            10 => 'https://server-blink.vercel.app',
        ];
        $this->storePath = $storeUrls[$storeId] ?? ($this->store?->base_url ?? '#');
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('New Bulk Inquiry from ' . ($this->bulkInquiry->full_name ?? 'Customer'))
            ->view('emails.bulk-inquiry')
            ->with([
                'inquiry'   => $this->bulkInquiry,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
