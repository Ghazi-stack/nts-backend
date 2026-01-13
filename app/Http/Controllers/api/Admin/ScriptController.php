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

    public function store(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $data = $request->all();

            // ✅ force store_id from header
            $data['store_id'] = $storeId;

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

            return response()->json([
                'status' => true,
                'script' => $scripts
            ]);

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

