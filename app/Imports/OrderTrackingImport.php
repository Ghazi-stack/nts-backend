<?php

namespace App\Imports;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OrderTrackingImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings, SkipsOnFailure
{
    use SkipsFailures;

    public array $summary = [
        'processed' => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'skipped_reasons' => [
            'validation'      => 0,
            'no_identifier'   => 0,
            'not_found'       => 0,
            'already_tracked' => 0,
        ],
        'errors'    => [],
    ];

    public function __construct(
        protected int $storeId,
        protected ?string $statusToSet = 'Shipped',
        protected bool $overrideExisting = false,
        protected bool $hasHeaders = true,
        protected string $separator = ',',
        protected string $enclosure = '"',
    ) {}

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => $this->separator,
            'enclosure' => $this->enclosure,
        ];
    }

    public function headingRow(): int
    {
        return $this->hasHeaders ? 1 : 0;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $i => $row) {
            $this->summary['processed']++;
            $lineNo = $this->hasHeaders ? $i + 2 : $i + 1;

            // Build assoc for no-headers mode
            if (!$this->hasHeaders) {
                $assoc = [
                    'Order Id'        => $row[0] ?? null,
                    'Order Number'    => $row[1] ?? null,
                    'Tracking Number' => $row[2] ?? null,
                    'Carrier Code'    => $row[3] ?? null,
                    'Carrier Name'    => $row[4] ?? null,
                    'Shipped_at'      => $row[5] ?? null,
                    'Store Id'        => $row[6] ?? null,
                ];
            } else {
                $assoc = $row->toArray();
            }

            // Normalize headers → camelCase
            $data = $this->normalizeToCamelCase($assoc);

            // Store ID: prefer column if present, else use constructor value
            $data['storeId'] = $this->toIntOrNull($data['storeId'] ?? $assoc['Store Id'] ?? $this->storeId);

            // Prefer orderNumber over orderId if present
            if (!empty($data['orderNumber'])) {
                $data['orderId'] = null;
            }

            // Force integer or null for orderId
            $data['orderId'] = $this->toIntOrNull($data['orderId']);

            // Normalize order number (strip a leading "#", trim)
            if (!empty($data['orderNumber'])) {
                $data['orderNumber'] = $this->normalizeOrderNumber($data['orderNumber']);
            }

            // Normalize date
            $data['shippedAt'] = $this->normalizeDate($data['shippedAt']);

            // Validate
            $v = Validator::make($data, [
                'orderId'        => 'nullable|integer',
                'orderNumber'    => 'nullable|string',
                'trackingNumber' => 'required|string',
                'carrierCode'    => 'nullable|string|max:50',
                'carrierName'    => 'nullable|string|max:100',
                'shippedAt'      => 'nullable|date',
                'storeId'        => 'required|integer',
            ]);

            if ($v->fails()) {
                $this->summary['errors'][] = [
                    'line'   => $lineNo,
                    'errors' => $v->errors()->all(),
                    'input'  => $this->debugIdentifiers($data),
                ];
                $this->summary['skipped']++;
                $this->summary['skipped_reasons']['validation']++;
                continue;
            }

            if (empty($data['orderId']) && empty($data['orderNumber'])) {
                $this->summary['errors'][] = [
                    'line'   => $lineNo,
                    'errors' => ['Provide orderId or orderNumber'],
                    'input'  => $this->debugIdentifiers($data),
                ];
                $this->summary['skipped']++;
                $this->summary['skipped_reasons']['no_identifier']++;
                continue;
            }

            // Store-scoped lookup (with a couple of helpful fallbacks)
            $order = $this->findOrder($data);

            if (!$order) {
                $this->summary['errors'][] = [
                    'line'   => $lineNo,
                    'errors' => ['Order not found for this store'],
                    'input'  => $this->debugIdentifiers($data),
                ];
                $this->summary['skipped']++;
                $this->summary['skipped_reasons']['not_found']++;
                continue;
            }

            if (!$this->overrideExisting && $order->tracking_number) {
                $this->summary['skipped']++;
                $this->summary['skipped_reasons']['already_tracked']++;
                continue;
            }

            // Apply updates
            $order->tracking_number       = $data['trackingNumber'];
            $order->tracking_carrier_code = $data['carrierCode'] ?? $order->tracking_carrier_code;
            $order->tracking_carrier_name = $data['carrierName'] ?? $order->tracking_carrier_name;
            $order->shipped_at            = $data['shippedAt'] ?: now();

            if ($this->statusToSet) {
                $order->status = $this->statusToSet;
            }

            $order->save();
            $this->summary['updated']++;
        }
    }

    private function findOrder(array $data): ?Order
    {
        // Always scope by store
        $base = Order::query()->where('store_id', (int) $data['storeId']);

        // Try by ID first if present
        if (!empty($data['orderId'])) {
            $byId = (clone $base)->where('id', (int) $data['orderId'])->first();
            if ($byId) {
                return $byId;
            }
        }

        // Then by order number (with small helpers)
        if (!empty($data['orderNumber'])) {
            $orderNumber = $data['orderNumber'];

            // 1) exact match
            $q = (clone $base)->where('order_number', $orderNumber);

            // 2) if numeric-like, also try matching to DB values with leading zeros (MySQL & Postgres variants)
            if (ctype_digit($orderNumber)) {
                $driver = DB::getDriverName();
                if ($driver === 'mysql') {
                    $q->orWhereRaw("TRIM(LEADING '0' FROM order_number) = ?", [$orderNumber]);
                } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
                    $q->orWhereRaw("ltrim(order_number, '0') = ?", [$orderNumber]);
                }
            }

            $byNumber = $q->first();
            if ($byNumber) {
                return $byNumber;
            }
        }

        return null;
    }

    private function normalizeToCamelCase(array $assoc): array
    {
        $index = [];
        foreach ($assoc as $k => $v) {
            if ($k === null) continue;
            $norm = $this->normalizeKey((string)$k);
            $index[$norm] = $v;
        }

        $map = [
            'orderid'        => 'orderId',
            'id'             => 'orderId',
            'ordernumber'    => 'orderNumber',
            'orderno'        => 'orderNumber',
            'trackingnumber' => 'trackingNumber',
            'tracking'       => 'trackingNumber',
            'carriercode'    => 'carrierCode',
            'carriername'    => 'carrierName',
            'shippedat'      => 'shippedAt',
            'shipped_at'     => 'shippedAt',
            'storeid'        => 'storeId',
        ];

        $out = [
            'orderId'        => null,
            'orderNumber'    => null,
            'trackingNumber' => null,
            'carrierCode'    => null,
            'carrierName'    => null,
            'shippedAt'      => null,
            'storeId'        => null,
        ];

        foreach ($index as $normKey => $value) {
            if (isset($map[$normKey])) {
                $unified = $map[$normKey];
                $out[$unified] = $this->cleanCell($value);
            }
        }

        // Fallback to exact given headers
        $out['orderId']        = $out['orderId']        ?? $this->cleanCell($assoc['Order Id']        ?? null);
        $out['orderNumber']    = $out['orderNumber']    ?? $this->cleanCell($assoc['Order Number']    ?? null);
        $out['trackingNumber'] = $out['trackingNumber'] ?? $this->cleanCell($assoc['Tracking Number'] ?? null);
        $out['carrierCode']    = $out['carrierCode']    ?? $this->cleanCell($assoc['Carrier Code']    ?? null);
        $out['carrierName']    = $out['carrierName']    ?? $this->cleanCell($assoc['Carrier Name']    ?? null);
        $out['shippedAt']      = $out['shippedAt']      ?? $this->cleanCell($assoc['Shipped_at']      ?? null);
        $out['storeId']        = $out['storeId']        ?? $this->cleanCell($assoc['Store Id']        ?? null);

        return $out;
    }

    private function normalizeKey(string $k): string
    {
        $k = mb_strtolower($k);
        return str_replace([' ', '_', '#', '.', '-'], '', $k);
    }

    private function cleanCell($value): ?string
    {
        if ($value === null) return null;
        if (is_string($value)) {
            $v = trim($value);
            return $v === '' ? null : $v;
        }
        return trim((string) $value) ?: null;
    }

    private function toIntOrNull($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int) $v;
        $digits = preg_replace('/\D+/', '', (string) $v);
        return $digits !== '' ? (int) $digits : null;
    }

    private function normalizeOrderNumber(string $v): string
    {
        // Typical cleanup: remove a single leading "#", trim spaces
        $v = trim($v);
        if (strlen($v) && $v[0] === '#') {
            $v = ltrim($v, '#');
        }
        return trim($v);
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') return null;

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') return null;
            $ts = strtotime($value);
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }

        return null;
    }

    private function debugIdentifiers(array $data): array
    {
        return [
            'storeId'     => $data['storeId'] ?? null,
            'orderId'     => $data['orderId'] ?? null,
            'orderNumber' => $data['orderNumber'] ?? null,
        ];
    }
}
