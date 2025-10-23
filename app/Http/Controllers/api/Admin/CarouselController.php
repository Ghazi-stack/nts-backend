<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\Carousel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\CarouselResource;

class CarouselController extends Controller
{
    /**
     * List all carousels for the current store.
     * Returns JSON in { settings: {...}, slides: [...] } format.
     * swapInterval is dynamic and can be set by admin via query param.
     */
    public function index(Request $request) 
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $carousels = Carousel::where('store_id', $storeId)
                ->orderBy('order')
                ->get();

            // Dynamic swapInterval from query param, default 5
            $swapInterval = (int) $request->get('swapInterval', 5);

            return response()->json([
                'status' => true,
                'message' => 'Carousel items retrieved successfully',
                'settings' => [
                    'swapInterval' => $swapInterval,
                ],
                'slides' => CarouselResource::collection($carousels),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching carousels: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve carousels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single carousel item by ID.
     */
    public function show($id)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $carousel = Carousel::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Carousel item retrieved successfully',
                'data' => new CarouselResource($carousel),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching carousel item: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Carousel item not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create a new carousel item with optional image upload.
     */
    public function store(Request $request) 
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $validated = $request->validate([
                'altText' => 'nullable|string',
                'heading' => 'nullable|string',
                'buttonText' => 'nullable|string',
                'text' => 'nullable|string',
                'link' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'order' => 'nullable|integer',
            ]);

            $imageUrl = null;
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $imageName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('carousel_images'), $imageName);
                $imageUrl = url('carousel_images/' . $imageName);
            }

            $carousel = Carousel::create([
                'store_id' => $storeId,
                'alt_text' => $validated['altText'] ?? null,
                'heading' => $validated['heading'] ?? null,
                'button_text' => $validated['buttonText'] ?? null,
                'text' => $validated['text'] ?? null,
                'link' => $validated['link'] ?? null,
                'image_url' => $imageUrl,
                'order' => $validated['order'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Carousel item created successfully',
                'data' => new CarouselResource($carousel),
            ], 201);

        } catch (Exception $e) {
            Log::error('Error creating carousel item: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create carousel item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update carousel item with optional image upload.
     */
    /**
 * Update carousel item with optional image upload.
 */
    public function update(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
        
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }
        
            $carousel = Carousel::where('store_id', $storeId)->findOrFail($id);
        
            // Validate only the fields that can be updated
            $request->validate([
                'altText' => 'nullable|string',
                'heading' => 'nullable|string',
                'buttonText' => 'nullable|string',
                'text' => 'nullable|string',
                'link' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'order' => 'nullable|integer',
            ]);
        
            // Prepare update data dynamically
            $updateData = [];
            $fieldMap = [
                'altText' => 'alt_text',
                'heading' => 'heading',
                'buttonText' => 'button_text',
                'text' => 'text',
                'link' => 'link',
                'order' => 'order'
            ];
        
            foreach ($fieldMap as $inputKey => $dbColumn) {
                if ($request->has($inputKey)) {
                    $updateData[$dbColumn] = $request->input($inputKey);
                }
            }
        
            // Handle new image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($carousel->image_url) {
                    $oldFile = public_path('carousel_images/' . basename($carousel->image_url));
                    if (file_exists($oldFile)) unlink($oldFile);
                }
            
                $file = $request->file('image');
                $imageName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('carousel_images'), $imageName);
                $updateData['image_url'] = url('carousel_images/' . $imageName);
            }
        
            // Update carousel item
            $carousel->update($updateData);
        
            return response()->json([
                'status' => true,
                'message' => 'Carousel item updated successfully',
                'data' => new CarouselResource($carousel),
            ]);
        
        } catch (Exception $e) {
            Log::error('Error updating carousel item: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update carousel item',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Delete one or multiple carousel items.
     */
    public function destroy($ids)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $idArray = explode(',', $ids);
            $carousels = Carousel::where('store_id', $storeId)->whereIn('id', $idArray)->get();

            if ($carousels->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching carousel items found'
                ], 404);
            }

            foreach ($carousels as $carousel) {
                if ($carousel->image_url) {
                    $oldFile = public_path('carousel_images/' . basename($carousel->image_url));
                    if (file_exists($oldFile)) unlink($oldFile);
                }
                $carousel->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Carousel items deleted successfully'
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting carousel items: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete carousel items',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
