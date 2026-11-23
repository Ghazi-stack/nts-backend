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
            'phrases'                 => 'required|array',
            'phrases.*.phraseName'    => 'required|string',
            'phrases.*.phraseValue'   => 'nullable|string',
        ]);

        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();

        foreach ($r->phrases as $p) {
            TransactionalEmailPhrase::updateOrCreate(
                [
                    'template_id' => $tpl->id,
                    'phrase_name' => $p['phraseName'],
                ],
                [
                    'phrase_value' => $p['phraseValue'] ?? '',
                ]
            );
        }

        $tpl->update(['updated_by' => auth()->id()]);

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
