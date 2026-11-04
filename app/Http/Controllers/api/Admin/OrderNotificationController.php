<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\OrderNotificationSetting;
use App\Http\Resources\OrderNotificationSettingResource;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OrderNotificationController extends Controller
{
    // BigCommerce wale statuses (screenshots se)
    private array $allowedStatuses = [
        'Pending',
        'Awaiting Payment',
        'Awaiting Fulfillment',
        'Awaiting Shipment',
        'Awaiting Pickup',
        'Partially Shipped',
        'Completed',
        'Shipped',
        'Cancelled',
        'Declined',
        'Refunded',
        'Disputed',
        'Manual Verification Required',
        'Partially Refunded',
    ];

    /**
     * Get settings for current store
     */
    public function getSettings()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $setting = OrderNotificationSetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'           => true,
                'message'          => 'Order notification settings retrieved successfully',
                'data'             => $setting ? new OrderNotificationSettingResource($setting) : null,
                'allowedStatuses'  => $this->allowedStatuses,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching order notification settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve settings',
            ], 500);
        }
    }

    /**
     * Create ya Update (Upsert)
     */
    public function saveSettings(Request $request)
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
                'emailAddress'      => 'required|email|max:255',
                'emailEnabled'      => 'nullable|boolean',
                'notifyOnStatuses'  => 'nullable|array',
                'notifyOnStatuses.*' => 'string|in:' . implode(',', $this->allowedStatuses),
            ]);

            $data = [
                'email_address'      => $request->emailAddress,
                'email_enabled'      => $request->boolean('emailEnabled', true),
                'notify_on_statuses' => $request->notifyOnStatuses ?? [],
            ];

            $setting = OrderNotificationSetting::updateOrCreate(
                ['store_id' => $storeId],
                $data
            );

            return response()->json([
                'status'  => true,
                'message' => $setting->wasRecentlyCreated
                    ? 'Order notification settings created successfully'
                    : 'Order notification settings updated successfully',
                'data'    => new OrderNotificationSettingResource($setting),
            ], $setting->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving order notification settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save settings',
            ], 500);
        }
    }

    /**
     * Test Notification Method
     */
    public function testNotification()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $setting = OrderNotificationSetting::where('store_id', $storeId)->first();

            if (!$setting || empty($setting->email_address)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Email address not configured. Please save settings first.',
                ], 400);
            }

            Mail::raw(
                "This is a test order notification email from your store.\n\nIf you received this, email notifications are working correctly.",
                function ($message) use ($setting) {
                    $message->to($setting->email_address)
                        ->subject('Test Order Notification');
                }
            );

            return response()->json([
                'status'  => true,
                'message' => "A sample email message has been sent to {$setting->email_address}. It should appear in your inbox within the next minute or two.",
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error sending test notification: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to send test email',
            ], 500);
        }
    }
}
