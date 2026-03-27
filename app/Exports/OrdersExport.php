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
            $orders = Order::with('customer:id,name,email') // ✅ eager load user
                ->select(
                    'id',
                    'order_number',
                    'customer_id',  // ✅ needed for relation
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
                return [
                    'id'           => $order->id,
                    'order_number' => $order->order_number,
                    'name'         => $order->customer->name  ?? 'N/A', // ✅ from users table
                    'email'        => $order->customer->email ?? 'N/A', // ✅ from users table
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
            'Customer Name',  // ✅ from users table
            'Customer Email', // ✅ from users table
            'Status',
            'Total Amount',
            'Created At',
        ];
    }
}