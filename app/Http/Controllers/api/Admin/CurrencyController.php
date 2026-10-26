<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Currency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class CurrencyController extends Controller
{
    /**
     * Store ID header se lo (aapke Category controller jaisa)
     */
    private function getStoreId()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status'  => false,
                'message' => 'Missing store-id in headers.',
            ], 400));
        }

        return $storeId;
    }

    /**
     * Currencies List (Default + Additional)
     */
    public function index()
    {
        $storeId = $this->getStoreId();

        $currencies = Currency::where('store_id', $storeId)
            ->orderBy('is_default', 'desc')
            ->orderBy('code')
            ->get();

        $default    = $currencies->firstWhere('is_default', true);
        $additional = $currencies->where('is_default', false)->values();

        return response()->json([
            'status' => true,
            'data'   => [
                'default'    => $default,
                'additional' => $additional,
            ],
        ]);
    }

    /**
     * Currency Codes Dropdown (200+ currencies)
     * API: fawazahmed0/currency-api
     */
    public function options()
    {
        $options = Cache::remember('currency_options_full_v3', now()->addDays(7), function () {
            try {
                $response = Http::timeout(20)->get(
                    'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies.json'
                );

                if (!$response->successful()) {
                    return [];
                }

                $currencies = $response->json();

                return collect($currencies)
                    ->filter(fn($name, $code) => !empty($name) && strlen($code) === 3) // sirf 3 letter codes
                    ->map(fn($name, $code) => [
                        'code'  => strtoupper($code),
                        'name'  => $name,
                        'label' => strtoupper($code) . ' - ' . $name,
                    ])
                    ->sortBy('code')
                    ->values()
                    ->toArray();
            } catch (\Exception $e) {
                \Log::error('Currency API failed: ' . $e->getMessage());
                return [];
            }
        });

        return response()->json([
            'status' => true,
            'data'   => $options,
        ]);
    }

    /**
     * Countries Dropdown (250+ countries)
     * API: countries.dev
     */
    public function countries()
    {
        $countries = Cache::remember('countries_list_v3', now()->addDays(30), function () {
            try {
                $response = Http::timeout(20)->get(
                    'https://countries.dev/countries?fields=name,alpha2Code'
                );

                if (!$response->successful()) {
                    return [];
                }

                $data = $response->json();

                if (!is_array($data)) {
                    return [];
                }

                return collect($data)
                    ->filter(fn($c) => isset($c['alpha2Code']) && isset($c['name']))
                    ->map(fn($c) => [
                        'code' => $c['alpha2Code'],
                        'name' => $c['name'],
                    ])
                    ->sortBy('name')
                    ->values()
                    ->toArray();
            } catch (\Exception $e) {
                \Log::error('Countries API failed: ' . $e->getMessage());
                return [];
            }
        });

        return response()->json([
            'status' => true,
            'data'   => $countries,
        ]);
    }

    /**
     * Add New Currency
     */
    public function store(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'code'                  => 'required|string|size:3',
            'name'                  => 'required|string|max:100',
            'symbol'                => 'nullable|string|max:10',
            'symbol_location'       => 'in:left,right',
            'decimal_token'         => 'nullable|string|size:1',
            'thousands_token'       => 'nullable|string|size:1',
            'decimal_places'        => 'integer|min:0|max:4',
            'exchange_rate'         => 'nullable|numeric|min:0',
            'is_transactional'      => 'boolean',
            'auto_update'           => 'boolean',
            'default_for_countries' => 'nullable|array',
            'enabled'               => 'boolean',
        ]);

        // Duplicate check (same store)
        if (Currency::where('store_id', $storeId)->where('code', $validated['code'])->exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'This currency is already added for this store.',
            ], 422);
        }

        $currency = Currency::create(array_merge($validated, [
            'store_id'   => $storeId,
            'is_default' => false,
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Currency added successfully',
            'data'    => $currency,
        ], 201);
    }

    /**
     * Update Currency
     */
    public function update(Request $request, Currency $currency)
    {
        $storeId = $this->getStoreId();

        if ($currency->store_id != $storeId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:100',
            'symbol'                => 'nullable|string|max:10',
            'symbol_location'       => 'in:left,right',
            'decimal_token'         => 'nullable|string|size:1',
            'thousands_token'       => 'nullable|string|size:1',
            'decimal_places'        => 'integer|min:0|max:4',
            'exchange_rate'         => 'nullable|numeric|min:0',
            'is_transactional'      => 'boolean',
            'auto_update'           => 'boolean',
            'default_for_countries' => 'nullable|array',
            'enabled'               => 'boolean',
        ]);

        $currency->update($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Currency updated successfully',
            'data'    => $currency->fresh(),
        ]);
    }

    /**
     * Set as Default Currency
     */
    public function setAsDefault(Currency $currency)
    {
        $storeId = $this->getStoreId();

        if ($currency->store_id != $storeId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        // Pehle wali default hatao
        Currency::where('store_id', $storeId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Nayi default set karo
        $currency->update([
            'is_default'       => true,
            'is_transactional' => true,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Currency set as default successfully',
            'data'    => $currency->fresh(),
        ]);
    }

    /**
     * Delete Currency
     */
    public function destroy(Currency $currency)
    {
        $storeId = $this->getStoreId();

        if ($currency->store_id != $storeId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($currency->is_default) {
            return response()->json([
                'status'  => false,
                'message' => 'Default currency cannot be deleted',
            ], 422);
        }

        $currency->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Currency deleted successfully',
        ]);
    }

    /**
     * Get Current Exchange Rate
     * Example: /api/currencies/current-rate?from=USD&to=AED
     */
    public function getCurrentRate(Request $request)
    {
        $request->validate([
            'from' => 'required|string|size:3',
            'to'   => 'required|string|size:3',
        ]);

        $from = strtoupper($request->from);
        $to   = strtoupper($request->to);

        try {
            $response = Http::timeout(10)->get("https://api.frankfurter.dev/v2/rate/{$from}/{$to}");

            if ($response->successful()) {
                return response()->json([
                    'status' => true,
                    'from'   => $from,
                    'to'     => $to,
                    'rate'   => $response->json('rate'),
                    'date'   => $response->json('date'),
                ]);
            }

            return response()->json(['status' => false, 'message' => 'Failed to fetch rate'], 503);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Rate service unavailable'], 503);
        }
    }
}
