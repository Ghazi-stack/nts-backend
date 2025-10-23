<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Logo;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\LogoResource;

class LogoController extends Controller
{
    public function store(Request $request)
    {
        $storeId = current_store_id();
        $validated = $request->validate([
            'logoUrl' => 'nullable|string',
            'faviconUrl' => 'nullable|string',
            'logoSize' => 'nullable|in:optimized,original',
        ]);
    
        $validated['store_id'] = $storeId;
        $logo = Logo::updateOrCreate(['store_id' => $storeId], $validated);
    
        return new LogoResource($logo);
    }
    
    public function show()
    {
        $storeId = current_store_id();
        $logo = Logo::where('store_id', $storeId)->first();
        return new LogoResource($logo);
    }
}
