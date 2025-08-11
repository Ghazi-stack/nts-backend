<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionalTemplatedMail extends Mailable
{
    use Queueable, SerializesModels;

    // rename to avoid colliding with Mailable::$html and others
    public string $subjectLine;
    public string $htmlBody;
    public ?string $textBody;
    public array $headerBag;
    public array $attachmentBag;

    public function __construct(
        string $subjectLine,
        string $html,
        ?string $text = null,
        array $headers = [],      // from_name, from_email, reply_to, cc, bcc, mailer?
        array $attachments = []   // [['path'=>'...','as'=>..., 'mime'=>...]] or raw attachData
    ) {
        $this->subjectLine   = $subjectLine;
        $this->htmlBody      = $html;
        $this->textBody      = $text;
        $this->headerBag     = $headers;
        $this->attachmentBag = $attachments;
    }

    public function build()
    {
        if (!empty($this->headerBag['mailer'])) {
            $this->mailer($this->headerBag['mailer']);
        }

        $mail = $this->subject($this->subjectLine)
            ->html($this->htmlBody)
            ->text('emails.text-raw', [
                'text' => $this->textBody ?? $this->fallbackText($this->htmlBody)
            ]);

        $fromEmail = $this->headerBag['from_email'] ?? config('mail.from.address');
        $fromName  = $this->headerBag['from_name']  ?? config('mail.from.name');
        if ($fromEmail) {
            $mail->from($fromEmail, $fromName);
        }

        $this->applyList('replyTo', $this->headerBag['reply_to'] ?? null, $mail);
        $this->applyList('cc',      $this->headerBag['cc']      ?? null, $mail);
        $this->applyList('bcc',     $this->headerBag['bcc']     ?? null, $mail);

        foreach ($this->attachmentBag as $att) {
            if (isset($att['path'])) {
                $mail->attach($att['path'], [
                    'as'   => $att['as']   ?? null,
                    'mime' => $att['mime'] ?? null,
                ]);
            } elseif (isset($att['data'], $att['name'])) {
                $mail->attachData($att['data'], $att['name'], [
                    'mime' => $att['mime'] ?? null,
                ]);
            }
        }

        return $mail;
    }

    private function applyList(string $method, ?string $value, Mailable $mail): void
    {
        if (!$value) return;
        $list = $this->splitList($value);
        foreach ($list as $addr) {
            $mail->{$method}($addr);
        }
    }

    private function splitList(string $value): array
    {
        $value = str_replace([';', "\n", "\r", "\t"], ',', $value);
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, fn($v)=>$v!==''));
    }

    private function fallbackText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html))) ?: '';
    }
}
