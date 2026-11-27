<?php

namespace App\Services;

use App\Models\TransactionalEmailPhrase;
use App\Models\TransactionalEmailTemplate;
use Database\Seeders\TransactionalEmailLiveTemplates;
use Database\Seeders\TransactionalEmailTemplateSeeder;
use Database\Seeders\TransactionalEmailV2Snapshot;
use Illuminate\Support\Facades\DB;

/**
 * Moves a store's transactional email templates (on the current tenant
 * connection) to the current default templates (v3: original design,
 * real data).
 *
 * Only templates nobody edited are changed:
 *  - original demo template, untouched       -> upgraded
 *  - earlier v2 template, untouched          -> refreshed
 *  - missing row                             -> created
 *  - already current                         -> already_current
 *  - edited by the owner (any version)       -> customized (left alone; the
 *    owner can use "Reset to default" in the dashboard)
 *
 * Phrase values the owner edited and custom phrases are always kept.
 * The enabled/disabled state is never changed.
 */
class TransactionalEmailTemplateUpgrader
{
    public const UPGRADED        = 'upgraded';
    public const CREATED         = 'created';
    public const REFRESHED       = 'refreshed';
    public const ALREADY_CURRENT = 'already_current';
    public const CUSTOMIZED      = 'customized';

    /**
     * sha1 of the first-generation v2 bodies (the current v2 generation is
     * in TransactionalEmailV2Snapshot).
     */
    private const FIRST_V2_HASHES = [
        'order_email'            => '7d17940ace317f33d33403f6ddfd90d777ede880',
        'order_status_update'    => '7994741cc77cee81aeb6a58972844c3e8224aca3',
        'order_ready_for_pickup' => '3ed60447ab32c4fb43ce70451e0d190a68759666',
        'guest_account_created'  => '5eb9ece44b13a271e15e7a7680e9043f52e4f5e4',
        'account_created'        => '5d4522dbf2ae040286de257fd8ea9fd1473a7fe0',
        'sign_in_link_request'   => 'e9e3db162012c6a66bc243eca0a1b6e741466aa8',
        'password_reset'         => '21c9b6c1e68af44cb871ed9448e35280c2983c8f',
        'order_notification'     => 'ece4585294f48c1ec90790a155aa792c2ec35fd9',
    ];

    /**
     * @return array<string, string> template key => outcome
     */
    public function upgradeStore(int $templateStoreId, bool $apply): array
    {
        $legacy = collect((new TransactionalEmailTemplateSeeder())->legacyTemplates())->keyBy('key');
        $report = [];

        foreach (TransactionalEmailLiveTemplates::all() as $key => $current) {
            $legacyDefault = $legacy->get($key);

            $tpl = TransactionalEmailTemplate::ofStore($templateStoreId)
                ->key($key)
                ->with('phrases')
                ->first();

            if (!$tpl) {
                $report[$key] = self::CREATED;

                if ($apply) {
                    $this->create($templateStoreId, $key, $legacyDefault, $current);
                }

                continue;
            }

            $hash = self::hash($tpl->body_html);

            if ($hash === self::hash($current['body_html'])) {
                $report[$key] = self::ALREADY_CURRENT;
                continue;
            }

            // Untouched original (demo) template.
            if ($legacyDefault && $hash === self::hash($legacyDefault['body_html'])) {
                $report[$key] = self::UPGRADED;

                if ($apply) {
                    $this->replace(
                        $tpl,
                        $current,
                        collect($legacyDefault['phrases'] ?? [])->pluck('phrase_value', 'phrase_name')->all(),
                        (string) ($legacyDefault['subject'] ?? '')
                    );
                }

                continue;
            }

            // Untouched v2 template (either generation).
            $v2 = TransactionalEmailV2Snapshot::DEFAULTS[$key] ?? null;

            if ($v2 && in_array($hash, [$v2['hash'], self::FIRST_V2_HASHES[$key] ?? ''], true)) {
                $report[$key] = self::REFRESHED;

                if ($apply) {
                    $this->replace($tpl, $current, $v2['phrases'], "{{lang 'subject'}}");
                }

                continue;
            }

            $report[$key] = self::CUSTOMIZED;
        }

        return $report;
    }

    private function create(int $storeId, string $key, ?array $legacyDefault, array $current): void
    {
        DB::connection('tenant')->transaction(function () use ($storeId, $key, $legacyDefault, $current) {
            $tpl = TransactionalEmailTemplate::create([
                'store_id'    => $storeId,
                'key'         => $key,
                'name'        => $legacyDefault['name'] ?? ucwords(str_replace('_', ' ', $key)),
                'description' => $legacyDefault['description'] ?? null,
                'enabled'     => true,
                'subject'     => $current['subject'],
                'body_html'   => $current['body_html'],
            ]);

            foreach ($current['phrases'] as $phrase) {
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
     * Replaces the HTML (and the subject when it is still the previous
     * default) and brings system phrases to the current defaults.
     *
     * @param array<string, string> $previousPhrases defaults of the version being replaced
     */
    private function replace(TransactionalEmailTemplate $tpl, array $current, array $previousPhrases, string $previousSubject): void
    {
        DB::connection('tenant')->transaction(function () use ($tpl, $current, $previousPhrases, $previousSubject) {
            $updates = ['body_html' => $current['body_html']];

            if (trim((string) $tpl->subject) === trim($previousSubject)) {
                $updates['subject'] = $current['subject'];
            }

            $tpl->update($updates);

            $newDefaults = collect($current['phrases'])->pluck('phrase_value', 'phrase_name');
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

                // Still the previous default value -> current default.
                // Edited by the owner -> kept.
                if (
                    array_key_exists($name, $previousPhrases)
                    && (string) $phrase->phrase_value === (string) $previousPhrases[$name]
                ) {
                    $phrase->update(['phrase_value' => $value, 'is_system' => true]);
                }
            }

            // Unused system phrases still at their previous default value are removed.
            foreach ($existing as $name => $phrase) {
                if (
                    $phrase->is_system
                    && !$newDefaults->has($name)
                    && array_key_exists($name, $previousPhrases)
                    && (string) $phrase->phrase_value === (string) $previousPhrases[$name]
                ) {
                    $phrase->delete();
                }
            }
        });
    }

    private static function hash(?string $s): string
    {
        return sha1((string) preg_replace('/\s+/', ' ', trim(str_replace("\r\n", "\n", (string) $s))));
    }
}
