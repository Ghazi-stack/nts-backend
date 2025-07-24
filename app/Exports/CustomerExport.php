<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Customer::where('store_id', current_store_id())
            ->select([
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'address',
                'company_name',
                'customer_group',
                'store_credit',
                'country',
                'state',
                'tax_exempt_code',
                'receive_review_emails',
                'force_password_reset',
                'created_at',
                'updated_at',
            ])->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Last Name',
            'Full Name',
            'Email',
            'Phone',
            'Address',
            'Company Name',
            'Customer Group',
            'Store Credit',
            'Country',
            'State',
            'Tax Exempt Code',
            'Receive Review Emails',
            'Force Password Reset',
            'Created At',
            'Updated At',
        ];
    }
}
