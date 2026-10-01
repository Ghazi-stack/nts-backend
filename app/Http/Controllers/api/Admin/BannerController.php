<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $perPage = $request->query('perPage', 20);
            $search  = trim((string) $request->query('search', ''));

            $banners = Banner::where('store_id', $storeId)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                            ->orWhere('page_content', 'like', "%{$search}%")
                            ->orWhere('placement', 'like', "%{$search}%")
                            ->orWhere('location', 'like', "%{$search}%")
                            ->orWhere('visible', 'like', "%{$search}%")
                            ->orWhere('date_range', 'like', "%{$search}%");
                    });
                })
                ->latest()
                ->paginate($perPage);

            $categoryIds = [];
            $brandIds    = [];

            foreach ($banners as $banner) {
                [$type, $id] = $this->resolveBannerLocation($banner);

                if ($type === 'category' && $id) {
                    $categoryIds[] = $id;
                }

                if ($type === 'brand' && $id) {
                    $brandIds[] = $id;
                }
            }

            $categories = $categoryIds
                ? Category::where('store_id', $storeId)->whereIn('id', array_unique($categoryIds))->get()->keyBy('id')
                : collect();

            $brands = $brandIds
                ? Brand::where('store_id', $storeId)->whereIn('id', array_unique($brandIds))->get()->keyBy('id')
                : collect();

            $banners->getCollection()->transform(function ($banner) use ($categories, $brands) {
                [$type, $id] = $this->resolveBannerLocation($banner);

                $banner->resolved_location_type = $type;
                $banner->resolved_location_id   = $id;
                $banner->location_category      = null;
                $banner->location_brand         = null;

                if ($type === 'category') {
                    $banner->location_category = $categories->get($id);
                } elseif ($type === 'brand') {
                    $banner->location_brand = $brands->get($id);
                }

                return $banner;
            });

            return response()->json([
                'status'  => true,
                'message' => 'Banners retrieved successfully',
                'data'    => BannerResource::collection($banners),
                'pagination' => [
                    'total'       => $banners->total(),
                    'count'       => $banners->count(),
                    'perPage'     => $banners->perPage(),
                    'currentPage' => $banners->currentPage(),
                    'totalPages'  => $banners->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching banners: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve banners',
            ], 500);
        }
    }


    public function getByLocation(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $location = strtolower(trim((string) $request->query('islocation', $request->query('isLocation', ''))));
            $locationId = $request->query('locationId');

            if ($location === '') {
                return response()->json([
                    'status'  => false,
                    'message' => 'islocation is required.',
                ], 422);
            }

            $perPage = $request->query('perPage', 20);
            $now = now();

            $query = Banner::where('store_id', $storeId)
                ->where('visible', 1)
                ->where(function ($q) use ($location) {
                    $q->where('location->key', $location)
                        ->orWhere('location->key', ucfirst($location))
                        ->orWhere('location->key', strtolower($location));
                });

            if (in_array($location, ['category', 'brand'], true) && $locationId !== null && $locationId !== '') {
                $query->where('location->id', (int) $locationId);
            }

            // visible = 1, current time date_range ke andar, same placement par sirf pehla created
            $selectedIds = (clone $query)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->filter(function ($banner) use ($now) {
                    return $this->bannerIsWithinDateRange($banner->date_range, $now);
                })
                ->unique(function ($banner) {
                    $placement = $banner->placement;

                    if (is_array($placement)) {
                        return strtolower(json_encode($placement));
                    }

                    return strtolower(trim((string) $placement));
                })
                ->pluck('id')
                ->all();

            $banners = Banner::where('store_id', $storeId)
                ->whereIn('id', $selectedIds ?: [0])
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->paginate($perPage);

            $categoryIds = [];
            $brandIds    = [];

            foreach ($banners as $banner) {
                [$type, $id] = $this->resolveBannerLocation($banner);

                if ($type === 'category' && $id) {
                    $categoryIds[] = $id;
                }

                if ($type === 'brand' && $id) {
                    $brandIds[] = $id;
                }
            }

            $categories = $categoryIds
                ? Category::where('store_id', $storeId)->whereIn('id', array_unique($categoryIds))->get()->keyBy('id')
                : collect();

            $brands = $brandIds
                ? Brand::where('store_id', $storeId)->whereIn('id', array_unique($brandIds))->get()->keyBy('id')
                : collect();

            $banners->getCollection()->transform(function ($banner) use ($categories, $brands) {
                [$type, $id] = $this->resolveBannerLocation($banner);

                $banner->resolved_location_type = $type;
                $banner->resolved_location_id   = $id;
                $banner->location_category      = null;
                $banner->location_brand         = null;

                if ($type === 'category') {
                    $banner->location_category = $categories->get($id);
                } elseif ($type === 'brand') {
                    $banner->location_brand = $brands->get($id);
                }

                return $banner;
            });

            return response()->json([
                'status'  => true,
                'message' => 'Banners retrieved successfully',
                'data'    => BannerResource::collection($banners),
                'pagination' => [
                    'total'       => $banners->total(),
                    'count'       => $banners->count(),
                    'perPage'     => $banners->perPage(),
                    'currentPage' => $banners->currentPage(),
                    'totalPages'  => $banners->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching banners by location: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve banners',
            ], 500);
        }
    }

    private function bannerIsWithinDateRange($dateRange, $now = null): bool
    {
        $now = $now ?: now();

        if ($dateRange === null || $dateRange === '' || $dateRange === 0 || $dateRange === '0') {
            return true;
        }

        if (is_string($dateRange)) {
            $decoded = json_decode($dateRange, true);
            $dateRange = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($dateRange)) {
            return true;
        }

        $start = $dateRange['startDate'] ?? null;
        $end   = $dateRange['endDate'] ?? null;

        if (!$start && !$end) {
            return true;
        }

        if ($start && $now->lt(\Carbon\Carbon::parse($start))) {
            return false;
        }

        if ($end && $now->gt(\Carbon\Carbon::parse($end))) {
            return false;
        }

        return true;
    }
    public function storeBanner(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $request->validate([
                'title'        => 'required|string|max:255',
                'pageContent'  => 'nullable|string',
                'locationType' => 'required|in:homepage,category,brand,search',
                'locationId'   => 'nullable|integer|required_if:locationType,category,brand',
                'dateType'     => 'nullable|in:always,range',
                'startDate'    => 'nullable|required_if:dateType,range|date',
                'endDate'      => 'nullable|required_if:dateType,range|date|after_or_equal:startDate',
                'visible'      => 'nullable|boolean',
                'placement'    => 'required|in:top,bottom',
            ]);

            $banner = Banner::create([
                'store_id'     => $storeId,
                'title'        => $request->title,
                'page_content' => $request->pageContent,
                'location'     => $this->buildLocation($request),
                'date_range'   => $this->buildDateRange($request),
                'visible'      => $request->boolean('visible', true),
                'placement'    => $request->placement,
            ]);

            $banner = $this->attachLocationEntity($banner, $storeId);

            return response()->json([
                'status'  => true,
                'message' => 'Banner created successfully',
                'banner'  => new BannerResource($banner),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create banner',
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);
            $banner = $this->attachLocationEntity($banner, $storeId);

            return response()->json([
                'status'  => true,
                'message' => 'Banner retrieved successfully',
                'banner'  => new BannerResource($banner),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve banner',
            ], 500);
        }
    }

    public function updateBanner(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);

            $request->validate([
                'title'        => 'nullable|string|max:255',
                'pageContent'  => 'nullable|string',
                'locationType' => 'nullable|in:homepage,category,brand,search',
                'locationId'   => 'nullable|integer',
                'dateType'     => 'nullable|in:always,range',
                'startDate'    => 'nullable|date',
                'endDate'      => 'nullable|date|after_or_equal:startDate',
                'visible'      => 'nullable|boolean',
                'placement'    => 'nullable|in:top,bottom',
            ]);

            $data = [];

            if ($request->exists('title')) {
                $data['title'] = $request->title;
            }

            if ($request->exists('pageContent')) {
                $data['page_content'] = $request->pageContent;
            }

            if ($request->exists('locationType')) {
                $data['location'] = $this->buildLocation($request);
            }

            if ($request->exists('dateType') || $request->exists('startDate') || $request->exists('endDate')) {
                $data['date_range'] = $this->buildDateRange($request);
            }

            if ($request->exists('visible')) {
                $data['visible'] = $request->boolean('visible');
            }

            if ($request->exists('placement')) {
                $data['placement'] = $request->placement;
            }

            if (!empty($data)) {
                $banner->update($data);
            }

            $banner = $this->attachLocationEntity($banner->fresh(), $storeId);

            return response()->json([
                'status'  => true,
                'message' => 'Banner updated successfully',
                'banner'  => new BannerResource($banner),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update banner',
            ], 500);
        }
    }

    public function destroyBanner($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);
            $banner->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Banner deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete banner',
            ], 500);
        }
    }

    private function resolveBannerLocation($banner): array
    {
        $location = is_array($banner->location) ? $banner->location : [];

        $type = strtolower(trim((string) (
            $location['key']
            ?? $banner->location_type
            ?? data_get($location, 'type')
            ?? data_get($location, 'locationType')
            ?? ''
        )));

        $id = $location['id']
            ?? $banner->location_id
            ?? data_get($location, 'locationId');

        $id = $id ? (int) $id : null;

        if ($type === 'homepage') {
            return ['homepage', null];
        }

        if (!in_array($type, ['category', 'brand'], true)) {
            return [$type ?: null, null];
        }

        return [$type, $id];
    }

    private function attachLocationEntity($banner, $storeId)
    {
        [$type, $id] = $this->resolveBannerLocation($banner);

        $banner->resolved_location_type = $type;
        $banner->resolved_location_id   = $id;
        $banner->location_category      = null;
        $banner->location_brand         = null;

        if ($type === 'category' && $id) {
            $banner->location_category = Category::where('store_id', $storeId)
                ->where('id', $id)
                ->first();
        }

        if ($type === 'brand' && $id) {
            $banner->location_brand = Brand::where('store_id', $storeId)
                ->where('id', $id)
                ->first();
        }

        return $banner;
    }

    private function buildLocation(Request $request): array
    {
        $type = strtolower((string) $request->locationType);

        if ($type === 'homepage') {
            return ['key' => 'Homepage'];
        }

        if ($type === 'category') {
            return [
                'key' => 'category',
                'id'  => (int) $request->locationId,
            ];
        }

        if ($type === 'brand') {
            return [
                'key' => 'brand',
                'id'  => (int) $request->locationId,
            ];
        }

        return ['key' => 'search'];
    }

    private function buildDateRange(Request $request)
    {
        if (!$request->dateType || $request->dateType === 'always') {
            return 0;
        }

        return json_encode([
            'startDate' => $request->startDate,
            'endDate'   => $request->endDate,
        ]);
    }
}
