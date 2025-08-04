<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Product::with(['brand', 'categories', 'content', 'identifiers', 'seo', 'images'])->get();
    }

    public function map($product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true);

        return [
            $product->item_type,
            $product->name,
            $product->product_type,
            $product->sku,
            optional($product->brand)->name,
            optional($product->category)->name,
            $product->price,
            $product->cost_price,
            $product->sale_price,
            $product->fixed_shipping_cost,
            $this->boolOut($product->free_shipping),
            $this->boolOut($product->allow_purchase),
            $product->condition,
            $this->boolOut($product->show_condition),
            $this->boolOut($product->track_inventory),
            $product->current_stock,
            $product->low_stock,
            $product->option_set,
            $product->option_set_align,
            optional($product->content)->description,
            optional($product->content)->warranty,
            optional($product->content)->availability_text,
            optional($product->seo)->page_title,
            optional($product->seo)->product_url,
            optional($product->seo)->meta_description,
            optional($product->seo)->meta_keywords,
            $this->boolOut(optional($product->seo)->redirect_old_url),
            $product->dimensions['weight'] ?? '',
            $product->dimensions['width'] ?? '',
            $product->dimensions['height'] ?? '',
            $product->dimensions['depth'] ?? '',
            optional($product->identifiers)->mpn,
            optional($product->identifiers)->upc,
            optional($product->identifiers)->gtin,
            optional($product->identifiers)->bpn,
            $this->formatCustomFields($product->custom_fields),
            $primaryImage->path ?? '',
            $primaryImage->alt_text ?? '',
            $this->boolOut($product->visible_on_storefront),
            $product->sort_order,
            $product->search_keywords,
            $product->tax_class,
            $product->tax_provider_code,
            $this->boolOut($product->stop_processing_rules),
        ];
    }

    public function headings(): array
    {
        return [
            'Item Type',
            'Product Name',
            'Product Type',
            'Product Code/SKU',
            'Brand Name',
            'Category',
            'Retail Price',
            'Cost Price',
            'Sale Price',
            'Fixed Shipping Cost',
            'Free Shipping',
            'Allow Purchases?',
            'Product Condition',
            'Show Product Condition?',
            'Track Inventory',
            'Current Stock Level',
            'Low Stock Level',
            'Option Set',
            'Option Set Align',
            'Product Description',
            'Product Warranty',
            'Product Availability',
            'Page Title',
            'Product URL',
            'Meta Description',
            'Meta Keywords',
            'Redirect Old URL?',
            'Product Weight',
            'Product Width',
            'Product Height',
            'Product Depth',
            'Manufacturer Part Number',
            'Product UPC/EAN',
            'Global Trade Item Number',
            'Bin Picking Number',
            'Product Custom Fields',
            'Product Image File - 1',
            'Product Image Description - 1',
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
         if (!is_array($fields)) return '';

        return collect($fields)->map(function ($val, $key) {
            if (is_array($val)) {
                $val = json_encode($val); // Safely convert array to JSON string
            }
            return "{$key}={$val}";
        })->implode('; ');
    }

    private function boolOut($val)
    {
        return $val ? 'Yes' : 'No';
    }
}
