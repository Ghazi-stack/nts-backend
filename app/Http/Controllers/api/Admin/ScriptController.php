<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Script;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Str;

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

    /**
     * Convert array keys from camelCase → snake_case (recursive)
     */
    private function camelToSnake(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $snakeKey = is_string($key) ? Str::snake($key) : $key;
            $result[$snakeKey] = is_array($value) ? $this->camelToSnake($value) : $value;
        }
        return $result;
    }

    /**
     * Convert array keys from snake_case → camelCase (recursive)
     */
    private function snakeToCamel(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $camelKey = is_string($key) ? Str::camel($key) : $key;
            $result[$camelKey] = is_array($value) ? $this->snakeToCamel($value) : $value;
        }
        return $result;
    }

    /**
     * Convert Model / array to camelCase array for response
     */
    private function toCamel($item)
    {
        if ($item instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->snakeToCamel($item->toArray());
        }
        if (is_array($item)) {
            return $this->snakeToCamel($item);
        }
        return $item;
    }

    public function store(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            // Convert incoming camelCase payload → snake_case
            $data = $this->camelToSnake($request->all());

            // ✅ force store_id from header
            $data['store_id'] = $storeId;

            // ✅ Map frontend field names to database field
            if (isset($data['script_type']) && $data['script_type'] === 'url' && !empty($data['script_url'])) {
                $data['script_content'] = $data['script_url'];
            } elseif (!empty($data['script_contents'])) {
                $data['script_content'] = $data['script_contents'];
            }

            $script = Script::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Script added successfully',
                'data' => $this->toCamel($script)
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

            // Convert each script to camelCase
            $scripts = $scripts->map(function ($script) {
                return $this->toCamel($script);
            });

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

            // ✅ Format script_content for url type
            $this->formatScriptOutput($script);

            return response()->json([
                'status' => true,
                'data' => $this->toCamel($script)
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

            // Convert incoming camelCase payload → snake_case
            $data = $this->camelToSnake($request->all());

            // ❌ store_id overwrite prevent
            unset($data['store_id']);

            // ✅ Map frontend field names to database field
            if (isset($data['script_type']) && $data['script_type'] === 'url' && !empty($data['script_url'])) {
                $data['script_content'] = $data['script_url'];
            } elseif (!empty($data['script_contents'])) {
                $data['script_content'] = $data['script_contents'];
            }

            $script->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Script updated successfully',
                'data' => $this->toCamel($script)
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
