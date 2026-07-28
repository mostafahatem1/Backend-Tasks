<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderStatusService;
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

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        OrderStatusService $orderStatusService
    ): JsonResponse {
        $actor = $request->user();
        $requestedStatus = OrderStatus::from($request->validated('status'));

        try {
            $result = $orderStatusService->updateStatus($order, $actor, $requestedStatus);
        } catch (InvalidOrderStatusTransitionException $e) {
            return apiResponse(
                data: [
                    'current_status' => $e->currentStatus->value,
                    'requested_status' => $e->requestedStatus->value,
                    'allowed_statuses' => array_map(fn ($s) => $s->value, $e->allowedStatuses),
                ],
                message: 'Invalid order status transition.',
                status: 409
            );
        }

        if (! $result['changed']) {
            $result['order']->load('items');

            return apiResponse(
                data: new OrderResource($result['order']),
                message: 'Order status is unchanged.',
                status: 200
            );
        }

        try {
            OrderStatusChanged::dispatch($result['history'], $result['order']->user_id);
        } catch (\Throwable $e) {
            report($e);
        }

        $result['order']->load('items');

        return apiResponse(
            data: new OrderResource($result['order']),
            message: 'Order status updated successfully.',
            status: 200
        );
    }
}
