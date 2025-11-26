<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\BulkInquiry;

class BulkInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public $bulkInquiry;

    public function __construct(BulkInquiry $bulkInquiry)
    {
        $this->bulkInquiry = $bulkInquiry;
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject('New Bulk Inquiry')
                    ->view('emails.bulk-inquiry')
                    ->with(['inquiry' => $this->bulkInquiry]);
    }
}
