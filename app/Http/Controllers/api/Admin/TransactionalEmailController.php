<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionalEmailTemplateResource;
use App\Mail\TransactionalTemplatedMail;
use App\Models\TransactionalEmailTemplate;
use App\Models\TransactionalEmailPhrase;
use App\Services\TransactionalEmailService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TransactionalEmailController extends Controller
{
    public function __construct(private TransactionalEmailService $svc) {}

    public function index(Request $r)
    {
        try {
            $q = TransactionalEmailTemplate::with([
                'updater',
                'phrases' => function ($q) {
                    $q->orderBy('is_system', 'desc')->orderBy('id', 'asc');
                }
            ])
                ->ofStore(current_store_id());

            if ($r->filled('search')) {
                $s = $r->search;
                $q->where(fn($qq) => $qq->where('name', 'like', "%$s%")
                    ->orWhere('key', 'like', "%$s%"));
            }

            $q->orderBy('id');

            $pageSize = (int) $r->input('pageSize', 50);
            $rows = $q->paginate($pageSize);

            return response()->json([
                'status'  => true,
                'message' => 'Email templates fetched.',
                'data'    => TransactionalEmailTemplateResource::collection($rows),
                'pagination' => [
                    'total'    => $rows->total(),
                    'page'     => $rows->currentPage(),
                    'pageSize' => $rows->perPage(),
                    'lastPage' => $rows->lastPage(),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(string $key)
    {
        $tpl = TransactionalEmailTemplate::with([
            'updater',
            'phrases' => function ($q) {
                $q->orderBy('is_system', 'desc')->orderBy('id', 'asc');
            }
        ])
            ->ofStore(current_store_id())
            ->key($key)
            ->firstOrFail();

        return response()->json([
            'status'  => true,
            'message' => 'Template fetched.',
            'data'    => new TransactionalEmailTemplateResource($tpl)
        ]);
    }

    // public function show(string $key)
    // {
    //     $tpl = TransactionalEmailTemplate::with([
    //         'updater',
    //         'phrases' => function ($q) {
    //             $q->orderBy('is_system', 'desc')->orderBy('id', 'asc');
    //         }
    //     ])
    //         ->ofStore(current_store_id())
    //         ->key($key)
    //         ->firstOrFail();

    //     return response()->json([
    //         'status'  => true,
    //         'message' => 'Template fetched.',
    //         'data'    => new TransactionalEmailTemplateResource($tpl),
    //         'default' => [
    //             'bodyHtml' => $this->getDefaultHtml(),
    //             'subject'  => 'Your {{store}} account details have changed',
    //             'phrases'  => $this->getDefaultPhrases(),
    //         ],
    //     ]);
    // }

    // private function getDefaultHtml(): string
    // {
    //     return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    //     <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
    //     <head>
    //     <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    //     <meta name="viewport" content="width=device-width">
    //     <style>
    //     body{margin:0;padding:0;background:#fff;font-family:Montserrat,Arial,sans-serif;}
    //     .container{max-width:544px;margin:0 auto;padding:0 20px;}
    //     .logo{text-align:center;padding:24px 0 8px;}
    //     .logo span{font-size:24px;font-weight:600;color:#333;}
    //     .divider{padding:8px 0;}
    //     .divider hr{border:none;border-top:1px solid #e5e5e5;margin:0;}
    //     .icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px;}
    //     .content{padding:24px 0;}
    //     h1{font-size:26px;font-weight:600;color:#333;margin:0 0 20px;line-height:1.3;}
    //     p{font-size:14px;color:#333;line-height:1.5;margin:0 0 12px;}
    //     a{color:#2199e8;text-decoration:none;}
    //     strong{font-weight:600;}
    //     .footer{padding:16px 0 24px;}
    //     .store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333;}
    //     .store-url{font-size:12px;color:#777;}
    //     .btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px;}
    //     </style>
    //     </head>
    //     <body>
    //     <div class="container">
    //     <div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333;"><span>{{store}}</span></a></div>
    //     <div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle;"><hr></td><td width="10%" style="text-align:center;"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle;"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle;"><hr></td></tr></table></div>
    //     <div class="content"><h1>{{subject}}</h1><p>{{hello}}</p><p>{{message}}</p><p><strong>Email, Password</strong></p><p>{{warning}}</p><p style="margin-top:20px;">{{thanks}}<br>{{security}}</p></div>
    //     <div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle;"><hr></td><td width="10%" style="text-align:center;"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle;"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle;"><hr></td></tr></table></div>
    //     <div class="footer"><table width="100%"><tr><td style="vertical-align:middle;"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle;"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div>
    //     </div>
    //     </body>
    //     </html>';
    // }

    // private function getDefaultPhrases(): array
    // {
    //     return [
    //         ['phraseName' => 'subject', 'phraseValue' => 'Your {{store}} account details have changed', 'isSystem' => true],
    //         ['phraseName' => 'hello', 'phraseValue' => 'Hello {{name}},', 'isSystem' => true],
    //         ['phraseName' => 'message', 'phraseValue' => 'We wanted to let you know that the following details have been changed on your <a href="{{store_url}}">{{store}}</a> account:', 'isSystem' => true],
    //         ['phraseName' => 'warning', 'phraseValue' => 'If you made the changes yourself, please disregard this email. If not, please contact the store immediately through their website: <a href="{{store_url}}">{{store_url}}</a>.', 'isSystem' => true],
    //         ['phraseName' => 'thanks', 'phraseValue' => 'Thanks,', 'isSystem' => true],
    //         ['phraseName' => 'security', 'phraseValue' => 'The Security Team', 'isSystem' => true],
    //         ['phraseName' => 'go_shopping', 'phraseValue' => 'Go shopping', 'isSystem' => true],
    //     ];
    // }

    public function upsert(Request $r, string $key)
    {
        try {
            $data = $r->validate([
                'name'        => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'enabled'     => 'nullable|boolean',
                'from_name'   => 'nullable|string|max:255',
                'from_email'  => 'nullable|email',
                'reply_to'    => 'nullable|email',
                'cc'          => 'nullable|string',
                'bcc'         => 'nullable|string',
                'subject'     => 'nullable|string|max:255',
                'body_html'   => 'nullable|string',
                'body_text'   => 'nullable|string',
                'variables'   => 'nullable|array',
                'locale'      => 'nullable|string|max:12',
            ]);

            $tpl = TransactionalEmailTemplate::updateOrCreate(
                ['store_id' => current_store_id(), 'key' => $key],
                array_merge(
                    ['name' => $data['name'] ?? ucwords(str_replace('_', ' ', $key))],
                    $data,
                    ['updated_by' => auth()->id()]
                )
            )->load(['updater', 'phrases']);

            return response()->json([
                'status'  => true,
                'message' => 'Template saved.',
                'data'    => new TransactionalEmailTemplateResource($tpl)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function toggle(string $key)
    {
        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();
        $tpl->update([
            'enabled'    => !$tpl->enabled,
            'updated_by' => auth()->id()
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Status updated.',
            'data'    => new TransactionalEmailTemplateResource($tpl->fresh(['updater', 'phrases']))
        ]);
    }

    public function preview(Request $r, string $key)
    {
        $tpl = $this->svc->get(current_store_id(), $key);

        // Store info lo central database se
        $store = \App\Models\Store::on('central')
            ->where('legacy_store_id', current_store_id())
            ->orWhere('id', current_store_id())
            ->first();

        // Data merge karo - store name aur base_url automatically add
        $data = array_merge($r->input('data', []), [
            'store'     => $store->name ?? ($r->input('data.store') ?? 'Store'),
            'store_url' => $store->base_url ?? '',
        ]);

        $render = $this->svc->render($tpl, $data);

        return response()->json([
            'status'  => true,
            'message' => 'Preview generated.',
            'data'    => $render
        ]);
    }
    public function sendTest(Request $r, string $key)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['error' => 'Store not selected.'], 400);
        }

        $tpl = $this->svc->get($storeId, $key);

        if (!$tpl->enabled) {
            return response()->json(['error' => 'Template is disabled.'], 400);
        }

        $r->validate([
            'to'   => 'required|email',
            'data' => 'nullable|array',
        ]);

        $render  = $this->svc->render($tpl, $r->input('data', []));
        $headers = $this->svc->headers($tpl);

        Mail::to($r->to)->send(
            new TransactionalTemplatedMail(
                $render['subject'],
                $render['html'],
                $render['text'],
                $headers,
                storeId: (int) $storeId
            )
        );

        return response()->json(['status' => true, 'message' => 'Test email sent.']);
    }

    // ==================== PHRASES ====================

    public function addPhrase(Request $r, string $key)
    {
        $r->validate([
            'phraseName'  => 'required|string',
            'phraseValue' => 'nullable|string',
        ]);

        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();

        $phrase = TransactionalEmailPhrase::create([
            'template_id'  => $tpl->id,
            'phrase_name'  => $r->phraseName,
            'phrase_value' => $r->phraseValue ?? '',
            'is_system'    => false,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Phrase added.',
            'data'    => [
                'id'          => $phrase->id,
                'phraseName'  => $phrase->phrase_name,
                'phraseValue' => $phrase->phrase_value,
                'isSystem'    => $phrase->is_system,
            ]
        ], 201);
    }

    public function updatePhrases(Request $r, string $key)
    {
        $r->validate([
            'phrases'               => 'required|array',
            'phrases.*.id'          => 'nullable|integer',
            'phrases.*.phraseName'  => 'required|string',
            'phrases.*.phraseValue' => 'nullable|string',
        ]);

        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();

        $duplicates = [];

        foreach ($r->phrases as $p) {
            if (!empty($p['id'])) {
                // ID hai - update karo
                TransactionalEmailPhrase::where('id', $p['id'])
                    ->where('template_id', $tpl->id)
                    ->update([
                        'phrase_name'  => $p['phraseName'],
                        'phrase_value' => $p['phraseValue'] ?? '',
                    ]);
            } else {
                // ID nahi - check duplicate
                $exists = TransactionalEmailPhrase::where('template_id', $tpl->id)
                    ->where('phrase_name', $p['phraseName'])
                    ->exists();

                if ($exists) {
                    $duplicates[] = $p['phraseName'];
                } else {
                    TransactionalEmailPhrase::create([
                        'template_id'  => $tpl->id,
                        'phrase_name'  => $p['phraseName'],
                        'phrase_value' => $p['phraseValue'] ?? '',
                        'is_system'    => false,
                    ]);
                }
            }
        }

        $tpl->update(['updated_by' => auth()->id()]);

        // Response
        if (!empty($duplicates)) {
            return response()->json([
                'status'  => false,
                'message' => 'Duplicate phrase name: ' . implode(', ', $duplicates),
                'duplicates' => $duplicates,
            ], 422);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Phrases updated.',
        ]);
    }

    public function deletePhrase(string $key, int $phraseId)
    {
        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->first();

        if (!$tpl) {
            return response()->json([
                'status'  => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $phrase = TransactionalEmailPhrase::where('template_id', $tpl->id)
            ->where('id', $phraseId)
            ->first();

        if (!$phrase) {
            return response()->json([
                'status'  => false,
                'message' => 'Phrase not found.',
            ], 404);
        }

        $phrase->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Phrase deleted successfully.',
        ]);
    }

    public function updateCode(Request $r, string $key)
    {
        $r->validate([
            'bodyHtml' => 'required|string',
        ]);

        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();

        $tpl->update([
            'body_html'  => $r->bodyHtml,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Code updated successfully.',
        ]);
    }

    public function resetToDefault(string $key)
    {
        $storeId = current_store_id();

        $tpl = TransactionalEmailTemplate::ofStore($storeId)->key($key)->first();

        if (!$tpl) {
            return response()->json([
                'status'  => false,
                'message' => 'Template not found.',
            ], 404);
        }

        // Custom phrases delete (jo user ne add kiye)
        TransactionalEmailPhrase::where('template_id', $tpl->id)
            ->where('is_system', false)
            ->delete();

        // Reload
        $tpl->load('phrases');

        return response()->json([
            'status'  => true,
            'message' => 'Reset successful. Custom phrases removed.',
            'data'    => new TransactionalEmailTemplateResource($tpl),
        ]);
    }
}
