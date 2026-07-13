<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    // public $payment;
    public $order;
    public $pdf;
    public $logoBase64;

    public function __construct($order, $pdf, $logoBase64)
    {
        $this->order = $order;
        $this->pdf = $pdf;
        $this->logoBase64 = $logoBase64;
    }

    public function build()
    {
        return $this->subject('Your Payment Invoice from Server Blink')
                    ->view('emails.payment_invoice')
                    ->with([
                        'order'       => $this->order,
                        'logoBase64'  => $this->logoBase64, // 👈 Add this
                    ])
                    ->attachData($this->pdf->output(), 'invoice-' . $this->order->order_number . '.pdf', [
                        'mime' => 'application/pdf',
                    ]);
    }
}
