<?php

namespace Tests\Feature;

use App\Models\StorePaymentMethod;
use App\Models\StorePaymentMethodProvider;
use App\Services\StripeAccountDisconnector;
use App\Services\StripePaymentSettingsResolver;
use App\Services\TenantCredentialResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class StorefrontPaymentMethodsTest extends TestCase
{
    private const STORE_HASH = 'store-abcdefghijkl';
    private const STORE_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.central' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.connections.tenant' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('central');
        DB::purge('tenant');

        Schema::connection('central')->create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_hash')->unique();
            $table->unsignedBigInteger('legacy_store_id')->nullable();
            $table->string('provisioning_status')->default('active');
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_livemode')->nullable();
        });
        DB::connection('central')->table('stores')->insert([
            'store_hash' => self::STORE_HASH,
            'legacy_store_id' => self::STORE_ID,
        ]);

        Schema::connection('tenant')->create('store_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('method_code');
            $table->string('category');
            $table->string('display_name');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_configured')->default(false);
            $table->json('available_countries')->nullable();
            $table->json('available_currencies')->nullable();
            $table->json('configuration')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('store_payment_method_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('method_code');
            $table->string('provider_code');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_available')->default(false);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        $store = DB::connection('central')->table('stores')
            ->where('store_hash', self::STORE_HASH)
            ->first();
        $tenantResolver = Mockery::mock(TenantCredentialResolver::class);
        $tenantResolver->shouldReceive('connectByStoreHash')
            ->with(self::STORE_HASH)
            ->andReturn($store);
        $tenantResolver->shouldReceive('disconnect')->zeroOrMoreTimes();
        $this->app->instance(TenantCredentialResolver::class, $tenantResolver);
    }

    public function test_only_enabled_configured_methods_are_returned_and_credentials_are_not_exposed(): void
    {
        $this->method('bank_deposit', true, true, [
            'account_information' => 'Send funds to account 123.',
            'private_note' => 'DO NOT EXPOSE',
        ], ['api_secret' => 'PRIVATE-CREDENTIAL']);
        $this->method('cash_on_delivery', false, true);
        $this->method('paypal', true, false, [], ['client_secret' => 'PAYPAL-SECRET']);

        $response = $this->getJson('/api/web/payment-methods', [
            'X-Store-Hash' => self::STORE_HASH,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.storeId', self::STORE_ID)
            ->assertJsonCount(1, 'data.paymentMethods')
            ->assertJsonPath('data.paymentMethods.0.methodCode', 'bank_deposit')
            ->assertJsonPath('data.paymentMethods.0.paymentInstructions', 'Send funds to account 123.')
            ->assertJsonPath('data.paymentMethods.0.isEnabled', true)
            ->assertJsonPath('data.paymentMethods.0.isConfigured', true)
            ->assertDontSee('PRIVATE-CREDENTIAL')
            ->assertDontSee('PAYPAL-SECRET')
            ->assertDontSee('DO NOT EXPOSE');
    }

    public function test_currency_and_country_filters_apply_to_saved_availability(): void
    {
        $this->method(
            'bank_deposit',
            true,
            true,
            ['account_information' => 'Bank instructions'],
            [],
            ['US'],
            ['USD', 'CAD']
        );

        $this->getJson('/api/web/payment-methods?currency=usd&country=us', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()->assertJsonCount(1, 'data.paymentMethods');

        $this->getJson('/api/web/payment-methods?currency=eur&country=us', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()->assertJsonCount(0, 'data.paymentMethods');

        $this->getJson('/api/web/payment-methods?currency=usd&country=ca', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()->assertJsonCount(0, 'data.paymentMethods');
    }

    public function test_methods_are_scoped_to_the_store_resolved_from_the_hash(): void
    {
        $this->method('bank_deposit', true, true, [
            'account_information' => 'This store instructions',
        ]);
        $this->method('cash_on_delivery', true, true, [
            'delivery_information' => 'Another store instructions',
        ], [], null, null, 11);

        $this->getJson('/api/web/payment-methods', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()
            ->assertJsonCount(1, 'data.paymentMethods')
            ->assertJsonPath('data.paymentMethods.0.methodCode', 'bank_deposit')
            ->assertDontSee('Another store instructions');
    }

    public function test_stripe_returns_only_enabled_public_payment_options(): void
    {
        DB::connection('central')->table('stores')
            ->where('store_hash', self::STORE_HASH)
            ->update([
                'stripe_account_id' => 'acct_public_id',
                'stripe_livemode' => false,
            ]);

        $this->method('stripe', true, true, [], [
            'stripe_user_id' => 'acct_public_id',
            'access_token' => 'STRIPE-ACCESS-SECRET',
            'livemode' => false,
        ]);
        StorePaymentMethodProvider::create([
            'store_id' => self::STORE_ID,
            'method_code' => 'apple_pay',
            'provider_code' => 'stripe',
            'is_enabled' => true,
            'is_available' => true,
        ]);

        $stripeResolver = Mockery::mock(StripePaymentSettingsResolver::class);
        $stripeResolver->shouldReceive('resolve')->with(self::STORE_ID, null)->andReturn([
            'active' => true,
            'settings' => [
                'stripeLinkEnabled' => true,
                'paymentMethods' => [
                    'card' => true,
                    'applePay' => true,
                    'googlePay' => true,
                    'link' => true,
                ],
            ],
        ]);
        $this->app->instance(StripePaymentSettingsResolver::class, $stripeResolver);

        $this->getJson('/api/web/payment-methods', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()
            ->assertJsonPath('data.paymentMethods.0.methodCode', 'stripe')
            ->assertJsonPath('data.paymentMethods.0.paymentOptions.card', true)
            ->assertJsonPath('data.paymentMethods.0.paymentOptions.applePay', true)
            ->assertJsonPath('data.paymentMethods.0.paymentOptions.googlePay', false)
            ->assertJsonPath('data.paymentMethods.0.paymentOptions.link', true)
            ->assertDontSee('STRIPE-ACCESS-SECRET')
            ->assertDontSee('acct_public_id');
    }

    public function test_disconnected_stripe_is_not_returned(): void
    {
        $this->method('stripe', true, true);

        $this->getJson('/api/web/payment-methods', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()->assertJsonCount(0, 'data.paymentMethods');
    }

    public function test_currency_configuration_updates_preserve_other_currencies_and_legacy_settings(): void
    {
        $method = $this->method('cash_on_delivery', true, true, [
            'delivery_information' => 'Legacy instructions',
        ], [], null, ['*']);
        $service = app(\App\Services\PaymentSettingsService::class);

        $service->saveCurrencyConfiguration(self::STORE_ID, 'cash_on_delivery', 'EUR', [
            'delivery_information' => 'EUR instructions',
        ]);
        $service->saveCurrencyConfiguration(self::STORE_ID, 'cash_on_delivery', 'USD', [
            'delivery_information' => 'USD instructions',
        ]);
        $service->saveCurrencyConfiguration(self::STORE_ID, 'cash_on_delivery', 'USD', [
            'delivery_information' => 'Updated USD instructions',
        ]);

        $stored = $method->fresh()->configuration;
        $this->assertSame('Legacy instructions', $stored['delivery_information']);
        $this->assertSame(
            'Updated USD instructions',
            $stored['currencySettings']['USD']['delivery_information']
        );
        $this->assertSame(
            'EUR instructions',
            $stored['currencySettings']['EUR']['delivery_information']
        );
        $this->assertTrue($stored['currencySettings']['USD']['isEnabled']);
        $this->assertTrue($stored['currencySettings']['EUR']['isConfigured']);
    }

    public function test_disabling_one_currency_clears_only_its_settings_and_keeps_stripe_credentials(): void
    {
        $credentials = [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ];
        $method = $this->method('stripe', true, true, [
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'USD SHOP',
                    'payment_methods' => ['card' => true],
                ],
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'EUR SHOP',
                    'payment_methods' => ['card' => false],
                ],
            ],
        ], $credentials, null, ['*']);

        app(\App\Services\PaymentSettingsService::class)
            ->disableCurrency(self::STORE_ID, 'stripe', 'USD');

        $stored = $method->fresh();
        $this->assertSame($credentials, $stored->credentials);
        $this->assertSame(
            ['isEnabled' => false, 'isConfigured' => false],
            $stored->configuration['currencySettings']['USD']
        );
        $this->assertSame(
            'EUR SHOP',
            $stored->configuration['currencySettings']['EUR']['statement_descriptor']
        );
        $this->assertTrue($stored->is_enabled);
        $this->assertTrue($stored->is_configured);
    }

    public function test_storefront_returns_only_requested_currency_settings_and_status(): void
    {
        $method = $this->method('cash_on_delivery', false, false, [], [], null, ['*']);
        $method->configuration = [
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'delivery_information' => 'USD-only instructions',
                ],
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'delivery_information' => 'EUR-only instructions',
                ],
            ],
        ];
        $method->save();

        $this->getJson('/api/web/payment-methods?currency=USD', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()
            ->assertJsonCount(1, 'data.paymentMethods')
            ->assertJsonPath('data.paymentMethods.0.paymentInstructions', 'USD-only instructions')
            ->assertJsonPath('data.paymentMethods.0.isEnabled', true)
            ->assertDontSee('EUR-only instructions')
            ->assertDontSee('currencySettings');

        app(\App\Services\PaymentSettingsService::class)
            ->disableCurrency(self::STORE_ID, 'cash_on_delivery', 'USD');

        $this->getJson('/api/web/payment-methods?currency=USD', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()->assertJsonCount(0, 'data.paymentMethods');

        $this->getJson('/api/web/payment-methods?currency=EUR', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()
            ->assertJsonCount(1, 'data.paymentMethods')
            ->assertJsonPath('data.paymentMethods.0.paymentInstructions', 'EUR-only instructions');
    }

    public function test_dashboard_selected_currency_reads_hide_other_currency_configuration(): void
    {
        $method = $this->method('cash_on_delivery', false, false, [], [], null, ['*']);
        $method->configuration = [
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'delivery_information' => 'USD dashboard instructions',
                ],
                'EUR' => [
                    'isEnabled' => false,
                    'isConfigured' => false,
                    'delivery_information' => 'EUR dashboard instructions',
                ],
            ],
        ];
        $method->save();

        $this->withoutMiddleware()
            ->getJson('/api/dashboard/payment-settings/cash_on_delivery?currency=USD', [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.isEnabled', true)
            ->assertJsonPath('data.configuration.deliveryInformation', 'USD dashboard instructions')
            ->assertDontSee('EUR dashboard instructions')
            ->assertDontSee('currencySettings');
    }

    public function test_dashboard_stripe_configuration_reads_selected_currency_only(): void
    {
        $this->method('stripe', true, true, [
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'USD STORE',
                    'payment_methods' => ['card' => true],
                ],
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'EUR STORE',
                    'payment_methods' => ['card' => false],
                ],
            ],
        ], [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ]);

        $this->withoutMiddleware()
            ->getJson('/api/dashboard/payment-settings/stripe/configuration?currency=USD', [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.configuration.statementDescriptor', 'USD STORE')
            ->assertJsonPath('data.configuration.paymentMethods.card', true)
            ->assertDontSee('EUR STORE')
            ->assertDontSee('currencySettings')
            ->assertDontSee('SHARED-STRIPE-TOKEN');
    }

    public function test_stripe_resolver_uses_currency_specific_settings_with_shared_credentials(): void
    {
        config(['services.stripe.connect.dynamic_settings_enabled' => true]);
        $this->method('stripe', true, true, [
            'statement_descriptor' => 'Legacy descriptor',
            'payment_methods' => ['card' => true],
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'USD descriptor',
                    'payment_methods' => ['card' => true],
                ],
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'EUR descriptor',
                    'payment_methods' => ['card' => false],
                ],
            ],
        ], [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ]);

        $resolver = app(StripePaymentSettingsResolver::class);
        $usd = $resolver->resolve(self::STORE_ID, 'USD');
        $eur = $resolver->resolve(self::STORE_ID, 'EUR');

        $this->assertTrue($usd['active']);
        $this->assertSame('USD descriptor', $usd['settings']['statementDescriptor']);
        $this->assertTrue($usd['settings']['paymentMethods']['card']);
        $this->assertTrue($eur['active']);
        $this->assertSame('EUR descriptor', $eur['settings']['statementDescriptor']);
        $this->assertFalse($eur['settings']['paymentMethods']['card']);
        $this->assertStringNotContainsString(
            'SHARED-STRIPE-TOKEN',
            json_encode([$usd, $eur])
        );
    }

    public function test_stripe_currency_configuration_update_preserves_other_currency_and_credentials(): void
    {
        $credentials = [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ];
        $method = $this->method('stripe', true, true, [
            'currencySettings' => [
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'statement_descriptor' => 'EUR descriptor',
                    'payment_methods' => ['card' => false],
                ],
            ],
        ], $credentials);

        $this->withoutMiddleware()
            ->putJson('/api/dashboard/payment-settings/stripe/configuration?currency=USD', [
                'configuration' => [
                    'statementDescriptor' => 'USD descriptor',
                    'paymentMethods' => ['card' => true],
                ],
            ], [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.configuration.statementDescriptor', 'USD descriptor')
            ->assertJsonPath('data.configuration.paymentMethods.card', true)
            ->assertDontSee('EUR descriptor')
            ->assertDontSee('SHARED-STRIPE-TOKEN');

        $stored = $method->fresh();
        $this->assertSame($credentials, $stored->credentials);
        $this->assertSame(
            'USD descriptor',
            $stored->configuration['currencySettings']['USD']['statement_descriptor']
        );
        $this->assertSame(
            'EUR descriptor',
            $stored->configuration['currencySettings']['EUR']['statement_descriptor']
        );
        $this->assertArrayNotHasKey(
            'account_id',
            $stored->configuration['currencySettings']['USD']
        );
        $this->assertArrayNotHasKey(
            'livemode',
            $stored->configuration['currencySettings']['USD']
        );
    }

    public function test_generic_dashboard_save_changes_only_the_selected_currency(): void
    {
        $method = $this->method('cash_on_delivery', true, true, [
            'delivery_information' => 'Legacy instructions',
            'currencySettings' => [
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'delivery_information' => 'EUR instructions',
                ],
            ],
        ], [], null, ['*']);

        $this->withoutMiddleware()
            ->postJson('/api/dashboard/payment-settings/cash_on_delivery', [
                'currency' => 'USD',
                'configuration' => [
                    'deliveryInformation' => 'USD instructions',
                ],
            ], [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.configuration.deliveryInformation', 'USD instructions')
            ->assertDontSee('EUR instructions')
            ->assertDontSee('currencySettings');

        $this->assertSame(
            'USD instructions',
            $method->fresh()->configuration['currencySettings']['USD']['delivery_information']
        );
        $this->assertSame(
            'EUR instructions',
            $method->fresh()->configuration['currencySettings']['EUR']['delivery_information']
        );
    }

    public function test_currency_specific_stripe_disable_does_not_disconnect_oauth(): void
    {
        $credentials = [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ];
        $method = $this->method('stripe', true, true, [
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'payment_methods' => ['card' => true],
                ],
                'EUR' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'payment_methods' => ['card' => true],
                ],
            ],
        ], $credentials, null, ['*']);

        $disconnector = Mockery::mock(StripeAccountDisconnector::class);
        $disconnector->shouldNotReceive('disconnectForStoreId');
        $this->app->instance(StripeAccountDisconnector::class, $disconnector);

        $this->withoutMiddleware()
            ->patchJson('/api/dashboard/payment-settings/stripe/disable', [
                'currency' => 'USD',
            ], [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.isEnabled', false)
            ->assertJsonPath('data.isConfigured', false);

        $stored = $method->fresh();
        $this->assertSame($credentials, $stored->credentials);
        $this->assertTrue($stored->is_enabled);
        $this->assertSame(
            ['isEnabled' => true, 'isConfigured' => true, 'payment_methods' => ['card' => true]],
            $stored->configuration['currencySettings']['EUR']
        );
    }

    public function test_global_stripe_disable_still_uses_the_existing_disconnector(): void
    {
        $this->method('stripe', true, true, [], [
            'stripe_user_id' => 'acct_shared',
            'access_token' => 'SHARED-STRIPE-TOKEN',
            'livemode' => false,
        ]);

        $disconnector = Mockery::mock(StripeAccountDisconnector::class);
        $disconnector->shouldReceive('disconnectForStoreId')
            ->once()
            ->with(self::STORE_ID);
        $this->app->instance(StripeAccountDisconnector::class, $disconnector);

        $this->withoutMiddleware()
            ->patchJson('/api/dashboard/payment-settings/stripe/disable', [], [
                'storeId' => (string) self::STORE_ID,
            ])->assertOk()
            ->assertJsonPath('data.isEnabled', false)
            ->assertJsonPath('data.isConfigured', false);
    }

    public function test_legacy_global_configuration_is_used_when_currency_entry_is_missing(): void
    {
        $this->method('cash_on_delivery', true, true, [
            'delivery_information' => 'Legacy COD instructions',
        ], [], null, ['*']);

        $this->getJson('/api/web/payment-methods?currency=GBP', [
            'X-Store-Hash' => self::STORE_HASH,
        ])->assertOk()
            ->assertJsonCount(1, 'data.paymentMethods')
            ->assertJsonPath('data.paymentMethods.0.paymentInstructions', 'Legacy COD instructions');
    }

    public function test_global_disable_still_clears_legacy_and_currency_configuration(): void
    {
        $method = $this->method('cash_on_delivery', true, true, [
            'delivery_information' => 'Legacy instructions',
            'currencySettings' => [
                'USD' => [
                    'isEnabled' => true,
                    'isConfigured' => true,
                    'delivery_information' => 'USD instructions',
                ],
            ],
        ], ['legacy_credential' => 'legacy']);

        app(\App\Services\PaymentSettingsService::class)
            ->disableMethod(self::STORE_ID, 'cash_on_delivery');

        $stored = $method->fresh();
        $this->assertFalse($stored->is_enabled);
        $this->assertFalse($stored->is_configured);
        $this->assertNull($stored->configuration);
        $this->assertNull($stored->credentials);
    }

    private function method(
        string $code,
        bool $enabled,
        bool $configured,
        array $configuration = [],
        array $credentials = [],
        ?array $countries = null,
        ?array $currencies = null,
        int $storeId = self::STORE_ID
    ): StorePaymentMethod {
        return StorePaymentMethod::create([
            'store_id' => $storeId,
            'method_code' => $code,
            'category' => $code === 'stripe' || $code === 'paypal'
                ? 'online'
                : 'offline',
            'display_name' => ucfirst(str_replace('_', ' ', $code)),
            'is_enabled' => $enabled,
            'is_configured' => $configured,
            'available_countries' => $countries === null
                ? null
                : ['all_countries' => false, 'countries' => $countries],
            'available_currencies' => $currencies,
            'configuration' => $configuration,
            'credentials' => $credentials,
        ]);
    }
}
