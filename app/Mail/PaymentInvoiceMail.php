<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $payment;
    public $pdf;

    public function __construct($payment, $pdf)
    {
        $this->payment = $payment;
        $this->pdf = $pdf;
    }

    public function build()
    {
        return $this->subject('Your Payment Invoice from Newtown Spares')
                    ->view('emails.payment_invoice')
                    ->with([
                        'payment' => $this->payment,
                    ])
                    ->attachData($this->pdf->output(), 'invoice-' . $this->payment->order_number . '.pdf', [
                        'mime' => 'application/pdf',
                    ]);
    }
}
