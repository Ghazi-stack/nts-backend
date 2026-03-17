<?php

namespace App\Jobs;

use App\Models\ProductImage;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;

class ProcessProductImage implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $tries = 2;
    public $timeout = 60;

    public function __construct(
        public $productId,
        public $imageValue,
        public $storeId,
        public $productName,
        public $productSlug
    ) {}

    public function handle()
    {
        try {

            $savedPath = null;
            $uploaded = false;

            /* ==============================
               (A) URL IMAGE
            ============================== */
            if (filter_var($this->imageValue, FILTER_VALIDATE_URL)) {

                $response = Http::timeout(12)
                    ->connectTimeout(5)
                    ->retry(1, 200)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0',
                        'Accept' => 'image/*',
                    ])
                    ->withOptions([
                        'verify' => false,
                    ])
                    ->get($this->imageValue);

                if (
                    !$response->successful() ||
                    !str_starts_with($response->header('Content-Type'), 'image/')
                ) {
                    return;
                }

                $originalName = pathinfo(
                    parse_url($this->imageValue, PHP_URL_PATH),
                    PATHINFO_FILENAME
                );

                $extension = pathinfo(
                    parse_url($this->imageValue, PHP_URL_PATH),
                    PATHINFO_EXTENSION
                );

                if (!$extension) {
                    $mime = $response->header('Content-Type');
                    $extension = explode('/', $mime)[1] ?? 'jpg';
                }

                $slug = Str::slug($this->productSlug ?: $this->productName);
                $slug = substr($slug, 0, 50);

                $fileName = $slug . '-' . uniqid() . '.' . $extension;

                $savedPath = "product_images/{$this->productId}/{$fileName}";

                $uploaded = Storage::disk('s3')->put(
                    $savedPath,
                    $response->body(),
                    [
                        'ContentType' => $response->header('Content-Type') ?? 'image/jpeg',
                    ]
                );
            }

            /* ==============================
               (B) LOCAL IMAGE
            ============================== */
            else {

                $localPath = public_path('product_images/' . $this->imageValue);

                if (!file_exists($localPath)) {
                    return;
                }

                $extension = pathinfo($this->imageValue, PATHINFO_EXTENSION) ?: 'jpg';

                $slug = Str::slug($this->productSlug ?: $this->productName);
                $slug = substr($slug, 0, 50);

                $fileName = $slug . '-' . uniqid() . '.' . $extension;

                $savedPath = "product_images/{$this->productId}/{$fileName}";

                $uploaded = Storage::disk('s3')->put(
                    $savedPath,
                    fopen($localPath, 'r'),
                    [
                        'ContentType' => mime_content_type($localPath) ?: 'image/jpeg',
                    ]
                );
            }

            /* ==============================
               SAVE IMAGE
            ============================== */
            if ($uploaded === true) {

                ProductImage::updateOrCreate(
                    [
                        'product_id' => $this->productId,
                        'path'       => $savedPath,
                        'url'        => $savedPath,
                    ],
                    [
                        'store_id'   => $this->storeId,
                        'alt_text'   => $this->productName,
                        'is_primary' => 1,
                    ]
                );
            }

        } catch (\Throwable $e) {
            // silent fail
        }
    }
}