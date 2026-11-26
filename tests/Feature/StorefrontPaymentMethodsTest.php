<?php

namespace Tests\Feature;

use App\Models\StorePaymentMethod;
use App\Models\StorePaymentMethodProvider;
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
        $stripeResolver->shouldReceive('resolve')->with(self::STORE_ID)->andReturn([
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
