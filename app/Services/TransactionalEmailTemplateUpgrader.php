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
    public const ALREADY_V2 = 'already_v2';
    public const CUSTOMIZED = 'customized';

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
                $report[$key] = self::ALREADY_V2;
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

    private static function sameHtml(?string $a, ?string $b): bool
    {
        $normalize = fn(?string $s) => preg_replace('/\s+/', ' ', trim(str_replace("\r\n", "\n", (string) $s)));

        return $normalize($a) === $normalize($b);
    }
}
