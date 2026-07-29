<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request): OrderCollection
    {
        Gate::authorize('viewAny', Order::class);

        $validated = $request->validated();

        $orders = Order::query()
            ->with('items')
            ->visibleTo($request->user())
            ->filter($validated)
            ->sortByOptions(
                $validated['sort_by'] ?? 'id',
                $validated['sort_direction'] ?? 'desc'
            )
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return new OrderCollection($orders);
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
            $result = $orderService->createOrder(
                $request->user(),
                $request->validated()['items'],
                $request->idempotencyKey()
            );

            $order = $result['order'];
            $replayed = $result['replayed'];

            if ($replayed) {
                return apiResponse(
                    data: new OrderResource($order),
                    message: 'Order already created for this idempotency key.',
                    status: 200
                )->header('Idempotency-Replayed', 'true');
            }

            return apiResponse(
                data: new OrderResource($order),
                message: 'Order created successfully.',
                status: 201
            )->header('Idempotency-Replayed', 'false');
        } catch (InsufficientStockException $e) {
            return apiResponse(
                data: [
                    'unavailable_items' => $e->getUnavailableItems(),
                ],
                message: 'One or more products do not have enough stock.',
                status: 409
            );
        } catch (IdempotencyKeyConflictException $e) {
            return apiResponse(
                message: 'The idempotency key has already been used with a different order request.',
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
