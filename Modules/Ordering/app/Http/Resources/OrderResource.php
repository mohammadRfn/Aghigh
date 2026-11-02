<?php

declare(strict_types=1);

namespace Modules\Ordering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Models\OrderItem;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'version' => $order->version,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
                'currency' => $item->currency,
            ])->values()->all(),
            'expires_at' => $order->expires_at?->toIso8601ZuluString(),
            'created_at' => $order->created_at?->toIso8601ZuluString(),
            'updated_at' => $order->updated_at?->toIso8601ZuluString(),
        ];
    }
}
