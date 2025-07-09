<?php
namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use PDF;

class PaymentInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function build()
    {
        $pdf = PDF::loadView('invoices.payment', ['payment' => $this->payment]);

        return $this->subject('Your Payment Invoice')
                    ->markdown('emails.invoice')
                    ->attachData($pdf->output(), 'invoice.pdf', [
                        'mime' => 'application/pdf',
                    ]);
    }
}
