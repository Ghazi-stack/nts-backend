<?php

namespace App\Services;

use App\Models\TransactionalEmailTemplate;
use Illuminate\Support\Facades\Log;

class TransactionalEmailService
{
    /**
     * Marker (a template comment, invisible in the email) that identifies a
     * template written for EmailTemplateEngine with real order/customer data.
     */
    public const LIVE_MARKER = '{{!-- email-engine:v2 --}}';

    public static function isLiveTemplate(?string $html): bool
    {
        return $html !== null && str_contains($html, 'email-engine:v2');
    }

    /**
     * Whether the store has this transactional email enabled.
     *
     * Fails open: if the store has no template row for the key, or the
     * lookup fails (e.g. no tenant connection), the email is still sent,
     * preserving the behavior from before templates could be disabled.
     */
    public function isEnabled($storeId, string $key): bool
    {
        if (!$storeId) {
            return true;
        }

        try {
            $enabled = TransactionalEmailTemplate::ofStore((int) $storeId)
                ->key($key)
                ->value('enabled');
        } catch (\Throwable $e) {
            Log::warning('Transactional email enabled-check failed; sending anyway', [
                'store_id' => $storeId,
                'key'      => $key,
                'error'    => $e->getMessage(),
            ]);

            return true;
        }

        if ($enabled === null || (bool) $enabled) {
            return true;
        }

        Log::info('Transactional email skipped: template disabled', [
            'store_id' => $storeId,
            'key'      => $key,
        ]);

        return false;
    }

    public function get(int $storeId, string $key): TransactionalEmailTemplate
    {
        return TransactionalEmailTemplate::ofStore($storeId)
            ->key($key)
            ->with('phrases')
            ->firstOrFail();
    }

    /**
     * Renders a template with the Handlebars-style EmailTemplateEngine.
     *
     * Phrases are available as {{lang 'name'}} and, for templates written
     * before the engine existed, as plain {{name}} when no data value has
     * that name.
     */
    public function render(TransactionalEmailTemplate $tpl, array $data = []): array
    {
        $vars = array_merge($tpl->variables ?? [], $data);

        $phrases = [];
        foreach ($tpl->phrases as $phrase) {
            $phrases[$phrase->phrase_name] = (string) $phrase->phrase_value;
        }

        $engine = new EmailTemplateEngine();

        // Subject is plain text: no HTML escaping.
        $subject = $engine->render($tpl->subject ?? '', $vars, $phrases, false);
        $html    = $engine->render($tpl->body_html ?? '', $vars, $phrases);
        $text    = $tpl->body_text
            ? $engine->render($tpl->body_text, $vars, $phrases, false)
            : trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        return [
            'subject' => trim(preg_replace('/\s+/', ' ', $subject)),
            'html'    => $html,
            'text'    => $text,
        ];
    }

    /**
     * Renders the store's template for a live email.
     *
     * Returns null when the store has no usable template (no row, empty
     * HTML) or rendering fails, so the caller can fall back to its Blade
     * view and the email is never lost. The enabled flag is NOT checked
     * here: send sites decide whether to send (see isEnabled()).
     *
     * @param callable(): array $context built lazily, only if a template exists
     * @return array{subject: string, html: string, text: string, headers: array}|null
     */
    public function renderForMail($storeId, string $key, callable $context): ?array
    {
        if (!$storeId) {
            return null;
        }

        try {
            $tpl = TransactionalEmailTemplate::ofStore((int) $storeId)
                ->key($key)
                ->with('phrases')
                ->first();

            if (!$tpl || trim((string) $tpl->body_html) === '') {
                return null;
            }

            // Templates seeded before the engine existed contain demo data
            // ("Demo Product", "John Doe"). Only templates written for the
            // engine (they carry LIVE_MARKER) are used for real emails.
            if (!self::isLiveTemplate($tpl->body_html)) {
                return null;
            }

            $rendered = $this->render($tpl, TransactionalEmailContext::withLegacyAliases($context()));

            if (trim(strip_tags($rendered['html'])) === '') {
                return null;
            }

            return $rendered + ['headers' => $this->headers($tpl)];
        } catch (\Throwable $e) {
            Log::warning('Transactional email template render failed; using default email', [
                'store_id' => $storeId,
                'key'      => $key,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function headers(TransactionalEmailTemplate $tpl): array
    {
        return [
            'from_name'  => $tpl->from_name,
            'from_email' => $tpl->from_email,
            'reply_to'   => $tpl->reply_to,
            'cc'         => $tpl->cc,
            'bcc'        => $tpl->bcc,
        ];
    }
}
