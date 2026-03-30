<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductExport implements FromCollection, WithHeadings, WithMapping
{
    protected $storeId;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function collection()
    {
        return Product::with([
            'brand',
            'categories.parent',
            'content',
            'identifiers',
            'seo',
            'images'
        ])
            ->where('store_id', $this->storeId)
            ->get()
            ->map(function ($product) {
                // ✅ Cast dimensions safely
                $product->dimensions = is_array($product->dimensions)
                    ? $product->dimensions
                    : (json_decode($product->dimensions, true) ?? []);

                // ✅ Cast custom_fields safely
                $product->custom_fields = is_array($product->custom_fields)
                    ? $product->custom_fields
                    : (json_decode($product->custom_fields, true) ?? []);

                return $product;
            });
    }

    public function map($product): array
    {
        try {
            $primaryImage = $product->images->firstWhere('is_primary', true);

            return [
                $product->item_type ?? '',
                '',
                $product->name ?? '',
                $product->sku ?? '',
                optional($product->brand)->name ?? '',
                $this->getCategoryPath($product) ?? '',
                $product->price ?? '',
                $product->cost_price ?? '',
                $product->sale_price ?? '',
                $product->fixed_shipping_cost ?? '',
                $this->boolOut($product->free_shipping),
                $this->boolOut($product->allow_purchase),
                $product->item_type ?? '',
                $product->product_type ?? '',
                optional($product->content)->description ?? '',
                optional($product->content)->warranty ?? '',
                optional($product->content)->availability_text ?? '',
                $product->option_set ?? '',
                $product->option_set_align ?? '',
                $product->dimensions['weight'] ?? '',
                $product->dimensions['width'] ?? '',
                $product->dimensions['height'] ?? '',
                $product->dimensions['depth'] ?? '',
                optional($product->seo)->page_title ?? '',
                optional($product->seo)->product_url ?? '',
                optional($product->seo)->meta_description ?? '',
                optional($product->seo)->meta_keywords ?? '',
                $this->boolOut(optional($product->seo)->redirect_old_url),
                optional($product->identifiers)->mpn ?? '',
                optional($product->identifiers)->gtin ?? '',
                optional($product->identifiers)->upc ?? '',
                optional($product->identifiers)->bpn ?? '',
                $product->condition ?? '',
                $this->boolOut($product->show_condition),
                $this->boolOut($product->track_inventory),
                $product->current_stock ?? '',
                $product->low_stock ?? '',
                $this->formatCustomFields($product->custom_fields),
                $primaryImage->path ?? '',
                $this->boolOut($product->visible_on_storefront),
                $product->sort_order ?? '',
                $product->search_keywords ?? '',
                $product->tax_class ?? '',
                $product->tax_provider_code ?? '',
                $this->boolOut($product->stop_processing_rules),
            ];
        } catch (\Exception $e) {
            // ✅ If one product fails, return empty row instead of crashing
            return array_fill(0, count($this->headings()), 'ERROR: ' . $e->getMessage());
        }
    }

    public function headings(): array
    {
        return [
            'Item Type',
            'Product ID',
            'Product Name',
            'Product Code/SKU',
            'Brand Name',
            'Category',
            'Retail Price',
            'Cost Price',
            'Sale Price',
            'Fixed Shipping Cost',
            'Free Shipping',
            'Allow Purchases?',
            'Item Type',
            'Product Type',
            'Product Description',
            'Product Warranty',
            'Product Availability',
            'Option Set',
            'Option Set Align',
            'Product Weight',
            'Product Width',
            'Product Height',
            'Product Depth',
            'Page Title',
            'Product URL',
            'Meta Description',
            'Meta Keywords',
            'Redirect Old URL?',
            'GPS Manufacturer Part Number',
            'GPS Global Trade Item Number',
            'Product UPC/EAN',
            'Bin Picking Number',
            'Product Condition',
            'Show Product Condition?',
            'Track Inventory',
            'Current Stock Level',
            'Low Stock Level',
            'Product Custom Fields',
            'Product Image File - 1',
            'Product Visible?',
            'Sort Order',
            'Search Keywords',
            'Product Tax Class',
            'Tax Provider Tax Code',
            'Stop Processing Rules',
        ];
    }

    private function formatCustomFields($fields)
    {
        if (empty($fields) || !is_array($fields)) return '';

        return collect($fields)->map(function ($val, $key) {
            if (is_array($val)) {
                $val = json_encode($val);
            }
            return "{$key}={$val}";
        })->implode('; ');
    }

    private function boolOut($val)
    {
        return $val ? 'Yes' : 'No';
    }

    private function getCategoryPath($product)
    {
        try {
            $category = $product->categories->first();
            if (!$category) return '';

            $names  = [];
            $limit  = 10; // ✅ prevent infinite loop
            $count  = 0;

            while ($category && $count < $limit) {
                array_unshift($names, $category->name);
                $category = $category->parent;
                $count++;
            }

            return implode('/', $names);
        } catch (\Exception $e) {
            return '';
        }
    }
}
