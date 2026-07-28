<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
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
