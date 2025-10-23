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
    // List all carousels
    public function index(Request $request) 
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);
            }

            $carousels = Carousel::where('store_id', $storeId)->orderBy('order')->get();
            $swapInterval = (int) $request->get('swapInterval', 5);

            return response()->json([
                'status'=>true,
                'message'=>'Carousel items retrieved successfully',
                'settings'=>['swapInterval'=>$swapInterval],
                'slides'=>CarouselResource::collection($carousels)
            ]);

        } catch (Exception $e) {
            Log::error('Error fetching carousels: '.$e->getMessage());
            return response()->json(['status'=>false,'message'=>'Failed to retrieve carousels','error'=>$e->getMessage()],500);
        }
    }

    // Create new slides
    public function store(Request $request) 
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);

            $slides = $request->input('slides', []);
            $createdSlides = [];

            foreach ($slides as $index => $slide) {
                $validated = validator($slide, [
                    'heading'=>'nullable|string',
                    'text'=>'nullable|string',
                    'buttonText'=>'nullable|string',
                    'link'=>'nullable|string',
                    'altText'=>'nullable|string',
                    'order'=>'nullable|integer',
                ])->validate();

                $imageUrl = null;
                if ($request->hasFile("slides.$index.image")) {
                    $file = $request->file("slides.$index.image");
                    $imageName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                    $file->move(public_path('carousel_images'), $imageName);
                    $imageUrl = url('carousel_images/'.$imageName);
                }

                $carousel = Carousel::create([
                    'store_id' => $storeId,
                    'heading' => $validated['heading'] ?? null,
                    'text' => $validated['text'] ?? null,
                    'button_text' => $validated['buttonText'] ?? null,
                    'link' => $validated['link'] ?? null,
                    'alt_text' => $validated['altText'] ?? null,
                    'order' => $validated['order'] ?? null,
                    'image_url' => $imageUrl,
                ]);

                $createdSlides[] = new CarouselResource($carousel);
            }

            $swapInterval = (int) ($request->input('settings.swapInterval') ?? 5);

            return response()->json([
                'status'=>true,
                'message'=>'Carousel slides created successfully',
                'settings'=>['swapInterval'=>$swapInterval],
                'slides'=>$createdSlides
            ],201);

        } catch (Exception $e) {
            Log::error('Error creating carousel slides: '.$e->getMessage());
            return response()->json(['status'=>false,'message'=>'Failed to create carousel slides','error'=>$e->getMessage()],500);
        }
    }

    // Update slides
    public function update(Request $request)
    {
        try {
            $storeId = current_store_id();
            if(!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);

            $slides = $request->input('slides', []);
            $updatedSlides = [];

            foreach ($slides as $index => $slide) {
                $validated = validator($slide, [
                    'heading'=>'nullable|string',
                    'text'=>'nullable|string',
                    'buttonText'=>'nullable|string',
                    'link'=>'nullable|string',
                    'altText'=>'nullable|string',
                    'order'=>'nullable|integer',
                ])->validate();

                $carousel = null;

                if (isset($validated['order'])) {
                    $carousel = Carousel::where('store_id',$storeId)
                                ->where('order',$validated['order'])
                                ->first();
                }

                $imageUrl = null;
                if ($request->hasFile("slides.$index.image")) {
                    $file = $request->file("slides.$index.image");
                    $imageName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                    $file->move(public_path('carousel_images'), $imageName);
                    $imageUrl = url('carousel_images/'.$imageName);
                }

                if($carousel){
                    if($imageUrl && $carousel->image_url){
                        $oldFile = public_path('carousel_images/'.basename($carousel->image_url));
                        if(file_exists($oldFile)) unlink($oldFile);
                    }
                    $carousel->update([
                        'heading'=>$validated['heading'] ?? $carousel->heading,
                        'text'=>$validated['text'] ?? $carousel->text,
                        'button_text'=>$validated['buttonText'] ?? $carousel->button_text,
                        'link'=>$validated['link'] ?? $carousel->link,
                        'alt_text'=>$validated['altText'] ?? $carousel->alt_text,
                        'image_url'=>$imageUrl ?? $carousel->image_url,
                        'order'=>$validated['order'] ?? $carousel->order
                    ]);
                } else {
                    $carousel = Carousel::create([
                        'store_id' => $storeId,
                        'heading' => $validated['heading'] ?? null,
                        'text' => $validated['text'] ?? null,
                        'button_text' => $validated['buttonText'] ?? null,
                        'link' => $validated['link'] ?? null,
                        'alt_text' => $validated['altText'] ?? null,
                        'order' => $validated['order'] ?? null,
                        'image_url' => $imageUrl,
                    ]);
                }

                $updatedSlides[] = new CarouselResource($carousel);
            }

            $swapInterval = (int) ($request->input('settings.swapInterval') ?? 5);

            return response()->json([
                'status'=>true,
                'message'=>'Carousel slides updated successfully',
                'settings'=>['swapInterval'=>$swapInterval],
                'slides'=>$updatedSlides
            ],200);

        } catch(Exception $e){
            Log::error('Error updating carousel slides: '.$e->getMessage());
            return response()->json(['status'=>false,'message'=>'Failed to update carousel slides','error'=>$e->getMessage()],500);
        }
    }

    // Delete slides
    public function destroy($ids)
    {
        try {
            $storeId = current_store_id();
            if(!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);

            $idArray = explode(',',$ids);
            $carousels = Carousel::where('store_id',$storeId)->whereIn('id',$idArray)->get();
            if($carousels->isEmpty()) return response()->json(['status'=>false,'message'=>'No matching carousel items found'],404);

            foreach($carousels as $carousel){
                if($carousel->image_url){
                    $oldFile = public_path('carousel_images/'.basename($carousel->image_url));
                    if(file_exists($oldFile)) unlink($oldFile);
                }
                $carousel->delete();
            }

            return response()->json(['status'=>true,'message'=>'Carousel items deleted successfully']);

        } catch(Exception $e){
            Log::error('Error deleting carousel items: '.$e->getMessage());
            return response()->json(['status'=>false,'message'=>'Failed to delete carousel items','error'=>$e->getMessage()],500);
        }
    }
}
