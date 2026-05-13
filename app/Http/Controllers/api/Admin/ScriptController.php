<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Script;
use Illuminate\Http\Request;
use Exception;

class ScriptController extends Controller
{

    private function getStoreId()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400));
        }

        return $storeId;
    }

    /**
     * Format script_content as <script src="..."></script> when script_type is "url"
     */
    private function formatScriptOutput($script)
    {
        if ($script->script_type === 'url' && !empty($script->script_content)) {
            $script->script_content = '<script src="' . $script->script_content . '"></script>';
        }
        return $script;
    }

    public function store(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $data = $request->all();

            // ✅ force store_id from header
            $data['store_id'] = $storeId;

            // ✅ If script_type is url, save script_url value into script_content
            if (isset($data['script_type']) && $data['script_type'] === 'url' && !empty($data['script_url'])) {
                $data['script_content'] = $data['script_url'];
            }

            $script = Script::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Script added successfully',
                'data' => $script
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        try {
            $storeId = $this->getStoreId();

            $scripts = Script::where('store_id', $storeId)
                ->orderBy('id', 'desc')
                ->get();

            // ✅ Format script_content for url type scripts
            $scripts->transform(function ($script) {
                return $this->formatScriptOutput($script);
            });

            return response($scripts->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                ->header('Content-Type', 'application/json');
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function show($id)
    {
        try {
            $storeId = $this->getStoreId();

            $script = Script::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$script) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            // ✅ Format script_content for url type
            $this->formatScriptOutput($script);

            return response()->json([
                'status' => true,
                'data' => $script
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $storeId = $this->getStoreId();

            $script = Script::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$script) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $data = $request->all();

            // ❌ store_id overwrite prevent
            unset($data['store_id']);

            // ✅ If script_type is url, save script_url value into script_content
            if (isset($data['script_type']) && $data['script_type'] === 'url' && !empty($data['script_url'])) {
                $data['script_content'] = $data['script_url'];
            }

            $script->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Script updated successfully',
                'data' => $script
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $storeId = $this->getStoreId();

            $script = Script::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$script) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $script->delete();

            return response()->json([
                'status' => true,
                'message' => 'Script deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
