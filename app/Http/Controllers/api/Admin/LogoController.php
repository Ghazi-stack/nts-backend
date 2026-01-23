<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\Logo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\LogoResource;
use Illuminate\Support\Facades\Storage;

class LogoController extends Controller
{
    /**
     * List logos with pagination.
     */
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status' => false, 'message' => 'Store not selected'], 400);

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
                'status' => false,
                'message' => 'Failed to retrieve logos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single logo by ID.
     */
    public function showById($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status' => false, 'message' => 'Store not selected'], 400);

            $logo = Logo::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Logo retrieved successfully',
                'data' => new LogoResource($logo),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching logo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Logo not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create a new logo with optional image/fav upload.
     */
    public function store(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
            }

            $validated = $request->validate([
                'logoText' => 'nullable|string',
                'logoType' => 'nullable|string',
                'logoSize' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'favicon' => 'nullable|image|mimes:ico,png|max:1024',
                'dimensions' => 'nullable|array',
                'dimensions.width' => 'nullable|integer',
                'dimensions.height' => 'nullable|integer',
            ]);

            // Check if logo already exists for this store
            $logo = Logo::where('store_id', $storeId)->first();


            /* ================= LOGO IMAGE ================= */
            if ($request->hasFile('image')) {

                // delete old logo from S3 if exists
                if ($logo && $logo->logo_url) {
                    $oldPath = parse_url($logo->logo_url, PHP_URL_PATH);
                    $oldPath = ltrim($oldPath, '/'); // remove leading slash
                    Storage::disk('s3')->delete($oldPath);
                }

                $file = $request->file('image');

                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'logos',
                    $file,
                    $name
                );

                $logoUrl = Storage::disk('s3')->url($path);
            } else {
                $logoUrl = $logo->logo_url ?? null;
            }


            /* ================= FAVICON IMAGE ================= */
            if ($request->hasFile('favicon')) {

                // delete old favicon from S3 if exists
                if ($logo && $logo->favicon_url) {
                    $oldPath = parse_url($logo->favicon_url, PHP_URL_PATH);
                    $oldPath = ltrim($oldPath, '/');
                    Storage::disk('s3')->delete($oldPath);
                }

                $file = $request->file('favicon');

                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'logos',
                    $file,
                    $name
                );

                $faviconUrl = Storage::disk('s3')->url($path);
            } else {
                $faviconUrl = $logo->favicon_url ?? null;
            }


            /* ================= PREPARE DATA (UNCHANGED) ================= */
            $data = [
                'store_id'     => $storeId,
                'logo_text'    => $validated['logoText'] ?? $logo->logo_text ?? null,
                'logo_type'    => $validated['logoType'] ?? $logo->logo_type ?? null,
                'logo_size'    => $validated['logoSize'] ?? $logo->logo_size ?? null,
                'logo_url'     => $logoUrl,
                'favicon_url'  => $faviconUrl,
                'dimensions'   => $validated['dimensions'] ?? $logo->dimensions ?? null,
            ];

            // If logo exists -> update, else -> create new
            if ($logo) {
                $logo->update($data);
                $message = 'Logo updated successfully';
            } else {
                $logo = Logo::create($data);
                $message = 'Logo created successfully';
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => new LogoResource($logo)
            ]);
        } catch (Exception $e) {
            Log::error('Error saving logo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to save logo',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update existing logo with optional image/fav upload.
     */
    public function update(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected'
                ], 400);
            }

            $logo = Logo::where('store_id', $storeId)->findOrFail($id);

            $validated = $request->validate([
                'logoText' => 'nullable|string',
                'logoType' => 'nullable|string',
                'logoSize' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'favicon' => 'nullable|image|mimes:ico,png|max:1024',
                'dimensions' => 'nullable|array',
                'dimensions.width' => 'nullable|integer',
                'dimensions.height' => 'nullable|integer',
            ]);

            $updateData = [];
            $fields = [
                'logoText'   => 'logo_text',
                'logoType'   => 'logo_type',
                'logoSize'   => 'logo_size',
                'dimensions' => 'dimensions'
            ];

            foreach ($fields as $input => $db) {
                if ($request->has($input)) {
                    $updateData[$db] = $request->input($input);
                }
            }

            /* ================= LOGO IMAGE (S3) ================= */
            if ($request->hasFile('image')) {

                // delete old logo from S3
                if ($logo->logo_url) {
                    $oldPath = parse_url($logo->logo_url, PHP_URL_PATH);
                    $oldPath = ltrim($oldPath, '/');
                    Storage::disk('s3')->delete($oldPath);
                }

                $file = $request->file('image');
                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'logos',
                    $file,
                    $name
                );

                $updateData['logo_url'] = Storage::disk('s3')->url($path);
            }

            /* ================= FAVICON (S3) ================= */
            if ($request->hasFile('favicon')) {

                // delete old favicon from S3
                if ($logo->favicon_url) {
                    $oldPath = parse_url($logo->favicon_url, PHP_URL_PATH);
                    $oldPath = ltrim($oldPath, '/');
                    Storage::disk('s3')->delete($oldPath);
                }

                $file = $request->file('favicon');
                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'logos',
                    $file,
                    $name
                );

                $updateData['favicon_url'] = Storage::disk('s3')->url($path);
            }

            $logo->update($updateData);

            return response()->json([
                'status' => true,
                'message' => 'Logo updated successfully',
                'data'    => new LogoResource($logo)
            ]);
        } catch (\Exception $e) {

            Log::error('Error updating logo: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Failed to update logo',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete logos (single/multiple)
     */
    public function destroy($ids)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) return response()->json(['status' => false, 'message' => 'Store not selected'], 400);

            $idArray = explode(',', $ids);
            $logos = Logo::where('store_id', $storeId)->whereIn('id', $idArray)->get();

            if ($logos->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'No matching logos found'], 404);
            }

            foreach ($logos as $logo) {
                if ($logo->logo_url && file_exists(public_path('logos/' . basename($logo->logo_url)))) unlink(public_path('logos/' . basename($logo->logo_url)));
                if ($logo->favicon_url && file_exists(public_path('logos/' . basename($logo->favicon_url)))) unlink(public_path('logos/' . basename($logo->favicon_url)));
                $logo->delete();
            }

            return response()->json(['status' => true, 'message' => 'Logo(s) deleted successfully']);
        } catch (Exception $e) {
            Log::error('Error deleting logos: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to delete logos', 'error' => $e->getMessage()], 500);
        }
    }
}
