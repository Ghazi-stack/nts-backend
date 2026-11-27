<?php

namespace App\Mail;

use App\Models\Store;
use App\Services\TransactionalEmailService;
use Illuminate\Mail\Mailable;

abstract class StoreMailable extends Mailable
{
    protected function setStoreSender(?Store $store): static
    {
        return $this->from(
            config('mail.from.address'),
            $store?->name ?: config('mail.from.name')
        );
    }

    protected function findStoreByLegacyId(int $legacyStoreId): Store
    {
        return Store::on('central')
            ->where('legacy_store_id', $legacyStoreId)
            ->firstOrFail();
    }

    /**
     * Builds this email from the store's dashboard template
     * (Dashboard > Marketing > Transactional Emails) when one exists.
     *
     * Returns false when the store has no usable template or it fails to
     * render; the caller then builds its default Blade email, so the email
     * is always sent.
     *
     * @param int|string|null   $templateStoreId store_id used by transactional_email_templates
     * @param callable(): array $context          template data, built only if a template exists
     */
    protected function buildFromStoreTemplate(?Store $store, $templateStoreId, string $key, callable $context): bool
    {
        $rendered = app(TransactionalEmailService::class)
            ->renderForMail($templateStoreId, $key, $context);

        if ($rendered === null) {
            return false;
        }

        $headers = $rendered['headers'];

        $fromName = trim((string) ($headers['from_name'] ?? ''));
        $fromEmail = trim((string) ($headers['from_email'] ?? ''));

        $this->from(
            $fromEmail !== '' ? $fromEmail : config('mail.from.address'),
            $fromName !== '' ? $fromName : ($store?->name ?: config('mail.from.name'))
        );

        if ($rendered['subject'] !== '') {
            $this->subject($rendered['subject']);
        }

        $this->html($rendered['html']);

        foreach (['replyTo' => 'reply_to', 'cc' => 'cc', 'bcc' => 'bcc'] as $method => $field) {
            foreach ($this->splitAddressList($headers[$field] ?? null) as $address) {
                $this->{$method}($address);
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function splitAddressList(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $parts = array_map('trim', explode(',', str_replace([';', "\n", "\r", "\t"], ',', $value)));

        return array_values(array_filter(
            $parts,
            fn ($address) => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)
        ));
    }
}
