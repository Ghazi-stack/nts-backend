<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\StorePaymentMethod;
use App\Services\PaymentSettingsService;
use App\Services\StripeConnectAccountResolver;
use App\Services\StripePaymentSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class StorefrontPaymentMethodController extends Controller
{
    public function __construct(
        private PaymentSettingsService $paymentSettings,
        private StripePaymentSettingsResolver $stripeSettings,
        private StripeConnectAccountResolver $stripeConnect
    ) {}

    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $storeId = (int) current_store_id();
        if ($storeId < 1) {
            return response()->json([
                'status' => false,
                'message' => 'The resolved store has no valid tenant store ID.',
            ], 400);
        }

        $currency = $request->query('currency');
        $country = $request->query('country');
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;
        $country = $country !== null ? strtoupper(trim($country)) : null;

        try {
            $methods = $this->paymentSettings->getStorefrontMethods(
                $storeId,
                $currency,
                $country
            );

            $data = [];
            $stripeResolved = null;

            foreach ($methods as $method) {
                $methodData = $this->formatMethod(
                    $method,
                    $request,
                    $storeId,
                    $currency,
                    $stripeResolved
                );

                if ($methodData !== null) {
                    $data[] = $methodData;
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Storefront payment methods retrieved successfully.',
                'data' => [
                    'storeId' => $storeId,
                    'currency' => $currency,
                    'country' => $country,
                    'paymentMethods' => $data,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve storefront payment methods.',
            ], 500);
        }
    }

    private function formatMethod(
        StorePaymentMethod $method,
        Request $request,
        int $storeId,
        ?string $currency,
        ?array &$stripeResolved
    ): ?array {
        $methodCode = $method->method_code;
        $catalog = $this->paymentSettings->getCatalogMethod($methodCode);
        $effective = $this->paymentSettings->getEffectiveSettings(
            $method,
            $currency
        );

        // Do not publish unknown methods whose storefront behavior is undefined.
        if (!$catalog) {
            return null;
        }

        // PayPal's current settings only establish that credentials were
        // entered; this repository has no verification or payment flow for it.
        if ($methodCode === 'paypal') {
            return null;
        }

        $paymentOptions = null;
        if ($methodCode === 'stripe') {
            try {
                // Match the account and environment against the central store
                // registry, as the existing Stripe payment flow does.
                $this->stripeConnect->optionsForRequest($request);
            } catch (Throwable) {
                return null;
            }

            $stripeResolved ??= $this->stripeSettings->resolve(
                $storeId,
                $currency
            );

            if (!($stripeResolved['active'] ?? false)) {
                return null;
            }

            $settings = $stripeResolved['settings'] ?? [];
            $configuredOptions = $settings['paymentMethods'] ?? [];
            $wallets = collect([
                'apple_pay' => 'applePay',
                'google_pay' => 'googlePay',
            ]);

            $walletAvailable = [];
            foreach ($wallets as $walletCode => $optionName) {
                $providers = $this->paymentSettings->getWalletProviders(
                    $storeId,
                    $walletCode
                );
                $stripeProvider = collect($providers)->firstWhere(
                    'providerCode',
                    'stripe'
                );
                $walletAvailable[$optionName] = (bool) (
                    ($configuredOptions[$optionName] ?? false)
                    && ($stripeProvider['isEnabled'] ?? false)
                    && ($stripeProvider['isAvailable'] ?? false)
                    && ($stripeProvider['isConnected'] ?? false)
                );
            }

            $paymentOptions = [
                'card' => (bool) ($configuredOptions['card'] ?? false),
                'applePay' => $walletAvailable['applePay'],
                'googlePay' => $walletAvailable['googlePay'],
                'link' => (bool) (
                    ($settings['stripeLinkEnabled'] ?? false)
                    && ($configuredOptions['link'] ?? false)
                ),
            ];

            if (!in_array(true, $paymentOptions, true)) {
                return null;
            }
        }

        $result = [
            'methodCode' => $methodCode,
            'category' => $method->category,
            'displayName' => $method->display_name ?: $catalog['displayName'],
            'description' => $catalog['description'] ?? null,
            'icon' => $catalog['icon'] ?? null,
            'isEnabled' => true,
            'isConfigured' => true,
            'isAvailable' => true,
            'availableCountries' => $this->formatCountries(
                $method->available_countries
            ),
            'supportedCurrencies' => array_values(
                $method->available_currencies ?? []
            ),
        ];

        if ($paymentOptions !== null) {
            $result['paymentOptions'] = $paymentOptions;
        }

        // These merchant-authored instructions are intended for shoppers.
        // Other configuration, including credentials and provider settings,
        // is deliberately excluded from the storefront response.
        $instructionKeys = [
            'bank_deposit' => 'account_information',
            'cash_on_delivery' => 'delivery_information',
            'check' => 'payment_information',
            'money_order' => 'payment_information',
            'pay_in_store' => 'payment_information',
        ];
        $instructionKey = $instructionKeys[$methodCode] ?? null;
        $instructions = $instructionKey
            ? ($effective['configuration'][$instructionKey] ?? null)
            : null;

        if (is_string($instructions) && trim($instructions) !== '') {
            $result['paymentInstructions'] = $instructions;
        }

        return $result;
    }

    private function formatCountries(?array $availability): ?array
    {
        if ($availability === null) {
            return null;
        }

        return [
            'allCountries' => (bool) ($availability['all_countries'] ?? false),
            'countries' => array_values($availability['countries'] ?? []),
        ];
    }
}
