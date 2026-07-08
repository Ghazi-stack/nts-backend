<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\DB;

class ProductExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    protected $storeId;
    protected $maxImages;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;

        // Max images count nikalo across all products
        $this->maxImages = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->where('products.store_id', $storeId)
            ->select('product_id', DB::raw('COUNT(*) as img_count'))
            ->groupBy('product_id')
            ->orderByDesc('img_count')
            ->limit(1)
            ->value('img_count') ?? 1;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function query()
    {
        return Product::with([
            'brand:id,name',
            'categories:id,name,parent_id',
            'categories.parent:id,name,parent_id',
            'content:id,product_id,description,warranty,availability_text',
            'identifiers:id,product_id,mpn,gtin,upc,bpn',
            'seo:id,product_id,page_title,product_url,meta_description,meta_keywords,redirect_old_url',
            'images:id,product_id,path,url,is_primary,alt_text,store_id',
        ])
            ->where('store_id', $this->storeId)
            ->orderBy('id', 'desc');
    }

    public function map($product): array
    {
        try {
            $dimensions = is_array($product->dimensions)
                ? $product->dimensions
                : (json_decode($product->dimensions, true) ?? []);

            $customFields = is_array($product->custom_fields)
                ? $product->custom_fields
                : (json_decode($product->custom_fields, true) ?? []);

            $row = [
                $product->item_type ?? '',
                $product->id ?? '',
                $product->name ?? '',
                $product->sku ?? '',
                optional($product->brand)->name ?? '',
                $this->getCategoryPath($product),
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
                $dimensions['weight'] ?? '',
                $dimensions['width'] ?? '',
                $dimensions['height'] ?? '',
                $dimensions['depth'] ?? '',
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
                $this->formatCustomFields($customFields),
                $this->boolOut($product->visible_on_storefront),
                $product->sort_order ?? '',
                $product->search_keywords ?? '',
                $product->tax_class ?? '',
                $product->tax_provider_code ?? '',
                $this->boolOut($product->stop_processing_rules),
            ];

            // Dynamic image columns
            $images = $product->images->sortByDesc('is_primary')->values();

            for ($i = 0; $i < $this->maxImages; $i++) {
                $img = $images[$i] ?? null;
                $row[] = $img ? $img->path : '';           // Product Image File
                $row[] = $img ? ($img->alt_text ?? '') : '';  // Product Image Description
                $row[] = $img ? $this->boolOut($img->is_primary) : '';  // Product Image Is Thumbnail
                $row[] = $i + 1;                            // Product Image Sort
            }

            return $row;
        } catch (\Throwable $e) {
            \Log::error('ProductExport map error — product ' . $product->id . ': ' . $e->getMessage());
            $totalCols = 44 + ($this->maxImages * 4);
            return array_fill(0, $totalCols, '');
        }
    }

    public function headings(): array
    {
        $headings = [
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
            'Product Visible?',
            'Sort Order',
            'Search Keywords',
            'Product Tax Class',
            'Tax Provider Tax Code',
            'Stop Processing Rules',
        ];

        // Dynamic image headings
        for ($i = 1; $i <= $this->maxImages; $i++) {
            $headings[] = "Product Image File - {$i}";
            $headings[] = "Product Image Description - {$i}";
            $headings[] = "Product Image Is Thumbnail - {$i}";
            $headings[] = "Product Image Sort - {$i}";
        }

        return $headings;
    }

    private function boolOut($val): string
    {
        return $val ? 'Yes' : 'No';
    }

    private function getCategoryPath($product): string
    {
        try {
            $category = $product->categories->first();
            if (!$category) return '';

            $names = [];
            $limit = 10;
            $count = 0;

            while ($category && $count < $limit) {
                array_unshift($names, $category->name);
                $category = $category->parent;
                $count++;
            }

            return implode('/', $names);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function formatCustomFields($fields): string
    {
        if (empty($fields) || !is_array($fields)) return '';

        return collect($fields)->map(function ($val, $key) {
            if (is_array($val)) $val = json_encode($val);
            return "{$key}={$val}";
        })->implode('; ');
    }
}
