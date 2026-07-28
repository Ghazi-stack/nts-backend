<?php

namespace App\Exports;

use App\Models\Newsletter;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SubscriberExport implements FromQuery, WithHeadings, WithMapping
{
    protected $storeId;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function query()
    {
        return Newsletter::where('store_id', $this->storeId)
            ->orderBy('id', 'asc');
    }

    public function headings(): array
    {
        return ['Email'];
    }

    public function map($subscriber): array
    {
        return [$subscriber->email];
    }
}
