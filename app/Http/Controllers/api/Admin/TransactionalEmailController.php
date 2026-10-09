<?php
namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionalEmailTemplateResource;
use App\Mail\TransactionalTemplatedMail;
use App\Models\TransactionalEmailTemplate;
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
            $q = TransactionalEmailTemplate::with('updater')
                ->ofStore(current_store_id());

            if ($r->filled('search')) {
                $s = $r->search;
                $q->where(fn($qq)=>$qq->where('name','like',"%$s%")->orWhere('key','like',"%$s%"));
            }
            $q->orderBy('name');

            $pageSize = (int) $r->input('pageSize', 50);
            $rows = $q->paginate($pageSize);

            return response()->json([
                'status' => true,
                'message'=> 'Email templates fetched.',
                'data'   => TransactionalEmailTemplateResource::collection($rows),
                'pagination' => [
                    'total' => $rows->total(),
                    'page' => $rows->currentPage(),
                    'pageSize' => $rows->perPage(),
                    'lastPage' => $rows->lastPage(),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['error'=>$e->getMessage()],500);
        }
    }

    public function show(string $key)
    {
        $tpl = TransactionalEmailTemplate::with('updater')
            ->ofStore(current_store_id())
            ->key($key)->firstOrFail();

        return response()->json([
            'status'=>true,
            'message'=>'Template fetched.',
            'data'=> new TransactionalEmailTemplateResource($tpl)
        ]);
    }

    public function upsert(Request $r, string $key)
    {
        try {
            $data = $r->validate([
                'name'        => 'nullable|string|max:255',
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
                    ['name' => $data['name'] ?? ucwords(str_replace('_',' ', $key))],
                    $data,
                    ['updated_by' => auth()->id()]
                )
            )->load('updater');

            return response()->json([
                'status'=>true,
                'message'=>'Template saved.',
                'data'=> new TransactionalEmailTemplateResource($tpl)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors'=>$e->errors()],422);
        } catch (Exception $e) {
            return response()->json(['error'=>$e->getMessage()],500);
        }
    }

    public function toggle(string $key)
    {
        $tpl = TransactionalEmailTemplate::ofStore(current_store_id())->key($key)->firstOrFail();
        $tpl->update(['enabled'=>!$tpl->enabled,'updated_by'=>auth()->id()]);
        return response()->json([
            'status'=>true,'message'=>'Status updated.','data'=>new TransactionalEmailTemplateResource($tpl->fresh('updater'))
        ]);
    }

    public function preview(Request $r, string $key)
    {
        $tpl = $this->svc->get(current_store_id(), $key);
        $render = $this->svc->render($tpl, $r->input('data', []));

        return response()->json([
            'status'=>true,'message'=>'Preview generated.','data'=>$render
        ]);
    }

    public function sendTest(Request $r, string $key)
    {

        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'error' => 'Store not selected.',
            ], 400);
        }

        $tpl = $this->svc->get(current_store_id(), $key);
        if (!$tpl->enabled) return response()->json(['error'=>'Template is disabled.'], 400);

        $r->validate(['to'=>'required|email','data'=>'nullable|array']);

        $render  = $this->svc->render($tpl, $r->input('data', []));
        $headers = $this->svc->headers($tpl);

        Mail::to($r->to)->send(
            new TransactionalTemplatedMail($render['subject'], $render['html'], $render['text'], $headers, storeId: (int) $storeId)
        );

        return response()->json(['status'=>true,'message'=>'Test email sent.']);
    }
}
