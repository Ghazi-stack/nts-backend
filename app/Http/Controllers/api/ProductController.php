<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index()
    {
        return ProductResource::collection(Product::with(['category', 'brand'])->paginate(10));
    }

    public function showProduct($slug)
    {
        $product = Product::with(['category', 'brand'])->where('slug', $slug)->firstOrFail();
        return new ProductResource($product);
    }

    public function addProduct(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'brand_id'    => 'required|exists:brands,id',
            'price'       => 'required|numeric',
            'stock'       => 'required|integer',
            'description' => 'nullable|string',
            'image'       => 'nullable|string'
        ]);

        $product = Product::create([
            'name'        => $request->name,
            'slug'        => Str::slug($request->name) . '-' . uniqid(),
            'category_id' => $request->category_id,
            'brand_id'    => $request->brand_id,
            'price'       => $request->price,
            'stock'       => $request->stock,
            'description' => $request->description,
            'image'       => $request->image,
        ]);

        return new ProductResource($product);
    }

    public function updateProduct(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $product->update($request->only([
            'name', 'category_id', 'brand_id', 'price', 'stock', 'description', 'image'
        ]));

        return new ProductResource($product);
    }

    public function destroyProduct($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

     public function searchProduct(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2'
        ]);

        $query = $request->input('query');

        $products = Product::where('name', 'like', "%$query%")
            ->orWhere('slug', 'like', "%$query%")
            ->orWhere('description', 'like', "%$query%")
            ->with(['category', 'brand'])
            ->paginate(10);

        return ProductResource::collection($products);
    }
}
