<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerMessageController extends Controller
{
    // ===================== CUSTOMER: Send Message =====================
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
                'order_id' => 'required|integer',
                'subject'  => 'required|string|max:255',
                'message'  => 'required|string',
            ]);

            $order = Order::where('id', $validated['order_id'])
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
                'sender_type'  => 'customer',
                'subject'      => $validated['subject'],
                'message'      => $validated['message'],
            ]);

            try {
                $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
                $storeName = Store::find($storeId)?->name ?? 'Store';
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
                'data' => $this->formatMessage($customerMessage),
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

    // ===================== CUSTOMER: Get My Messages (token se) =====================
    public function myMessages(Request $request)
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
            $customer = $user->customer;

            if (!$customer) {
                return response()->json([
                    'status' => true,
                    'message' => 'No messages found.',
                    'data' => [],
                ]);
            }

            $customerOrderIds = Order::where('customer_id', $customer->id)
                ->where('store_id', $storeId)
                ->pluck('id');

            $messages = CustomerMessage::where('store_id', $storeId)
                ->whereIn('order_id', $customerOrderIds)
                ->orderBy('created_at', 'desc')
                ->get();

            $storeName = Store::find($storeId)?->name ?? 'Store';

            return response()->json([
                'status' => true,
                'message' => 'Messages retrieved successfully.',
                'data' => $messages->map(function ($msg) use ($storeName) {
                    return $this->formatMessageWithSender($msg, $storeName);
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===================== ADMIN: Reply to Customer =====================
    public function adminReply(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $validated = $request->validate([
                'order_id' => 'required|integer',
                'subject'  => 'required|string|max:255',
                'message'  => 'required|string',
            ]);

            $order = Order::where('id', $validated['order_id'])
                ->where('store_id', $storeId)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found.'
                ], 404);
            }

            $adminMessage = CustomerMessage::create([
                'store_id'     => $storeId,
                'customer_id'  => $order->customer_id,
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'sender_type'  => 'admin',
                'subject'      => $validated['subject'],
                'message'      => $validated['message'],
            ]);

            try {
                $customerEmail = $order->customer?->email;
                $emailService = app(\App\Services\TransactionalEmailService::class);

                // Store's "Order Notification" template: toggle decides whether the
                // customer is emailed; a live template replaces the plain-text email.
                $rendered = null;
                if ($customerEmail && $emailService->isEnabled($storeId, 'order_notification')) {
                    $rendered = $emailService->renderForMail($storeId, 'order_notification', function () use ($storeId, $order, $validated) {
                        $registry = Store::on('central')->where('legacy_store_id', $storeId)->first();
                        $logoUrl = \App\Models\Logo::where('store_id', $storeId)->value('logo_url');
                        $base = \App\Services\TransactionalEmailContext::base($registry, $logoUrl);

                        return $base + [
                            'customer' => \App\Services\TransactionalEmailContext::customer(
                                $order->customer->first_name ?? null,
                                $order->customer->email ?? null,
                                trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? ''))
                            ),
                            'order'    => ['id' => (string) $order->order_number],
                            'message_data' => ['subject' => $validated['subject'], 'body' => $validated['message']],
                            'links'    => ['account' => $base['store']['url'], 'order_status' => $base['store']['url']],
                        ];
                    });
                } else {
                    $customerEmail = null; // template disabled: no customer email
                }

                if ($customerEmail && $rendered) {
                    Mail::to($customerEmail)->send(new \App\Mail\TransactionalTemplatedMail(
                        $rendered['subject'],
                        $rendered['html'],
                        $rendered['text'],
                        $rendered['headers'],
                        storeId: (int) $storeId
                    ));
                } elseif ($customerEmail) {
                    $storeName = Store::find($storeId)?->name ?? 'Store';
                    Mail::raw(
                        "Hello " . ($order->customer->first_name ?? '') . ",\n\n" .
                            $storeName . " replied to your message regarding Order #" . $order->order_number . ":\n\n" .
                            "Subject: " . $validated['subject'] . "\n" .
                            "Message: " . $validated['message'] . "\n\n" .
                            "Thanks,\n" . $storeName,
                        function ($mail) use ($customerEmail, $validated, $storeName) {
                            $mail->to($customerEmail)->subject('Re: ' . $validated['subject'] . ' - ' . $storeName);
                        }
                    );
                }
            } catch (\Exception $mailEx) {
                Log::error('ADMIN REPLY EMAIL FAILED', [
                    'error' => $mailEx->getMessage(),
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Reply sent successfully.',
                'data' => $this->formatMessage($adminMessage),
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

    // ===================== ADMIN: Get Messages for Order =====================
    public function adminMessages(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $orderId = $request->query('order_id');
            $perPage = $request->query('perPage', 20);
            $keyword = $request->query('keyword');

            $query = CustomerMessage::with(['customer', 'order']) // order relation load
                ->where('store_id', $storeId);

            if ($orderId) {
                $query->where('order_id', $orderId);
            }

            if ($keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('subject', 'like', "%{$keyword}%")
                        ->orWhere('message', 'like', "%{$keyword}%")
                        ->orWhere('order_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($c) use ($keyword) {
                            $c->where('first_name', 'like', "%{$keyword}%")
                                ->orWhere('last_name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('order', function ($o) use ($keyword) {
                            $o->where('order_number', 'like', "%{$keyword}%");
                        });
                });
            }

            $messages = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $storeName = Store::find($storeId)?->name ?? 'Store';

            $totalMessages = $messages->total();
            $unreadCount = CustomerMessage::where('store_id', $storeId)
                ->when($orderId, fn($q) => $q->where('order_id', $orderId))
                ->where('is_read', false)
                ->where('sender_type', 'customer')
                ->count();

            // Agar order_id diya gaya hai to uska order_number nikal lo (top level ke liye)
            $orderNumber = null;
            if ($orderId) {
                $orderNumber = \App\Models\Order::where('id', $orderId)
                    ->where('store_id', $storeId)
                    ->value('order_number');
            }

            return response()->json([
                'status' => true,
                'message' => 'Messages retrieved successfully.',
                'totalMessages' => $totalMessages,
                'unreadCount' => $unreadCount,
                'orderNumber' => $orderNumber, // yahan aayega unreadCount ke neeche
                'data' => $messages->map(function ($msg) use ($storeName) {
                    return $this->formatMessageWithSender($msg, $storeName);
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

    // ===================== ADMIN: Show Single Message =====================
    public function showMessage($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $message = CustomerMessage::with(['customer', 'order'])
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$message) {
                return response()->json([
                    'status' => false,
                    'message' => 'Message not found.'
                ], 404);
            }

            $storeName = Store::find($storeId)?->name ?? 'Store';

            return response()->json([
                'status' => true,
                'message' => 'Message retrieved successfully.',
                'data' => $this->formatMessageWithSender($message, $storeName),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    // ===================== ADMIN: Mark as Read =====================
    public function markAsRead(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids'   => 'required|array|min:1',
                'ids.*' => 'integer',
            ]);

            $storeId = current_store_id();

            CustomerMessage::whereIn('id', $validated['ids'])
                ->where('store_id', $storeId)
                ->update(['is_read' => true]);

            return response()->json([
                'status' => true,
                'message' => 'Messages marked as read.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===================== ADMIN: Mark as Unread =====================
    public function markAsUnread(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids'   => 'required|array|min:1',
                'ids.*' => 'integer',
            ]);

            $storeId = current_store_id();

            CustomerMessage::whereIn('id', $validated['ids'])
                ->where('store_id', $storeId)
                ->update(['is_read' => false]);

            return response()->json([
                'status' => true,
                'message' => 'Messages marked as unread.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===================== ADMIN: Edit Message =====================
    public function updateMessage(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $message = CustomerMessage::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$message) {
                return response()->json([
                    'status' => false,
                    'message' => 'Message not found.'
                ], 404);
            }

            $validated = $request->validate([
                'subject'     => 'nullable|string|max:255',
                'message'     => 'nullable|string',
                'is_read'     => 'nullable|boolean',
                'sender_type' => 'nullable|string|in:customer,admin',
                'order_id'    => 'nullable|integer',
            ]);

            if (isset($validated['order_id'])) {
                $order = Order::where('id', $validated['order_id'])
                    ->where('store_id', $storeId)
                    ->first();

                if ($order) {
                    $message->order_id = $order->id;
                    $message->order_number = $order->order_number;
                }
            }

            if (isset($validated['subject'])) {
                $message->subject = $validated['subject'];
            }

            if (isset($validated['message'])) {
                $message->message = $validated['message'];
            }

            if (isset($validated['is_read'])) {
                $message->is_read = $validated['is_read'];
            }

            if (isset($validated['sender_type'])) {
                $message->sender_type = $validated['sender_type'];
            }

            $message->save();

            return response()->json([
                'status' => true,
                'message' => 'Message updated successfully.',
                'data' => $this->formatMessage($message),
            ]);
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

    // ===================== ADMIN: Toggle Flag =====================
    public function toggleFlag(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids'   => 'required|array|min:1',
                'ids.*' => 'integer',
                'flag'  => 'required|boolean',
            ]);

            $storeId = current_store_id();

            CustomerMessage::whereIn('id', $validated['ids'])
                ->where('store_id', $storeId)
                ->update(['is_flagged' => $validated['flag']]);

            return response()->json([
                'status' => true,
                'message' => $validated['flag'] ? 'Messages flagged.' : 'Messages unflagged.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===================== ADMIN: Delete Messages =====================
    public function deleteMessages(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids'   => 'required|array|min:1',
                'ids.*' => 'integer',
            ]);

            $storeId = current_store_id();

            CustomerMessage::whereIn('id', $validated['ids'])
                ->where('store_id', $storeId)
                ->delete();

            return response()->json([
                'status' => true,
                'message' => 'Messages deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===================== HELPERS =====================
    private function formatMessage($msg)
    {
        return [
            'id'          => $msg->id,
            'orderId'     => $msg->order_id,
            'orderNumber' => $msg->order_number,
            'senderType'  => $msg->sender_type,
            'subject'     => $msg->subject,
            'message'     => $msg->message,
            'isRead'      => $msg->is_read,
            'isFlagged' => $msg->is_flagged,
            'createdAt'   => $msg->created_at?->toDateTimeString(),
            'createdAtFormatted' => $msg->created_at?->format('jS M Y @ g:i A'),
        ];
    }

    private function formatMessageWithSender($msg, $storeName)
    {
        $isAdmin = $msg->sender_type === 'admin';

        $senderLabel = $isAdmin
            ? $storeName . ' said'
            : 'You said';

        $from = $isAdmin
            ? ($msg->customer?->email ?? 'Admin')
            : '[Customer]';

        $subjectDisplay = $isAdmin
            ? 'Re: ' . $msg->subject
            : $msg->subject;

        return [
            'id'             => $msg->id,
            'orderId'        => $msg->order_id,
            'orderNumber'    => $msg->order?->order_number ?? $msg->order_number, // order table se prefer
            'senderType'     => $msg->sender_type,
            'senderLabel'    => $senderLabel,
            'from'           => $from,
            'subject'        => $subjectDisplay,
            'message'        => $msg->message,
            'preview'        => $senderLabel . ': ' . $msg->message,
            'isRead'         => $msg->is_read,
            'isFlagged'      => $msg->is_flagged,
            'customer'       => $msg->customer ? [
                'id'        => $msg->customer->id,
                'firstName' => $msg->customer->first_name,
                'lastName'  => $msg->customer->last_name,
                'email'     => $msg->customer->email,
            ] : null,
            'createdAt'          => $msg->created_at?->toDateTimeString(),
            'createdAtFormatted' => $msg->created_at?->format('jS M Y @ g:i A'),
        ];
    }
}
