<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionalTemplatedMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public string $subjectLine;
    public string $htmlBody;
    public ?string $textBody;
    public array $headerBag;
    public array $attachmentBag;
    public ?int $storeId;

    public function __construct(
        string $subjectLine,
        string $html,
        ?string $text = null,
        array $headers = [],
        array $attachments = [],
        ?int $storeId = null
    ) {
        $this->subjectLine = $subjectLine;
        $this->htmlBody = $html;
        $this->textBody = $text;
        $this->headerBag = $headers;
        $this->attachmentBag = $attachments;
        $this->storeId = $storeId;
    }

    public function build()
    {
        if (!empty($this->headerBag['mailer'])) {
            $this->mailer($this->headerBag['mailer']);
        }

        $store = $this->storeId
            ? Store::find($this->storeId)
            : null;

        $fromEmail = $this->headerBag['from_email']
            ?? config('mail.from.address');

        $providedFromName = trim(
            (string) ($this->headerBag['from_name'] ?? '')
        );

        $fromName = $providedFromName !== ''
            ? $providedFromName
            : ($store?->name ?: config('mail.from.name'));

        $mail = $this->subject($this->subjectLine)
            ->html($this->htmlBody);

        if ($this->textBody !== null && $this->textBody !== '') {
            $mail->text('emails.text-raw', [
                'text' => $this->textBody,
            ]);
        }

        if ($fromEmail) {
            $mail->from($fromEmail, $fromName);
        }

        $this->applyList(
            'replyTo',
            $this->headerBag['reply_to'] ?? null,
            $mail
        );

        $this->applyList(
            'cc',
            $this->headerBag['cc'] ?? null,
            $mail
        );

        $this->applyList(
            'bcc',
            $this->headerBag['bcc'] ?? null,
            $mail
        );

        foreach ($this->attachmentBag as $att) {
            if (isset($att['path'])) {
                $options = [];

                if (!empty($att['as'])) {
                    $options['as'] = $att['as'];
                }

                if (!empty($att['mime'])) {
                    $options['mime'] = $att['mime'];
                }

                $mail->attach($att['path'], $options);
            } elseif (isset($att['data'], $att['name'])) {
                $options = [];

                if (!empty($att['mime'])) {
                    $options['mime'] = $att['mime'];
                }

                $mail->attachData(
                    $att['data'],
                    $att['name'],
                    $options
                );
            }
        }

        return $mail;
    }

    private function applyList(
        string $method,
        ?string $value,
        Mailable $mail
    ): void {
        if (!$value) {
            return;
        }

        foreach ($this->splitList($value) as $address) {
            $mail->{$method}($address);
        }
    }

    private function splitList(string $value): array
    {
        $value = str_replace(
            [';', "\n", "\r", "\t"],
            ',',
            $value
        );

        $parts = array_map('trim', explode(',', $value));

        return array_values(
            array_filter($parts, fn ($value) => $value !== '')
        );
    }
}