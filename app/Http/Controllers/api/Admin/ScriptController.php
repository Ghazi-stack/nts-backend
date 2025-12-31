<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Script;
use Illuminate\Http\Request;
use Exception;

class ScriptController extends Controller
{
    // ADD DATA
    public function store(Request $request)
    {
        try {
            $script = Script::create($request->all());

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

    // FETCH ALL
    public function index()
    {
        try {
            return response()->json([
                'status' => true,
                'script' => Script::all()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // FETCH BY ID
    public function show($id)
    {
        try {
            $script = Script::find($id);

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

    // UPDATE BY ID
    public function update(Request $request, $id)
    {
        try {
            $script = Script::find($id);

            if (!$script) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $script->update($request->all());

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

    // DELETE
    public function destroy($id)
    {
        try {
            $script = Script::find($id);

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
