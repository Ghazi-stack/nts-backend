<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class ProductController extends Controller
{
    public function index()
    {
        try {
            $products = Product::with(['category', 'brand'])->paginate(10);
            return ProductResource::collection($products);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch products.'], 500);
        }
    }

    public function showProduct($slug)
    {
        try {
            $product = Product::with(['category', 'brand'])->where('slug', $slug)->firstOrFail();
            return new ProductResource($product);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found.'], 404);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to retrieve product.'], 500);
        }
    }

    public function addProduct(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'        => 'nullable|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'brand_id'    => 'required|exists:brands,id',
                'price'       => 'required|numeric|min:0',
                'stock'       => 'nullable|integer',
                'description' => 'nullable|string',
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
            ]);

            if ($request->hasFile('image')) {
                $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('image')->getClientOriginalName());
                $request->file('image')->move(public_path('product_images'), $imageName);
                $validated['image'] = 'product_images/' . $imageName;
            }

            $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();

            $product = Product::create($validated);
               return response()->json([
                    'status'  => true,
                    'message' => 'Product created successfully.',
                    'data'    => new ProductResource($product)
                ], 201); // 👈 Status code 201: Created

            return new ProductResource($product);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateProduct(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name'        => 'nullable|string|max:255',
                'category_id' => 'nullable|exists:categories,id',
                'brand_id'    => 'nullable|exists:brands,id',
                'price'       => 'nullable|numeric|min:0',
                'stock'       => 'nullable|integer',
                'description' => 'nullable|string',
                'image'       => 'nullable|string'
            ]);

            $product = Product::findOrFail($id);

            if (isset($validated['name'])) {
                $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();
            }

            $product->update($validated);
             return response()->json([
                    'status'  => true,
                    'message' => 'Product updated successfully.',
                    'data'    => new ProductResource($product)
                ], 200);

            return new ProductResource($product);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found.'], 404);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to update product.'], 500);
        }


    }

    public function destroyProduct($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->delete();

            return response()->json(['message' => 'Product deleted successfully.']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found.'], 404);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to delete product.'], 500);
        }
    }

    public function searchProduct(Request $request)
    {
       try {
            $request->validate([
                'query' => 'required|string|min:2'
            ]);

            $query = $request->input('query');

            $products = Product::where('name', 'like', "%$query%")
                ->orWhere('slug', 'like', "%$query%")
                ->orWhere('description', 'like', "%$query%")
                ->with(['category', 'brand'])
                ->paginate(10);

            return response()->json([
                'status'  => true,
                'message' => 'Products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ], 200); // ✅ HTTP 200 OK
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
