<?php

namespace App\Imports;

use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class CustomerImport implements ToModel, WithHeadingRow, WithValidation
{
    protected $storeId;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function model(array $row)
    {
        return new Customer([
            'store_id'              => $this->storeId,
            'first_name'            => $row['first_name'] ?? null,
            'last_name'             => $row['last_name'] ?? null,
            'name'                  => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email'                 => $row['email'] ?? null,
            'phone'                 => $row['phone'] ?? null,
            'address'               => $row['address'] ?? null,
            'company_name'          => $row['company_name'] ?? null,
            'customer_group'        => $row['customer_group'] ?? null,
            'store_credit'          => $row['store_credit'] ?? 0,
            'country'               => $row['country'] ?? null,
            'state'                 => $row['state'] ?? null,
            'tax_exempt_code'       => $row['tax_exempt_code'] ?? null,
            'receive_review_emails' => isset($row['receive_review_emails']) ? (bool)$row['receive_review_emails'] : true,
            'force_password_reset'  => isset($row['force_password_reset']) ? (bool)$row['force_password_reset'] : false,
            'password'              => !empty($row['password']) ? Hash::make($row['password']) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => 'nullable|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'email'      => ['nullable', 'email', Rule::unique('customers', 'email')],
            'phone'      => 'nullable|string|max:20',
            'store_credit' => 'nullable|numeric',
            'receive_review_emails' => 'nullable|boolean',
            'force_password_reset'  => 'nullable|boolean',
            'password' => 'nullable|string|min:7',
        ];
    }
}
