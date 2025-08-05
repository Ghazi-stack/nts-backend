<?php

namespace App\Http\Controllers\api\Admin;

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
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $filters = ProductFilter::where('store_id', $storeId)
            ->orderBy('sort_order')
            ->get();

        $filters->each(function ($filter) use ($storeId) {
            $filter->countDetails = $this->getCountDetails($filter, $storeId);
        });

        return response()->json([
            'status' => true,
            'filters' => ProductFilterResource::collection($filters)
        ]);
    }

    public function updateVisibility(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $request->validate([
            'filters' => 'required|array',
            'filters.*.id' => 'required|exists:product_filters,id',
            'filters.*.visible' => 'required|boolean'
        ]);

        foreach ($request->filters as $item) {
            ProductFilter::where('store_id', $storeId)
                ->where('id', $item['id'])
                ->update(['is_visible' => $item['visible']]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visibility updated successfully.'
        ]);
    }

    private function getCountDetails($filter, $storeId)
    {
        switch ($filter->type) {
            case 'category':
                return Category::where('store_id', $storeId)->count() . ' in total';

            case 'brand':
                return Brand::where('store_id', $storeId)->count() . ' in total';

            case 'price':
                $min = Product::where('store_id', $storeId)->min('price');
                $max = Product::where('store_id', $storeId)->max('price');
                return "Ranging from $min to $max";

            case 'flags':
                $flags = [];
                if (Product::where('store_id', $storeId)->where('free_shipping', true)->exists()) {
                    $flags[] = "Has Free Shipping";
                }
                if (Product::where('store_id', $storeId)->where('is_featured', true)->exists()) {
                    $flags[] = "Is Featured";
                }
                if (Product::where('store_id', $storeId)->where('current_stock', '>', 0)->exists()) {
                    $flags[] = "In Stock";
                }
                return implode(', ', $flags);

            case 'custom':
                $key = strtolower(str_replace(' ', '_', $filter->name)); // e.g. Eco Friendly => eco_friendly
                $count = Product::where('store_id', $storeId)
                    ->where("custom_fields->$key", true)
                    ->count();
                return "$count products";

            default:
                return '-';
        }
    }
}
