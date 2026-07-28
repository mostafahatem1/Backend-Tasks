<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $user = $request->user();

        $query = Order::with('items')->orderBy('id', 'desc');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $orders = $query->get();

        return apiResponse(
            data: OrderResource::collection($orders),
            message: 'Orders retrieved successfully.',
            status: 200
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        $order->load('items');

        return apiResponse(
            data: new OrderResource($order),
            message: 'Order retrieved successfully.',
            status: 200
        );
    }

    public function store(StoreOrderRequest $request, OrderService $orderService): JsonResponse
    {
        try {
            $order = $orderService->createOrder($request->user(), $request->validated()['items']);

            return apiResponse(
                data: new OrderResource($order),
                message: 'Order created successfully.',
                status: 201
            );
        } catch (InsufficientStockException $e) {
            return apiResponse(
                data: [
                    'unavailable_items' => $e->getUnavailableItems(),
                ],
                message: 'One or more products do not have enough stock.',
                status: 409
            );
        }
    }
}
