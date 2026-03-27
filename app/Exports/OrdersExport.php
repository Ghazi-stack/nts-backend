<?php

namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class OrdersExport implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        try {
            $orders = Order::with('customer:id,first_name,last_name,email') // ✅ first_name & last_name
                ->select(
                    'id',
                    'order_number',
                    'customer_id',
                    'status',
                    'total_amount',
                    'created_at'
                )
                ->where('store_id', current_store_id())
                ->latest()
                ->get();

            if ($orders->isEmpty()) {
                return collect([
                    ['No orders found for this store.']
                ]);
            }

            return $orders->map(function ($order) {
                // ✅ first_name + last_name combined
                $firstName = $order->customer->first_name ?? '';
                $lastName  = $order->customer->last_name  ?? '';
                $fullName  = trim($firstName . ' ' . $lastName) ?: 'N/A';

                return [
                    'id'           => $order->id,
                    'order_number' => $order->order_number,
                    'name'         => $fullName,                          // ✅ combined
                    'email'        => $order->customer->email ?? 'N/A',
                    'status'       => $order->status,
                    'total_amount' => $order->total_amount,
                    'created_at'   => $order->created_at?->format('Y-m-d H:i:s'),
                ];
            });
        } catch (\Exception $e) {
            return collect([
                ['Error generating export: ' . $e->getMessage()]
            ]);
        }
    }

    public function headings(): array
    {
        return [
            'ID',
            'Order Number',
            'Customer Name',
            'Customer Email',
            'Status',
            'Total Amount',
            'Created At',
        ];
    }
}
