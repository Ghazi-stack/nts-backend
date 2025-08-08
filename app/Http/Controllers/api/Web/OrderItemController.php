<?php

namespace App\Http\Controllers\api\Web;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Resources\OrderItemResource;

class OrderItemController extends Controller
{
      public function index($orderId)
    {
        $items = OrderItem::with('product')
            ->where('order_id', $orderId)
            ->get();

        return response()->json([
            'status' => true,
            'data' => OrderItemResource::collection($items)
        ]);
    }

    public function store(Request $request, $orderId)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);

        $item = OrderItem::create([
            'store_id'   => current_store_id(),
            'order_id'   => $orderId,
            'product_id' => $product->id,
            'quantity'   => $request->quantity,
            'price'      => $product->price,
        ]);

        $this->recalculateOrderTotal($orderId);

        return response()->json([
            'status' => true,
            'message' => 'Item added to order.',
            'data' => new OrderItemResource($item->load('product')),
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'nullable|integer|min:1',
            'price'    => 'nullable|numeric|min:0'
        ]);

        $item = OrderItem::findOrFail($id);

        if ($request->has('quantity')) {
            $item->quantity = $request->quantity;
        }

        if ($request->has('price')) {
            $item->price = $request->price;
        }

        $item->save();
        $this->recalculateOrderTotal($item->order_id);

        return response()->json([
            'status' => true,
            'message' => 'Order item updated.',
            'data' => new OrderItemResource($item->load('product')),
        ]);
    }

    public function destroy($id)
    {
        $item = OrderItem::findOrFail($id);
        $orderId = $item->order_id;
        $item->delete();

        $this->recalculateOrderTotal($orderId);

        return response()->json([
            'status' => true,
            'message' => 'Item deleted from order.',
        ]);
    }

    protected function recalculateOrderTotal($orderId)
    {
        $order = Order::find($orderId);
        $itemsTotal = OrderItem::where('order_id', $orderId)
            ->sum(\DB::raw('price * quantity'));

        $order->total_amount = $itemsTotal + ($order->shipping_cost ?? 0);
        $order->save();
    }

    public function webIndex($orderId)
    {
        $user = auth('sanctum')->user();

        // Ensure user owns the order
        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('store_id', current_store_id())
            ->first();

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found or access denied.'
            ], 404);
        }

        $items = $order->items()->with('product')->get();

        return response()->json([
            'status' => true,
            'data' => OrderItemResource::collection($items)
        ]);
    }
}
