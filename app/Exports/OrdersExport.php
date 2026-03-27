<?php
namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OrdersExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Order::select('id', 'order_number', 'email', 'status', 'total_amount')
            ->where('store_id', current_store_id())
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Order Number',
            // 'Name',
            'Email',
            'Status',
            'Total Amount',
        ];
    }
}
