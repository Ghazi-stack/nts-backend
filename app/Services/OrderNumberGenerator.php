<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderNumberGenerator
{
    public function assign(Order $order, int $storeId): string
    {
        return DB::connection('tenant')->transaction(
            function () use ($order, $storeId) {

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

                $nextNumber = trim((string) $nextNumber);

                if (!ctype_digit($nextNumber)) {
                    throw new RuntimeException(
                        'Starting order number must contain digits only.'
                    );
                }

                $lockedOrder = Order::where('id', $order->id)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Do not overwrite an existing order number.
                if (
                    $lockedOrder->order_number !== null &&
                    $lockedOrder->order_number !== ''
                ) {
                    return (string) $lockedOrder->order_number;
                }

                // Assign exactly the configured number.
                $assignedNumber = $nextNumber;

                $lockedOrder->order_number = $assignedNumber;
                $lockedOrder->save();

                // Increment while preserving the original length.
                $incremented = (string) ((int) $nextNumber + 1);

                $originalLength = strlen($nextNumber);

                $miscellaneous['startingOrderNumber'] =
                    str_pad(
                        $incremented,
                        $originalLength,
                        '0',
                        STR_PAD_LEFT
                    );

                $settings->miscellaneous = $miscellaneous;
                $settings->save();

                $order->order_number = $assignedNumber;

                return $assignedNumber;
            }
        );
    }
}