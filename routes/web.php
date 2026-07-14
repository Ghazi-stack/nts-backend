<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Dashboard\ChartController;
use App\Http\Controllers\api\SitemapController;
use App\Models\Store;
use App\Models\Logo;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function (Illuminate\Http\Request $request) {
    $storeId = $request->header('store-id') ?? $request->query('store_id');
    
    if ($storeId) {
        $store = Store::find($storeId);
    } else {
        $store = Store::latest()->first();
    }

    $logo = $store ? Logo::where('store_id', $store->id)->first() : null;
    $logoUrl = $logo ? $logo->logo_url : null;

    return view('welcome', [
        'store' => $store,
        'logoUrl' => $logoUrl,
    ]);
});

Route::get('/xmlsitemap', [SitemapController::class, 'index']);