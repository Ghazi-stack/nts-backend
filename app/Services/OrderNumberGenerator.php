<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderNumberGenerator
{
    /**
     * Assign the next customer-facing order number.
     *
     * Draft orders must not call this method until conversion.
     */
    public function assign(Order $order, int $storeId): string
    {
        return DB::connection('tenant')->transaction(function () use ($order, $storeId) {

            // Lock the settings row to prevent concurrent sequence allocation.
            $settings = StoreSetting::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (!$settings) {
                throw new RuntimeException(
                    'Store settings not found.'
                );
            }

            $miscellaneous = $settings->miscellaneous ?? [];

            $nextNumber = $miscellaneous['startingOrderNumber'] ?? null;

            if (
                !is_string($nextNumber) &&
                !is_int($nextNumber)
            ) {
                throw new RuntimeException(
                    'Starting order number is not configured.'
                );
            }

            $nextNumber = (string) $nextNumber;

            if (!ctype_digit($nextNumber)) {
                throw new RuntimeException(
                    'Starting order number must contain only digits.'
                );
            }

            // Reload the order inside the transaction.
            $lockedOrder = Order::where('id', $order->id)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->firstOrFail();

            // Avoid allocating another number if already assigned.
            if (!empty($lockedOrder->order_number)) {
                return (string) $lockedOrder->order_number;
            }

            // Assign the current sequence number.
            $lockedOrder->order_number = $nextNumber;
            $lockedOrder->save();

            // Increment the next available number.
            $miscellaneous['startingOrderNumber'] =
                (string) ((int) $nextNumber + 1);

            $settings->miscellaneous = $miscellaneous;
            $settings->save();

            // Synchronize the original model.
            $order->order_number = $nextNumber;

            return $nextNumber;
        });
    }
}
