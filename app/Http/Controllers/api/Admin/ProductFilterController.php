<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductFilter;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Http\Request;
use App\Http\Resources\ProductFilterResource;

class ProductFilterController extends Controller
{
    public function index()
    {
        $filters = ProductFilter::where('store_id', current_store_id())
            ->orderBy('sort_order')
            ->get();

        $filters->each(function ($filter) {
            $filter->countDetails = $this->getCountDetails($filter);
        });

        return response()->json([
            'status' => true,
            'filters' => ProductFilterResource::collection($filters)
        ]);
    }

    public function updateVisibility(Request $request)
    {
        $request->validate([
            'filters' => 'required|array',
            'filters.*.id' => 'required|exists:product_filters,id',
            'filters.*.visible' => 'required|boolean'
        ]);

        foreach ($request->filters as $item) {
           ProductFilter::where('store_id', current_store_id())
            ->where('id', $item['id'])
            ->update(['is_visible' => $item['visible']]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visibility updated successfully.'
        ]);
    }

    private function getCountDetails($filter)
    {
        switch ($filter->type) {
            case 'category':
                return Category::count() . ' in total';
            case 'brand':
                return Brand::count() . ' in total';
            case 'price':
                $min = Product::min('price');
                $max = Product::max('price');
                return "Ranging from $min to $max";
            case 'flags':
                $flags = [];
                if (Product::where('free_shipping', true)->exists()) $flags[] = "Has Free Shipping";
                if (Product::where('is_featured', true)->exists()) $flags[] = "Is Featured";
                if (Product::where('current_stock', '>', 0)->exists()) $flags[] = "In Stock";
                return implode(', ', $flags);
            case 'custom':
                $key = strtolower(str_replace(' ', '_', $filter->name)); // e.g. Eco Friendly => eco_friendly
                $count = Product::where("custom_fields->$key", true)->count();
                return "$count products";
            default:
                return '-';
        }
    }
}
