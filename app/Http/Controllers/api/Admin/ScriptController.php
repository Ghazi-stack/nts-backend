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
     * Convert script model to camelCase response
     */
    private function formatScript($script)
    {
        $data = [
            'id'            => $script->id,
            'storeId'       => $script->store_id,
            'scriptName'    => $script->script_name,
            'scriptType'    => $script->script_type,
            'scriptContent' => $script->script_content,
            'location'      => $script->location,
            'placement'     => $script->placement,
            'createdAt'     => $script->created_at,
            'updatedAt'     => $script->updated_at,
        ];

        // Format scriptContent as <script src="..."></script> when scriptType is "url"
        if ($script->script_type === 'url' && !empty($script->script_content)) {
            $data['scriptContent'] = '<script src="' . $script->script_content . '"></script>';
        }

        return $data;
    }

    public function store(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $data = $request->all();

            // Map camelCase to snake_case for database
            $dbData = [
                'store_id'       => $storeId,
                'script_type'    => $data['scriptType'] ?? null,
                'script_content' => null,
            ];

            // Map frontend field names
            if (isset($data['scriptType']) && $data['scriptType'] === 'url' && !empty($data['scriptUrl'])) {
                $dbData['script_content'] = $data['scriptUrl'];
            } elseif (!empty($data['scriptContents'])) {
                $dbData['script_content'] = $data['scriptContents'];
            } elseif (!empty($data['scriptContent'])) {
                $dbData['script_content'] = $data['scriptContent'];
            }

            $script = Script::create($dbData);

            return response()->json([
                'status'  => true,
                'message' => 'Script added successfully',
                'data'    => $this->formatScript($script)
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
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

            $formatted = $scripts->map(function ($script) {
                return $this->formatScript($script);
            });

            return response()->json([
                'status' => true,
                'script' => $formatted
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
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
                    'status'  => false,
                    'message' => 'Record not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data'   => $this->formatScript($script)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
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
                    'status'  => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $data = $request->all();

            // Map camelCase to snake_case for database
            $dbData = [];

            if (isset($data['scriptType'])) {
                $dbData['script_type'] = $data['scriptType'];
            }

            if (isset($data['scriptType']) && $data['scriptType'] === 'url' && !empty($data['scriptUrl'])) {
                $dbData['script_content'] = $data['scriptUrl'];
            } elseif (!empty($data['scriptContents'])) {
                $dbData['script_content'] = $data['scriptContents'];
            } elseif (!empty($data['scriptContent'])) {
                $dbData['script_content'] = $data['scriptContent'];
            }

            $script->update($dbData);

            return response()->json([
                'status'  => true,
                'message' => 'Script updated successfully',
                'data'    => $this->formatScript($script->fresh())
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
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
                    'status'  => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $script->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Script deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
