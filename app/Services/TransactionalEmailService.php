<?php
namespace App\Services;

use App\Models\TransactionalEmailTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;

class TransactionalEmailService
{
    public function get(int $storeId, string $key): TransactionalEmailTemplate
    {
        return TransactionalEmailTemplate::ofStore($storeId)->key($key)->firstOrFail();
    }

    public function render(TransactionalEmailTemplate $tpl, array $data): array
    {
        // database defaults + runtime payload
        $vars = array_merge($tpl->variables ?? [], $data);

        $subject = $this->interpolate($tpl->subject ?? '', $vars);
        $html    = $tpl->body_html ? $this->bladeFromString($tpl->body_html, $vars) : '';
        $text    = $tpl->body_text ? $this->bladeFromString($tpl->body_text, $vars) : strip_tags($html);

        return compact('subject','html','text');
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
        return preg_replace_callback('/{{\s*([a-z0-9\._]+)\s*}}/i', function($m) use ($data){
            return (string) Arr::get($data, $m[1], '');
        }, $str);
    }

    private function bladeFromString(string $contents, array $data): string
    {
        $hash = sha1($contents);
        $path = storage_path("framework/views/tpl_{$hash}.blade.php");
        if (!file_exists($path)) file_put_contents($path, $contents);
        return View::file($path, ['data'=>$data])->render();
    }
}
