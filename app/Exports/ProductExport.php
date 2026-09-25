<?php

namespace App\Exports;

use App\Models\Product;

class ProductExport
{
    public function __construct(protected int $storeId) {}

    public function headings(): array
    {
        return [
            'Product ID',
            'Product Type',
            'Code',
            'Name',
            'Brand',
            'Description',
            'Cost Price',
            'Retail Price',
            'Sale Price',
            'Calculated Price',
            'Fixed Shipping Price',
            'Free Shipping',
            'Warranty',
            'Weight',
            'Width',
            'Height',
            'Depth',
            'Allow Purchases',
            'Product Visible',
            'Product Availability',
            'Product Inventoried',
            'Stock Level',
            'Low Stock Level',
            'Date Added',
            'Date Modified',
            'Category Details',
            'Images',
            'Page Title',
            'META Keywords',
            'META Description',
            'Product Condition',
            'Product URL',
            'Redirect Old URL?',
            'Product Tax Code',
            'Product Custom Fields',
        ];
    }

    public function map($product): array
    {
        $dimensions = is_array($product->dimensions)
            ? $product->dimensions
            : (json_decode($product->dimensions, true) ?? []);

        $customFields = is_array($product->custom_fields)
            ? $product->custom_fields
            : (json_decode($product->custom_fields, true) ?? []);

        $retail = $this->num($product->price ?? 0);
        $sale   = $this->num($product->sale_price ?? 0);

        return [
            $product->id ?? '',
            $product->product_type ?? $product->item_type ?? 'P',
            $product->sku ?? '',
            $product->name ?? '',
            optional($product->brand)->name ?? '',
            optional($product->content)->description ?? '',
            $this->money($product->cost_price ?? 0),
            $this->money($retail),
            $this->money($sale),
            $this->money($sale > 0 ? $sale : $retail),
            $this->money($product->fixed_shipping_cost ?? 0, 4),
            $this->yn($product->free_shipping),
            optional($product->content)->warranty ?? '',
            $this->money($dimensions['weight'] ?? $product->weight ?? 0, 4),
            $this->money($dimensions['width'] ?? 0, 4),
            $this->money($dimensions['height'] ?? 0, 4),
            $this->money($dimensions['depth'] ?? 0, 4),
            $this->yn($product->allow_purchase ?? true),
            $this->yn($product->visible_on_storefront ?? true),
            optional($product->content)->availability_text ?? '',
            $this->yn($product->track_inventory),
            $product->current_stock ?? 0,
            $product->low_stock ?? 0,
            $this->formatDate($product->created_at),
            $this->formatDate($product->updated_at),
            $this->categoryDetails($product),
            $this->imagesDetails($product),
            optional($product->seo)->page_title ?? '',
            optional($product->seo)->meta_keywords ?? '',
            optional($product->seo)->meta_description ?? '',
            $product->condition ?? 'New',
            optional($product->seo)->product_url ?? '',
            $this->yn(optional($product->seo)->redirect_old_url),
            $product->tax_provider_code ?? $product->tax_class ?? '',
            $this->formatCustomFields($customFields),
        ];
    }

    private function categoryDetails($product): string
    {
        if (!$product->categories || $product->categories->isEmpty()) {
            return '';
        }

        return $product->categories->map(function ($category) {
            $names = [];
            $current = $category;
            $guard = 0;

            while ($current && $guard < 10) {
                array_unshift($names, $current->name);
                $current = $current->parent;
                $guard++;
            }

            $path = implode('/', $names);

            return 'Category Name: ' . ($category->name ?? '') . ', Category Path: ' . $path;
        })->implode('|');
    }

    private function imagesDetails($product): string
    {
        if (!$product->images || $product->images->isEmpty()) {
            return '';
        }

        return $product->images
            ->sortByDesc('is_primary')
            ->values()
            ->map(function ($img) {
                $file = $img->path ? basename($img->path) : '';
                $url  = $img->url ?: $img->path ?: '';

                return 'Product Image File: ' . $file . ', Product Image URL: ' . $url;
            })
            ->implode('|');
    }

    private function formatCustomFields($fields): string
    {
        if (empty($fields) || !is_array($fields)) {
            return '';
        }

        return collect($fields)->map(function ($val, $key) {
            if (is_array($val)) {
                $val = json_encode($val);
            }

            return $key . '=' . $val;
        })->implode('; ');
    }

    private function yn($val): string
    {
        return $val ? 'Y' : 'N';
    }

    private function num($value, $fallback = 0)
    {
        if ($value === null || $value === '' || $value === false) {
            return $fallback;
        }

        return is_numeric($value) ? $value + 0 : $fallback;
    }

    private function money($value, int $decimals = 2): string
    {
        return number_format((float) $this->num($value), $decimals, '.', '');
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
