<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CurrencyConversionService
{
    public function convert(
        float $amount,
        string $fromCurrency,
        string $toCurrency
    ): float {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        if ($fromCurrency === $toCurrency || $amount == 0.0) {
            return round($amount, 2);
        }

        $rate = Cache::remember(
            "fx:{$fromCurrency}:{$toCurrency}",
            now()->addHours(12),
            function () use ($fromCurrency, $toCurrency) {
                $response = Http::timeout(5)->get(
                    "https://api.frankfurter.dev/v1/latest",
                    [
                        'base' => $fromCurrency,
                        'symbols' => $toCurrency,
                    ]
                );

                $response->throw();

                $rate = $response->json("rates.{$toCurrency}");

                if (!is_numeric($rate) || (float) $rate <= 0) {
                    throw new RuntimeException(
                        "Exchange rate {$fromCurrency} to {$toCurrency} unavailable."
                    );
                }

                return (float) $rate;
            }
        );

        return round($amount * $rate, 2);
    }
}
