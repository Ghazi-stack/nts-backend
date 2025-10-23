<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\Logo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\LogoResource;

class LogoController extends Controller
{
    /**
     * List logos with pagination.
     */
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected'],400);

            $perPage = (int) $request->get('perPage', 10);
            $page = (int) $request->get('page', 1);

            $logos = Logo::where('store_id', $storeId)
                ->orderBy('id', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Logos retrieved successfully',
                'data' => LogoResource::collection($logos),
                'pagination' => [
                    'perPage' => $perPage,
                    'currentPage' => $logos->currentPage(),
                    'totalPages' => $logos->lastPage(),
                    'totalItems' => $logos->total(),
                ],
                
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching logos: ' . $e->getMessage());
            return response()->json([
                'status'=>false,
                'message'=>'Failed to retrieve logos',
                'error'=>$e->getMessage()
            ],500);
        }
    }

    /**
     * Get single logo by ID.
     */
    public function showById($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected'],400);

            $logo = Logo::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status'=>true,
                'message'=>'Logo retrieved successfully',
                'data'=>new LogoResource($logo),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching logo: ' . $e->getMessage());
            return response()->json([
                'status'=>false,
                'message'=>'Logo not found',
                'error'=>$e->getMessage()
            ],404);
        }
    }

    /**
     * Create a new logo with optional image/fav upload.
     */
    public function store(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected'],400);

            $validated = $request->validate([
                'logoText'=>'nullable|string',
                'logoType'=>'nullable|string',
                'logoSize'=>'nullable|string',
                'image'=>'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'favicon'=>'nullable|image|mimes:ico,png|max:1024',
                'dimensions'=>'nullable|array',
                'dimensions.width'=>'required_with:dimensions|integer',
                'dimensions.height'=>'required_with:dimensions|integer',
            ]);

            // Handle logo image
            $logoUrl = null;
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $imageName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('logos'), $imageName);
                $logoUrl = url('logos/'.$imageName);
            }

            // Handle favicon
            $faviconUrl = null;
            if ($request->hasFile('favicon')) {
                $file = $request->file('favicon');
                $favName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('logos'), $favName);
                $faviconUrl = url('logos/'.$favName);
            }

            $logo = Logo::create([
                'store_id'=>$storeId,
                'logo_text'=>$validated['logoText']??null,
                'logo_type'=>$validated['logoType']??null,
                'logo_size'=>$validated['logoSize']??null,
                'logo_url'=>$logoUrl,
                'favicon_url'=>$faviconUrl,
                'dimensions'=>$validated['dimensions']??null,
            ]);

            return response()->json([
                'status'=>true,
                'message'=>'Logo created successfully',
                'data'=>new LogoResource($logo)
            ],201);

        } catch (Exception $e) {
            Log::error('Error creating logo: '.$e->getMessage());
            return response()->json([
                'status'=>false,
                'message'=>'Failed to create logo',
                'error'=>$e->getMessage()
            ],500);
        }
    }

    /**
     * Update existing logo with optional image/fav upload.
     */
    public function update(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected'],400);

            $logo = Logo::where('store_id',$storeId)->findOrFail($id);

            $validated = $request->validate([
                'logoText'=>'nullable|string',
                'logoType'=>'nullable|string',
                'logoSize'=>'nullable|string',
                'image'=>'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'favicon'=>'nullable|image|mimes:ico,png|max:1024',
                'dimensions'=>'nullable|array',
                'dimensions.width' => 'nullable|integer',
                'dimensions.height' => 'nullable|integer',
            ]);

            $updateData = [];
            $fields = [
                'logoText'=>'logo_text',
                'logoType'=>'logo_type',
                'logoSize'=>'logo_size',
                'dimensions'=>'dimensions'
            ];

            foreach($fields as $input=>$db){
                if($request->has($input)) $updateData[$db] = $request->input($input);
            }

            // Update logo image
            if($request->hasFile('image')){
                if($logo->logo_url){
                    $old = public_path('logos/'.basename($logo->logo_url));
                    if(file_exists($old)) unlink($old);
                }
                $file=$request->file('image');
                $name=time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('logos'),$name);
                $updateData['logo_url']=url('logos/'.$name);
            }

            // Update favicon
            if($request->hasFile('favicon')){
                if($logo->favicon_url){
                    $old = public_path('logos/'.basename($logo->favicon_url));
                    if(file_exists($old)) unlink($old);
                }
                $file=$request->file('favicon');
                $name=time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('logos'),$name);
                $updateData['favicon_url']=url('logos/'.$name);
            }

            $logo->update($updateData);

            return response()->json([
                'status'=>true,
                'message'=>'Logo updated successfully',
                'data'=>new LogoResource($logo)
            ]);

        } catch(Exception $e){
            Log::error('Error updating logo: '.$e->getMessage());
            return response()->json([
                'status'=>false,
                'message'=>'Failed to update logo',
                'error'=>$e->getMessage()
            ],500);
        }
    }

    /**
     * Delete logos (single/multiple)
     */
    public function destroy($ids)
    {
        try{
            $storeId = current_store_id();
            if(!$storeId) return response()->json(['status'=>false,'message'=>'Store not selected'],400);

            $idArray=explode(',',$ids);
            $logos=Logo::where('store_id',$storeId)->whereIn('id',$idArray)->get();

            if($logos->isEmpty()){
                return response()->json(['status'=>false,'message'=>'No matching logos found'],404);
            }

            foreach($logos as $logo){
                if($logo->logo_url && file_exists(public_path('logos/'.basename($logo->logo_url)))) unlink(public_path('logos/'.basename($logo->logo_url)));
                if($logo->favicon_url && file_exists(public_path('logos/'.basename($logo->favicon_url)))) unlink(public_path('logos/'.basename($logo->favicon_url)));
                $logo->delete();
            }

            return response()->json(['status'=>true,'message'=>'Logo(s) deleted successfully']);
        }catch(Exception $e){
            Log::error('Error deleting logos: '.$e->getMessage());
            return response()->json(['status'=>false,'message'=>'Failed to delete logos','error'=>$e->getMessage()],500);
        }
    }
}
