<?php

namespace App\Imports;

use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithMapping;

class CustomerImport implements ToModel, WithHeadingRow, WithValidation, WithMapping
{
    protected $storeId;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    // ✅ Map and preprocess each row before validation
    public function map($row): array
    {
        return [
            'first_name'            => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email'                 => $row['email'] ?? null,
            'phone'                 => isset($row['phone']) ? (string)$row['phone'] : null,
            'address'               => $row['address_1'] ?? null,
            'company_name'          => $row['company'] ?? null,
            'customer_group'        => isset($row['customer_group']) 
                                        ? strtolower($row['customer_group']) 
                                        : null, // ✅ lowercase only
            'store_credit'          => $row['store_credit'] ?? 0,
            'country'               => $row['country'] ?? null,
            'state'                 => $row['state'] ?? null,
            'tax_exempt_code'       => isset($row['tax_exempt_code']) ? (string)$row['tax_exempt_code'] : null,
            'receive_review_emails' => isset($row['receive_review_emails']) ? (bool)$row['receive_review_emails'] : true,
            'force_password_reset'  => isset($row['force_password_reset']) ? (bool)$row['force_password_reset'] : false,
            'password'              => !empty($row['password']) ? \Hash::make($row['password']) : null,
        ];
    }

    public function model(array $row)
    {
        return new Customer([
            'store_id'              => $this->storeId,
            'first_name' => $row['first_name'],
            'email'                 => $row['email'],
            'phone'                 => $row['phone'],
            'address'               => $row['address'],
            'company_name'          => $row['company_name'],
            'customer_group'        => $row['customer_group'], // already lowercased
            'store_credit'          => $row['store_credit'],
            'country'               => $row['country'],
            'state'                 => $row['state'],
            'tax_exempt_code'       => $row['tax_exempt_code'],
            'receive_review_emails' => $row['receive_review_emails'],
            'force_password_reset'  => $row['force_password_reset'],
            'password'              => $row['password'],
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name'            => 'nullable|string|max:255',
            'last_name'             => 'nullable|string|max:255',
            'email'                 => ['nullable', 'email', Rule::unique('customers', 'email')],
            'phone'                 => 'nullable|string|max:20',
            'store_credit'          => 'nullable|numeric',
            'receive_review_emails' => 'nullable|boolean',
            'force_password_reset'  => 'nullable|boolean',
            'password'              => 'nullable|string|min:7',
            'customer_group'        => 'nullable|string|max:255', // no specific values
        ];
    }
}
