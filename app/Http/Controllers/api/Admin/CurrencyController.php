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
     * Store ID header se lo
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
     * Currency ko camelCase response mein convert karo
     */
    private function formatCurrency($currency)
    {
        if (!$currency) return null;

        return [
            'id'                  => $currency->id,
            'storeId'             => $currency->store_id,
            'code'                => $currency->code,
            'name'                => $currency->name,
            'symbol'              => $currency->symbol,
            'symbolLocation'      => $currency->symbol_location,
            'decimalToken'        => $currency->decimal_token,
            'thousandsToken'      => $currency->thousands_token,
            'decimalPlaces'       => $currency->decimal_places,
            'exchangeRate'        => $currency->exchange_rate,
            'isDefault'           => $currency->is_default,
            'isTransactional'     => $currency->is_transactional,
            'autoUpdate'          => $currency->auto_update,
            'defaultForCountries' => $currency->default_for_countries,
            'enabled'             => $currency->enabled,
            'createdAt'           => $currency->created_at,
            'updatedAt'           => $currency->updated_at,
        ];
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
                'default'    => $this->formatCurrency($default),
                'additional' => $additional->map(fn($c) => $this->formatCurrency($c))->values(),
            ],
        ]);
    }

    /**
     * Currency Codes Dropdown (200+ currencies)
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
                    ->filter(fn($name, $code) => !empty($name) && strlen($code) === 3)
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
            'code'                => 'required|string|size:3',
            'name'                => 'required|string|max:100',
            'symbol'              => 'nullable|string|max:10',
            'symbolLocation'      => 'in:left,right',
            'decimalToken'        => 'nullable|string|size:1',
            'thousandsToken'      => 'nullable|string|size:1',
            'decimalPlaces'       => 'integer|min:0|max:4',
            'exchangeRate'        => 'nullable|numeric|min:0',
            'isTransactional'     => 'boolean',
            'autoUpdate'          => 'boolean',
            'defaultForCountries' => 'nullable|array',
            'enabled'             => 'boolean',
            'isDefault'           => 'boolean',
        ]);

        $code = strtoupper($validated['code']);

        // Duplicate check (same store)
        if (Currency::where('store_id', $storeId)->where('code', $code)->exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'This currency is already added for this store.',
            ], 422);
        }

        $isDefault = $request->boolean('isDefault', false);

        // Agar isDefault true hai to pehle wali hata do
        if ($isDefault) {
            Currency::where('store_id', $storeId)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $currency = Currency::create([
            'store_id'              => $storeId,
            'code'                  => $code,
            'name'                  => $validated['name'],
            'symbol'                => $validated['symbol'] ?? null,
            'symbol_location'       => $validated['symbolLocation'] ?? 'left',
            'decimal_token'         => $validated['decimalToken'] ?? '.',
            'thousands_token'       => $validated['thousandsToken'] ?? ',',
            'decimal_places'        => $validated['decimalPlaces'] ?? 2,
            'exchange_rate'         => $validated['exchangeRate'] ?? 1,
            'is_transactional'      => $request->boolean('isTransactional', false),
            'auto_update'           => $request->boolean('autoUpdate', true),
            'default_for_countries' => $validated['defaultForCountries'] ?? null,
            'enabled'               => $request->boolean('enabled', true),
            'is_default'            => $isDefault,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Currency added successfully',
            'data'    => $this->formatCurrency($currency),
        ], 201);
    }

    /**
     * GET /currencies/default
     * Default + Enabled currency ka data
     */
    public function getDefault()
    {
        $storeId = $this->getStoreId();

        $default = Currency::where('store_id', $storeId)
            ->where('is_default', true)
            ->where('enabled', true)
            ->first();

        if (!$default) {
            return response()->json([
                'status'  => false,
                'message' => 'No default currency found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'code'           => $default->code,
                'name'           => $default->name,
                'symbol'         => $default->symbol,
                'symbolLocation' => $default->symbol_location,
                'decimalToken'   => $default->decimal_token,
                'thousandsToken' => $default->thousands_token,
                'decimalPlaces'  => $default->decimal_places,
                'exchangeRate'   => $default->exchange_rate,
                'isDefault'      => $default->is_default,
            ],
        ]);
    }

    /**
     * Update Currency (sirf jo bhejo wahi update hoga)
     */
    public function update(Request $request, Currency $currency)
    {
        $storeId = $this->getStoreId();

        if ($currency->store_id != $storeId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'                => 'sometimes|string|max:100',
            'symbol'              => 'nullable|string|max:10',
            'symbolLocation'      => 'in:left,right',
            'decimalToken'        => 'nullable|string|size:1',
            'thousandsToken'      => 'nullable|string|size:1',
            'decimalPlaces'       => 'integer|min:0|max:4',
            'exchangeRate'        => 'nullable|numeric|min:0',
            'isTransactional'     => 'boolean',
            'autoUpdate'          => 'boolean',
            'defaultForCountries' => 'nullable|array',
            'enabled'             => 'boolean',
        ]);

        $data = [];

        if (isset($validated['name'])) $data['name'] = $validated['name'];
        if (isset($validated['symbol'])) $data['symbol'] = $validated['symbol'];
        if (isset($validated['symbolLocation'])) $data['symbol_location'] = $validated['symbolLocation'];
        if (isset($validated['decimalToken'])) $data['decimal_token'] = $validated['decimalToken'];
        if (isset($validated['thousandsToken'])) $data['thousands_token'] = $validated['thousandsToken'];
        if (isset($validated['decimalPlaces'])) $data['decimal_places'] = $validated['decimalPlaces'];
        if (isset($validated['exchangeRate'])) $data['exchange_rate'] = $validated['exchangeRate'];
        if ($request->has('isTransactional')) $data['is_transactional'] = $request->boolean('isTransactional');
        if ($request->has('autoUpdate')) $data['auto_update'] = $request->boolean('autoUpdate');
        if (isset($validated['defaultForCountries'])) $data['default_for_countries'] = $validated['defaultForCountries'];
        if ($request->has('enabled')) $data['enabled'] = $request->boolean('enabled');

        $currency->update($data);

        return response()->json([
            'status'  => true,
            'message' => 'Currency updated successfully',
            'data'    => $this->formatCurrency($currency->fresh()),
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

        Currency::where('store_id', $storeId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $currency->update([
            'is_default'       => true,
            'is_transactional' => true,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Currency set as default successfully',
            'data'    => $this->formatCurrency($currency->fresh()),
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
