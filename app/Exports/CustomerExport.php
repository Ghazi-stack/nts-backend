<?php

namespace App\Exports;

class CustomerExport
{
    public function headings(): array
    {
        return [
            'Customer ID',
            'First Name',
            'Last Name',
            'Company',
            'Email',
            'Phone',
            'Notes',
            'Store Credit',
            'Customer Group',
            'Date Joined',
            'Addresses',
            'Receive Review/Abandoned Cart Emails?',
            'Tax Exempt Category',
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->id ?? '',
            $this->str($customer->first_name ?? ''),
            $this->str($customer->last_name ?? ''),
            $this->str($customer->company_name ?? $customer->company ?? ''),
            $this->str($customer->email ?? ''),
            $this->str($customer->phone ?? ''),
            $this->str($customer->notes ?? $customer->note ?? ''),
            $this->money($customer->store_credit ?? 0),
            $this->str($customer->customer_group ?? ''),
            $this->formatDate($customer->created_at),
            $this->addresses($customer),
            $this->yn($customer->receive_review_emails),
            $this->str($customer->tax_exempt_code ?? $customer->tax_exempt_category ?? ''),
        ];
    }

    private function addresses($customer): string
    {
        $raw = $customer->address
            ?? $customer->addresses
            ?? $customer->billing_address
            ?? null;

        $list = $this->toList($raw);

        if (empty($list)) {
            return '';
        }

        $parts = [];

        foreach ($list as $addr) {
            if (!is_array($addr)) {
                continue;
            }

            $first = $this->fromArr($addr, 'firstName', 'first_name') ?: $this->str($customer->first_name ?? '');
            $last  = $this->fromArr($addr, 'lastName', 'last_name') ?: $this->str($customer->last_name ?? '');

            $parts[] = implode(', ', [
                'Address First Name: ' . $first,
                'Address Last Name: ' . $last,
                'Address Company: ' . ($this->fromArr($addr, 'companyName', 'company_name', 'company') ?: $this->str($customer->company_name ?? '')),
                'Address Line 1: ' . $this->fromArr($addr, 'addressLine1', 'address_line1', 'address_line_1', 'street_1'),
                'Address Line 2: ' . $this->fromArr($addr, 'addressLine2', 'address_line2', 'address_line_2', 'street_2'),
                'City/Suburb: ' . $this->fromArr($addr, 'city', 'suburb'),
                'State Abbreviation: ' . $this->fromArr($addr, 'state', 'state_abbreviation', 'stateAbbreviation'),
                'Zip/Postcode: ' . $this->fromArr($addr, 'zip', 'postal_code', 'postcode'),
                'Country: ' . $this->fromArr($addr, 'country'),
                'Address Phone: ' . ($this->fromArr($addr, 'phone') ?: $this->str($customer->phone ?? '')),
            ]);
        }

        return implode('|', $parts);
    }

    private function toList($raw): array
    {
        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }

            return array_is_list($raw) ? $raw : [$raw];
        }

        if (is_object($raw)) {
            $arr = (array) $raw;
            return array_is_list($arr) ? $arr : [$arr];
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }

            return [[
                'addressLine1' => $raw,
            ]];
        }

        return [];
    }

    private function fromArr(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== null && $data[$key] !== '') {
                return $this->str($data[$key]);
            }
        }

        return '';
    }

    private function yn($val): string
    {
        return $val ? 'Y' : 'N';
    }

    private function money($value): string
    {
        return number_format((float) ($value ?: 0), 2, '.', '');
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return $this->str($value);
        }
    }

    private function str($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }
}
