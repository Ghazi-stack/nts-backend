<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Orders
            ['group' => 'orders', 'name' => 'all_orders',    'label' => 'All Orders',    'slug' => 'orders.all',       'sort_order' => 1],
            ['group' => 'orders', 'name' => 'add_order',     'label' => 'Add',           'slug' => 'orders.add',       'sort_order' => 2],
            ['group' => 'orders', 'name' => 'search_orders', 'label' => 'Search',        'slug' => 'orders.search',    'sort_order' => 3],
            ['group' => 'orders', 'name' => 'export_orders', 'label' => 'Export',        'slug' => 'orders.export',    'sort_order' => 4],
            ['group' => 'orders', 'name' => 'draft_orders',  'label' => 'Draft Orders',  'slug' => 'orders.draft',     'sort_order' => 5],
            ['group' => 'orders', 'name' => 'shipments',     'label' => 'Shipments',     'slug' => 'orders.shipments', 'sort_order' => 6],
            ['group' => 'orders', 'name' => 'return_request', 'label' => 'Return Request', 'slug' => 'orders.return',   'sort_order' => 7],

            // Products
            ['group' => 'products', 'name' => 'all_products',    'label' => 'All Products', 'slug' => 'products.all',        'sort_order' => 1],
            ['group' => 'products', 'name' => 'add_product',     'label' => 'Add',          'slug' => 'products.add',        'sort_order' => 2],
            ['group' => 'products', 'name' => 'search_products', 'label' => 'Search',       'slug' => 'products.search',     'sort_order' => 3],
            ['group' => 'products', 'name' => 'import_products', 'label' => 'Import',       'slug' => 'products.import',     'sort_order' => 4],
            ['group' => 'products', 'name' => 'export_products', 'label' => 'Export',        'slug' => 'products.export',     'sort_order' => 5],
            ['group' => 'products', 'name' => 'categories',      'label' => 'Categories',   'slug' => 'products.categories', 'sort_order' => 6],
            ['group' => 'products', 'name' => 'reviews',          'label' => 'Reviews',      'slug' => 'products.reviews',    'sort_order' => 7],
            ['group' => 'products', 'name' => 'brands',           'label' => 'Brands',       'slug' => 'products.brands',     'sort_order' => 8],

            // Customers
            ['group' => 'customers', 'name' => 'all_customers',    'label' => 'All Customers', 'slug' => 'customers.all',    'sort_order' => 1],
            ['group' => 'customers', 'name' => 'add_customer',     'label' => 'Add',           'slug' => 'customers.add',    'sort_order' => 2],
            ['group' => 'customers', 'name' => 'search_customers', 'label' => 'Search',        'slug' => 'customers.search', 'sort_order' => 3],
            ['group' => 'customers', 'name' => 'import_customers', 'label' => 'Import',        'slug' => 'customers.import', 'sort_order' => 4],
            ['group' => 'customers', 'name' => 'export_customers', 'label' => 'Export',         'slug' => 'customers.export', 'sort_order' => 5],

            // Storefront
            ['group' => 'storefront', 'name' => 'logo',              'label' => 'Logo',              'slug' => 'storefront.logo',      'sort_order' => 1],
            ['group' => 'storefront', 'name' => 'home_page_carousel', 'label' => 'Home Page Carousel', 'slug' => 'storefront.carousel', 'sort_order' => 2],
            ['group' => 'storefront', 'name' => 'script_manager',    'label' => 'Script Manager',    'slug' => 'storefront.scripts',   'sort_order' => 3],
            ['group' => 'storefront', 'name' => 'web_pages',         'label' => 'Web Pages',         'slug' => 'storefront.pages',     'sort_order' => 4],
            ['group' => 'storefront', 'name' => 'blog',              'label' => 'Blog',              'slug' => 'storefront.blog',      'sort_order' => 5],

            // Marketing
            ['group' => 'marketing', 'name' => 'coupon_codes', 'label' => 'Coupon Codes', 'slug' => 'marketing.coupons', 'sort_order' => 1],

            // Settings
            ['group' => 'settings', 'name' => 'settings', 'label' => 'Settings', 'slug' => 'settings.all', 'sort_order' => 1],
        ];

        foreach ($permissions as $perm) {
            Permission::updateOrCreate(
                ['slug' => $perm['slug']],
                $perm
            );
        }
    }
}
