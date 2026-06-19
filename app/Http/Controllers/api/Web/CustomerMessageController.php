<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerMessageController extends Controller
{
    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $validated = $request->validate([
                'order_number' => 'required|string',
                'subject'      => 'required|string|max:255',
                'message'      => 'required|string',
            ]);

            // Order find karo order_number se
            $order = Order::where('order_number', $validated['order_number'])
                ->where('store_id', $storeId)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found.'
                ], 404);
            }

            $customer = $user->customer;

            $customerMessage = CustomerMessage::create([
                'store_id'     => $storeId,
                'customer_id'  => $customer ? $customer->id : null,
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'subject'      => $validated['subject'],
                'message'      => $validated['message'],
            ]);

            // Admin ko email bhejo
            try {
                $adminEmail = env('MAIL_ADMIN_ADDRESS', 'info@serverblink.uk');
                Mail::raw(
                    "New Customer Message\n\nCustomer: " . ($customer ? $customer->first_name . ' ' . $customer->last_name : $user->name) .
                        "\nEmail: " . $user->email .
                        "\nOrder: #" . $order->order_number .
                        "\nSubject: " . $validated['subject'] .
                        "\n\nMessage:\n" . $validated['message'],
                    function ($mail) use ($adminEmail, $validated) {
                        $mail->to($adminEmail)->subject('Customer Message: ' . $validated['subject']);
                    }
                );
            } catch (\Exception $mailEx) {
                Log::error('CUSTOMER MESSAGE EMAIL FAILED', [
                    'error' => $mailEx->getMessage(),
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Message sent successfully.',
                'data' => [
                    'id'          => $customerMessage->id,
                    'orderNumber' => $customerMessage->order_number,
                    'subject'     => $customerMessage->subject,
                    'message'     => $customerMessage->message,
                    'createdAt'   => $customerMessage->created_at?->toDateTimeString(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Admin: sab messages get karo
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $perPage = $request->query('perPage', 20);
            $keyword = $request->query('keyword');

            $messages = CustomerMessage::with(['customer', 'order'])
                ->where('store_id', $storeId)
                ->when($keyword, function ($q) use ($keyword) {
                    $q->where('subject', 'like', "%{$keyword}%")
                        ->orWhere('message', 'like', "%{$keyword}%")
                        ->orWhere('order_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($c) use ($keyword) {
                            $c->where('first_name', 'like', "%{$keyword}%")
                                ->orWhere('last_name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Messages retrieved successfully.',
                'data' => $messages->map(function ($msg) {
                    return [
                        'id'          => $msg->id,
                        'orderNumber' => $msg->order_number,
                        'subject'     => $msg->subject,
                        'message'     => $msg->message,
                        'isRead'      => $msg->is_read,
                        'customer'    => $msg->customer ? [
                            'id'        => $msg->customer->id,
                            'firstName' => $msg->customer->first_name,
                            'lastName'  => $msg->customer->last_name,
                            'email'     => $msg->customer->email,
                        ] : null,
                        'createdAt'   => $msg->created_at?->toDateTimeString(),
                    ];
                }),
                'pagination' => [
                    'total'       => $messages->total(),
                    'count'       => $messages->count(),
                    'perPage'     => $messages->perPage(),
                    'currentPage' => $messages->currentPage(),
                    'totalPages'  => $messages->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
