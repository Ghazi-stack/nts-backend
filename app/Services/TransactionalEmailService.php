<?php

namespace App\Services;

use App\Models\TransactionalEmailTemplate;
use Illuminate\Support\Arr;

class TransactionalEmailService
{
    public function get(int $storeId, string $key): TransactionalEmailTemplate
    {
        return TransactionalEmailTemplate::ofStore($storeId)
            ->key($key)
            ->with('phrases')
            ->firstOrFail();
    }

    public function render(TransactionalEmailTemplate $tpl, array $data = []): array
    {
        $vars = array_merge($tpl->variables ?? [], $data);

        // Phrases inject karo
        foreach ($tpl->phrases as $phrase) {
            $vars[$phrase->phrase_name] = $phrase->phrase_value;
        }

        // Pehla pass: phrases replace
        $subject = $this->interpolate($tpl->subject ?? '', $vars);
        $html    = $this->interpolate($tpl->body_html ?? '', $vars);
        $text    = $tpl->body_text
            ? $this->interpolate($tpl->body_text, $vars)
            : strip_tags($html);

        // Doosra pass: phrase values ke andar {{name}}, {{store}} replace
        $subject = $this->interpolate($subject, $vars);
        $html    = $this->interpolate($html, $vars);
        $text    = $this->interpolate($text, $vars);

        return [
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ];
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

    private function interpolate(string $str, array $data): string
    {
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($m) use ($data) {
            $key = $m[1];
            return Arr::get($data, $key, $m[0]);
        }, $str);
    }
}
