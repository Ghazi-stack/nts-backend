<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\WebPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebPageResource;

class WebPageController extends Controller
{
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

            $perPage = (int) $request->query('perPage', 20);
            $page = (int) $request->query('page', 1);

            // Optional keyword search
            $keyword = trim((string) $request->query('keyword', ''));

            $query = WebPage::where('store_id', $storeId)
                            ->orderBy('id', 'desc');

            if ($keyword !== '') {
                $query->where('page_name', 'like', "%{$keyword}%");
            }

            $pages = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Web pages retrieved successfully',
                'data' => WebPageResource::collection($pages),
                'pagination' => [
                    'total' => $pages->total(),
                    'count' => $pages->count(),
                    'perPage' => $pages->perPage(),
                    'currentPage' => $pages->currentPage(),
                    'totalPages' => $pages->lastPage(),
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching web pages: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve pages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

            $page = WebPage::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Web page retrieved successfully',
                'data' => new WebPageResource($page),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching web page: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Web page not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

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
                'pageName' => 'nullable|string|max:255',
                'pageUrl' => 'nullable|string|max:255',
                'pageTitle' => 'nullable|string|max:255',
                'pageContent' => 'nullable|string',
                'pageType' => 'nullable|string|max:100',
                'templateLayoutFile' => 'nullable|string|max:255',
                'metaKeywords' => 'nullable|string|max:255',
                'metaDescription' => 'nullable|string|max:1000',
                'searchKeywords' => 'nullable|string|max:255',
                'displayAsHomePage' => 'boolean',
                'restrictToCustomersOnly' => 'boolean',
                'showInNavigation' => 'boolean',
                'sortOrder' => 'nullable',
                'parentPage' => 'nullable',
            ]);

            // Convert camelCase to snake_case for DB
            $data = [
                'store_id' => $storeId,
                'page_name' => $validated['pageName'] ?? null,
                'page_url' => $validated['pageUrl'] ?? null,
                'page_title' => $validated['pageTitle'] ?? null,
                'page_content' => $validated['pageContent'] ?? null,
                'page_type' => $validated['pageType'] ?? null,
                'template_layout_file' => $validated['templateLayoutFile'] ?? null,
                'meta_keywords' => $validated['metaKeywords'] ?? null,
                'meta_description' => $validated['metaDescription'] ?? null,
                'search_keywords' => $validated['searchKeywords'] ?? null,
                'display_as_home_page' => $validated['displayAsHomePage'] ?? false,
                'restrict_to_customers_only' => $validated['restrictToCustomersOnly'] ?? false,
                'show_in_navigation' => $validated['showInNavigation'] ?? false,
                'sort_order' => $validated['sortOrder'] ?? null,
                'parent_page' => $validated['parentPage'] ?? null,
            ];

            $page = WebPage::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Web page created successfully',
                'data' => new WebPageResource($page)
            ], 201);
        } catch (Exception $e) {
            Log::error('Error creating web page: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create page',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

            $page = WebPage::where('store_id', $storeId)->findOrFail($id);

            $validated = $request->validate([
                'pageName' => 'nullable|string|max:255',
                'pageUrl' => 'nullable|string|max:255',
                'pageTitle' => 'nullable|string|max:255',
                'pageContent' => 'nullable|string',
                'pageType' => 'nullable|string|max:100',
                'templateLayoutFile' => 'nullable|string|max:255',
                'metaKeywords' => 'nullable|string|max:255',
                'metaDescription' => 'nullable|string|max:1000',
                'searchKeywords' => 'nullable|string|max:255',
                'displayAsHomePage' => 'boolean',
                'restrictToCustomersOnly' => 'boolean',
                'showInNavigation' => 'boolean',
                'sortOrder' => 'nullable',
                'parentPage' => 'nullable',
            ]);

            $data = [
                'page_name' => $validated['pageName'] ?? $page->page_name,
                'page_url' => $validated['pageUrl'] ?? $page->page_url,
                'page_title' => $validated['pageTitle'] ?? $page->page_title,
                'page_content' => $validated['pageContent'] ?? $page->page_content,
                'page_type' => $validated['pageType'] ?? $page->page_type,
                'template_layout_file' => $validated['templateLayoutFile'] ?? $page->template_layout_file,
                'meta_keywords' => $validated['metaKeywords'] ?? $page->meta_keywords,
                'meta_description' => $validated['metaDescription'] ?? $page->meta_description,
                'search_keywords' => $validated['searchKeywords'] ?? $page->search_keywords,
                'display_as_home_page' => $validated['displayAsHomePage'] ?? $page->display_as_home_page,
                'restrict_to_customers_only' => $validated['restrictToCustomersOnly'] ?? $page->restrict_to_customers_only,
                'show_in_navigation' => $validated['showInNavigation'] ?? $page->show_in_navigation,
                'sort_order' => $validated['sortOrder'] ?? $page->sort_order,
                'parent_page' => $validated['parentPage'] ?? $page->parent_page,
            ];

            $page->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Web page updated successfully',
                'data' => new WebPageResource($page)
            ]);
        } catch (Exception $e) {
            Log::error('Error updating web page: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to update page',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

            $pages = WebPage::where('store_id', $storeId)
                ->whereIn('id', $idArray)
                ->get();

            if ($pages->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching pages found'
                ], 404);
            }

            foreach ($pages as $page) {
                $page->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Web pages deleted successfully'
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting web pages: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete pages',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
