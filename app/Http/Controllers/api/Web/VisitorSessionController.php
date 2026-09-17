<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VisitorSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class VisitorSessionController extends Controller
{
    public function store(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $request->validate([
                'sessionId' => 'required|string|max:255',
            ]);

            $session = VisitorSession::where('store_id', $storeId)
                ->where('session_id', $request->sessionId)
                ->first();

            if ($session) {
                $session->touch();

                return response()->json([
                    'status'  => true,
                    'message' => 'Session already exists.',
                ]);
            }

            VisitorSession::create([
                'store_id'   => $storeId,
                'session_id' => $request->sessionId,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Session stored successfully.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status'  => true,
                'message' => 'Session already exists.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }
}
