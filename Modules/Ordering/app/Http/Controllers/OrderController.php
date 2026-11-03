<?php

declare(strict_types=1);

namespace Modules\Ordering\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Contracts\CurrentUser;
use Modules\Ordering\Actions\CancelOrder;
use Modules\Ordering\Actions\PlaceOrder;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Http\Middleware\RequireIdempotencyKey;
use Modules\Ordering\Http\Requests\ListOrdersRequest;
use Modules\Ordering\Http\Requests\PlaceOrderRequest;
use Modules\Ordering\Http\Resources\OrderResource;
use Modules\Ordering\Models\Order;

#[Group('Orders')]
final class OrderController
{
    /**
     * Place an order. Requires `Idempotency-Key` (16-64 chars of A-Z a-z 0-9 _ -).
     *
     * Prices are read server-side; stock is reserved for `ordering.reservation_ttl_seconds`.
     * A repeated key with the same payload replays the stored response (`Idempotency-Replayed: true`);
     * with a different payload it is 422 "idempotency-key-reuse". 409 "out-of-stock" stores nothing.
     */
    public function store(PlaceOrderRequest $request, CurrentUser $actor, PlaceOrder $place): JsonResponse
    {
        $key = (string) $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE);
        $items = $request->items();

        $placement = $place($actor->id, $key, hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)), $items);

        $headers = $placement->replayed ? ['Idempotency-Replayed' => 'true'] : [];
        if ($placement->orderId !== null) {
            $headers['Location'] = route('api.v1.ordering.orders.show', ['order' => $placement->orderId], false);
        }

        return new JsonResponse($placement->body, $placement->status, $headers);
    }

    /** The caller's own orders, newest first. Keyset pagination: `per_page`, `cursor` (from `meta.next_cursor`), optional `status`. */
    public function index(ListOrdersRequest $request, CurrentUser $actor): JsonResponse
    {
        $perPage = $request->perPage();
        $query = Order::query()->with('items')->where('user_id', $actor->id)->orderByDesc('id')->limit($perPage + 1);
        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }
        if (($cursor = $request->cursor()) !== null) {
            $query->where('id', '<', $cursor);
        }

        $orders = $query->get();
        $page = $orders->take($perPage)->values();
        $last = $page->last();

        return new JsonResponse([
            'data' => $page->map(fn (Order $order): array => (new OrderResource($order))->resolve())->all(),
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => $orders->count() > $perPage && $last !== null ? ListOrdersRequest::encodeCursor($last->id) : null,
            ],
        ]);
    }

    /** One of the caller's orders. Someone else's order is indistinguishable from an unknown one (404). */
    public function show(string $order, CurrentUser $actor): JsonResponse
    {
        return new JsonResponse(['data' => (new OrderResource($this->owned($order, $actor)))->resolve()]);
    }

    /** Cancel one of the caller's orders and release its stock. Paid, cancelled or expired orders: 409 "invalid-order-transition". */
    public function cancel(string $order, CurrentUser $actor, CancelOrder $cancel): JsonResponse
    {
        $cancelled = $cancel($this->owned($order, $actor));

        return new JsonResponse(['data' => (new OrderResource($cancelled))->resolve()]);
    }

    private function owned(string $id, CurrentUser $actor): Order
    {
        $order = Order::query()->with('items')->whereKey(strtolower($id))->where('user_id', $actor->id)->first();
        if ($order === null) {
            throw new OrderingProblem('Order not found.', 404, 'not-found');
        }

        return $order;
    }
}
