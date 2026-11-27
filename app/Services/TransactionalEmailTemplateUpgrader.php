<?php

namespace App\Services;

use App\Models\TransactionalEmailPhrase;
use App\Models\TransactionalEmailTemplate;
use Database\Seeders\TransactionalEmailLiveTemplates;
use Database\Seeders\TransactionalEmailTemplateSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Moves a store's transactional email templates (on the current tenant
 * connection) from the original demo-content defaults to the v2 templates.
 *
 * Only templates the store owner never changed are upgraded. A template
 * whose HTML differs from the original default is reported as
 * "customized" and left alone; the owner can switch it with
 * "Reset to default" in the dashboard.
 */
class TransactionalEmailTemplateUpgrader
{
    public const UPGRADED   = 'upgraded';
    public const CREATED    = 'created';
    public const REFRESHED  = 'refreshed';
    public const ALREADY_V2 = 'already_v2';
    public const CUSTOMIZED = 'customized';

    /**
     * sha1 of earlier v2 default bodies (normalized whitespace). A stored
     * template matching one of these was never edited, so it can safely be
     * refreshed to the current v2 design.
     */
    private const PREVIOUS_V2_HASHES = [
        'order_email'            => ['7d17940ace317f33d33403f6ddfd90d777ede880'],
        'order_status_update'    => ['7994741cc77cee81aeb6a58972844c3e8224aca3'],
        'order_ready_for_pickup' => ['3ed60447ab32c4fb43ce70451e0d190a68759666'],
        'guest_account_created'  => ['5eb9ece44b13a271e15e7a7680e9043f52e4f5e4'],
        'account_created'        => ['5d4522dbf2ae040286de257fd8ea9fd1473a7fe0'],
        'sign_in_link_request'   => ['e9e3db162012c6a66bc243eca0a1b6e741466aa8'],
        'password_reset'         => ['21c9b6c1e68af44cb871ed9448e35280c2983c8f'],
        'order_notification'     => ['ece4585294f48c1ec90790a155aa792c2ec35fd9'],
    ];

    /**
     * @return array<string, string> template key => outcome
     */
    public function upgradeStore(int $templateStoreId, bool $apply): array
    {
        $legacy = collect((new TransactionalEmailTemplateSeeder())->legacyTemplates())->keyBy('key');
        $report = [];

        foreach (TransactionalEmailLiveTemplates::all() as $key => $v2) {
            $default = $legacy->get($key);

            $tpl = TransactionalEmailTemplate::ofStore($templateStoreId)
                ->key($key)
                ->with('phrases')
                ->first();

            if (!$tpl) {
                $report[$key] = self::CREATED;

                if ($apply) {
                    $this->create($templateStoreId, $key, $default, $v2);
                }

                continue;
            }

            if (TransactionalEmailService::isLiveTemplate($tpl->body_html)) {
                $hash = self::hash($tpl->body_html);

                if ($hash === self::hash($v2['body_html'])) {
                    $report[$key] = self::ALREADY_V2;
                } elseif (in_array($hash, self::PREVIOUS_V2_HASHES[$key] ?? [], true)) {
                    // Untouched earlier v2 design: move to the current one.
                    $report[$key] = self::REFRESHED;

                    if ($apply) {
                        $this->refresh($tpl, $v2);
                    }
                } else {
                    // Owner edited the v2 template: keep their version.
                    $report[$key] = self::ALREADY_V2;
                }

                continue;
            }

            if (!$default || !self::sameHtml($tpl->body_html, $default['body_html'])) {
                $report[$key] = self::CUSTOMIZED;
                continue;
            }

            $report[$key] = self::UPGRADED;

            if ($apply) {
                $this->upgrade($tpl, $default, $v2);
            }
        }

        return $report;
    }

    private function create(int $storeId, string $key, ?array $default, array $v2): void
    {
        DB::connection('tenant')->transaction(function () use ($storeId, $key, $default, $v2) {
            $tpl = TransactionalEmailTemplate::create([
                'store_id'    => $storeId,
                'key'         => $key,
                'name'        => $default['name'] ?? ucwords(str_replace('_', ' ', $key)),
                'description' => $default['description'] ?? null,
                'enabled'     => true,
                'subject'     => $v2['subject'],
                'body_html'   => $v2['body_html'],
            ]);

            foreach ($v2['phrases'] as $phrase) {
                TransactionalEmailPhrase::create([
                    'template_id'  => $tpl->id,
                    'phrase_name'  => $phrase['phrase_name'],
                    'phrase_value' => $phrase['phrase_value'],
                    'is_system'    => true,
                ]);
            }
        });
    }

    /**
     * Replaces HTML (and the subject when it was never changed) and brings
     * system phrases to v2. Phrase values the owner edited and custom
     * (non-system) phrases are kept. The enabled flag is not touched.
     */
    private function upgrade(TransactionalEmailTemplate $tpl, array $default, array $v2): void
    {
        DB::connection('tenant')->transaction(function () use ($tpl, $default, $v2) {
            $updates = ['body_html' => $v2['body_html']];

            if (trim((string) $tpl->subject) === trim((string) ($default['subject'] ?? ''))) {
                $updates['subject'] = $v2['subject'];
            }

            $tpl->update($updates);

            $oldDefaults = collect($default['phrases'] ?? [])->pluck('phrase_value', 'phrase_name');
            $newDefaults = collect($v2['phrases'])->pluck('phrase_value', 'phrase_name');
            $existing = $tpl->phrases->keyBy('phrase_name');

            foreach ($newDefaults as $name => $value) {
                $phrase = $existing->get($name);

                if (!$phrase) {
                    TransactionalEmailPhrase::create([
                        'template_id'  => $tpl->id,
                        'phrase_name'  => $name,
                        'phrase_value' => $value,
                        'is_system'    => true,
                    ]);
                    continue;
                }

                $unchanged = $oldDefaults->has($name)
                    && (string) $phrase->phrase_value === (string) $oldDefaults->get($name);

                if ($unchanged) {
                    $phrase->update(['phrase_value' => $value, 'is_system' => true]);
                }
            }

            // Old system phrases the v2 template no longer uses, still at
            // their default value, are removed. Edited ones are kept.
            foreach ($existing as $name => $phrase) {
                if (
                    $phrase->is_system
                    && !$newDefaults->has($name)
                    && $oldDefaults->has($name)
                    && (string) $phrase->phrase_value === (string) $oldDefaults->get($name)
                ) {
                    $phrase->delete();
                }
            }
        });
    }

    /**
     * Replaces an untouched earlier v2 body with the current one and adds
     * phrases the new design uses. Existing phrases are left as they are.
     */
    private function refresh(TransactionalEmailTemplate $tpl, array $v2): void
    {
        DB::connection('tenant')->transaction(function () use ($tpl, $v2) {
            $tpl->update(['body_html' => $v2['body_html']]);

            $existing = $tpl->phrases->pluck('phrase_name')->all();

            foreach ($v2['phrases'] as $phrase) {
                if (!in_array($phrase['phrase_name'], $existing, true)) {
                    TransactionalEmailPhrase::create([
                        'template_id'  => $tpl->id,
                        'phrase_name'  => $phrase['phrase_name'],
                        'phrase_value' => $phrase['phrase_value'],
                        'is_system'    => true,
                    ]);
                }
            }
        });
    }

    private static function normalize(?string $s): string
    {
        return (string) preg_replace('/\s+/', ' ', trim(str_replace("\r\n", "\n", (string) $s)));
    }

    private static function hash(?string $s): string
    {
        return sha1(self::normalize($s));
    }

    private static function sameHtml(?string $a, ?string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
