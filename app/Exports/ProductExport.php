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
        return Product::with(['brand', 'category', 'content', 'identifiers', 'seo', 'images'])->get();
    }

    public function map($product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true);

        return [
            'Item Type'                   => $product->item_type,
            'Product Name'                => $product->name,
            'Product Type'                => $product->product_type,
            'Product Code/SKU'           => $product->sku,
            'Brand Name'                 => optional($product->brand)->name,
            'Category'                   => optional($product->category)->name,
            'Retail Price'               => $product->price,
            'Cost Price'                 => $product->cost_price,
            'Sale Price'                 => $product->sale_price,
            'Fixed Shipping Cost'        => $product->fixed_shipping_cost,
            'Free Shipping'              => $this->boolOut($product->free_shipping),
            'Allow Purchases?'           => $this->boolOut($product->allow_purchase),
            'Product Condition'          => $product->condition,
            'Show Product Condition?'    => $this->boolOut($product->show_condition),
            'Track Inventory'            => $this->boolOut($product->track_inventory),
            'Current Stock Level'        => $product->current_stock,
            'Low Stock Level'            => $product->low_stock,
            'Option Set'                 => $product->option_set,
            'Option Set Align'           => $product->option_set_align,
            'Product Description'        => optional($product->content)->description,
            'Product Warranty'           => optional($product->content)->warranty,
            'Product Availability'       => optional($product->content)->availability_text,
            'Page Title'                 => optional($product->seo)->page_title,
            'Product URL'                => optional($product->seo)->product_url,
            'Meta Description'           => optional($product->seo)->meta_description,
            'Meta Keywords'              => optional($product->seo)->meta_keywords,
            'Redirect Old URL?'          => $this->boolOut(optional($product->seo)->redirect_old_url),
            'Product Weight'             => $product->dimensions['weight'] ?? '',
            'Product Width'              => $product->dimensions['width'] ?? '',
            'Product Height'             => $product->dimensions['height'] ?? '',
            'Product Depth'              => $product->dimensions['depth'] ?? '',
            'Manufacturer Part Number'   => optional($product->identifiers)->mpn,
            'Product UPC/EAN'            => optional($product->identifiers)->upc,
            'Global Trade Item Number'   => optional($product->identifiers)->gtin,
            'Bin Picking Number'         => optional($product->identifiers)->bpn,
            'Product Custom Fields'      => $this->formatCustomFields($product->custom_fields),
            'Product Image File - 1'     => $primaryImage->path ?? '',
            'Product Image Description - 1' => $primaryImage->alt_text ?? '',
            'Product Visible?'           => $this->boolOut($product->visible_on_storefront),
            'Sort Order'                 => $product->sort_order,
            'Search Keywords'            => $product->search_keywords,
            'Product Tax Class'          => $product->tax_class,
            'Tax Provider Tax Code'      => $product->tax_provider_code,
            'Stop Processing Rules'      => $this->boolOut($product->stop_processing_rules),
        ];
    }

    public function headings(): array
    {
        return array_keys($this->map(new Product));
    }

    private function formatCustomFields($fields)
    {
        if (!is_array($fields)) return '';

        return collect($fields)->map(function ($val, $key) {
            return "{$key}={$val}";
        })->implode(';');
    }

    private function boolOut($val)
    {
        return $val ? 'Yes' : 'No';
    }
}
