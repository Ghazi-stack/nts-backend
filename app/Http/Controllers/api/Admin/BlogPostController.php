<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;

class BlogPostController extends Controller
{
    /**
     * Display a paginated list of blog posts for the current store.
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

            $perPage = (int) $request->query('perPage', 20);
            $page = (int) $request->query('page', 1);

            $query = BlogPost::where('store_id', $storeId)
                ->where('is_draft', false)
                ->orderBy('id', 'desc');

            // Optional: search/filter by keyword
            if ($keyword = $request->query('keyword')) {
                $query->where('title', 'like', "%{$keyword}%");
            }

            $posts = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Published blog posts retrieved successfully',
                'data' => BlogPostResource::collection($posts),
                'pagination' => [
                    'total' => $posts->total(),
                    'count' => $posts->count(),
                    'perPage' => $posts->perPage(),
                    'currentPage' => $posts->currentPage(),
                    'totalPages' => $posts->lastPage(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Error fetching published blog posts: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve posts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show a single blog post by ID.
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

            $post = BlogPost::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Blog post retrieved successfully',
                'data' => new BlogPostResource($post),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching blog post: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Blog post not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Store a newly created blog post.
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

            // ✅ Merge camelCase → snake_case for DB
            $request->merge([
                'post_url' => $request->postUrl,
                'meta_description' => $request->metaDescription,
                'is_draft' => $request->isDraft, // 👈 new field
            ]);

            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
                'body' => 'nullable|string',
                'author' => 'nullable|string|max:255',
                'tags' => 'nullable|string|max:255',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // frontend sends "thumbnail"
                'post_url' => 'nullable|string|max:255|unique:blog_posts,post_url',
                'meta_description' => 'nullable|string|max:1000',
                'is_draft' => 'nullable', // ✅ added
            ]);

            // ✅ Upload thumbnail if exists
            $thumbnailName = null;
            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                $thumbnailName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('blog_images'), $thumbnailName);
            }

            // ✅ Create post
            $post = BlogPost::create([
                'store_id' => $storeId,
                'title' => $validated['title'] ?? null,
                'body' => $validated['body'] ?? null,
                'author' => $validated['author'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'thumbnail_image' => $thumbnailName,
                'post_url' => $validated['post_url'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'is_draft' => filter_var($validated['is_draft'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Blog post created successfully',
                'data' => new BlogPostResource($post),
            ], 201);

        } catch (Exception $e) {
            Log::error('Error creating blog post: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create blog post',
                'error' => $e->getMessage(),
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
        
            $post = BlogPost::where('store_id', $storeId)->findOrFail($id);
        
            // ✅ Merge camelCase → snake_case
            $request->merge([
                'post_url' => $request->postUrl,
                'meta_description' => $request->metaDescription,
                'is_draft' => $request->isDraft, 
            ]);
        
            $validated = $request->validate([
                'title' => 'nullable',
                'body' => 'nullable',
                'author' => 'nullable',
                'tags' => 'nullable',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp',
                'post_url' => 'nullable|string|max:255|unique:blog_posts,post_url,' . $id,
                'meta_description' => 'nullable',
                'is_draft' => 'nullable',
            ]);
        
            // ✅ Handle new thumbnail upload
            if ($request->hasFile('thumbnail')) {
                // Delete old file if exists
                if ($post->thumbnail_image && file_exists(public_path('blog_images/' . $post->thumbnail_image))) {
                    unlink(public_path('blog_images/' . $post->thumbnail_image));
                }
            
                $file = $request->file('thumbnail');
                $thumbnailName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('blog_images'), $thumbnailName);
                $validated['thumbnail_image'] = $thumbnailName;
            }
        
            $post->update([
                'title' => $validated['title'] ?? $post->title,
                'body' => $validated['body'] ?? $post->body,
                'author' => $validated['author'] ?? $post->author,
                'tags' => $validated['tags'] ?? $post->tags,
                'thumbnail_image' => $validated['thumbnail_image'] ?? $post->thumbnail_image,
                'post_url' => $validated['post_url'] ?? $post->post_url,
                'meta_description' => $validated['meta_description'] ?? $post->meta_description,
                'is_draft' => isset($validated['is_draft'])
                ? (filter_var($validated['is_draft'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                : $post->is_draft,
            ]);
        
            return response()->json([
                'status' => true,
                'message' => $post->is_draft ? 'Blog draft updated successfully' : 'Blog post updated successfully',
                'data' => new BlogPostResource($post),
            ]);
        
        } catch (Exception $e) {
            Log::error('Error updating blog post: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update blog post',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function drafts(Request $request)
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

            $query = BlogPost::where('store_id', $storeId)
                             ->where('is_draft', true)
                             ->orderBy('id', 'desc');

            $drafts = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Draft posts retrieved successfully',
                'data' => BlogPostResource::collection($drafts),
                'pagination' => [
                    'total' => $drafts->total(),
                    'count' => $drafts->count(),
                    'perPage' => $drafts->perPage(),
                    'currentPage' => $drafts->currentPage(),
                    'totalPages' => $drafts->lastPage(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Error fetching draft posts: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve drafts',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Delete one or multiple blog posts.
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
            $posts = BlogPost::where('store_id', $storeId)->whereIn('id', $idArray)->get();

            if ($posts->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching posts found'
                ], 404);
            }

            foreach ($posts as $post) {
                if ($post->thumbnail_image && file_exists(public_path('blog_images/' . $post->thumbnail_image))) {
                    unlink(public_path('blog_images/' . $post->thumbnail_image));
                }
                $post->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Blog posts deleted successfully'
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting blog posts: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete blog posts',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
