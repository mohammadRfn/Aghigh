<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Models\Product;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug,
            ]),
            'primary_image' => $this->whenLoaded('primaryImage', fn (): ?array => $this->primaryImage === null ? null : (new ImageResource($this->primaryImage))->resolve($request)),
            'images' => $this->whenLoaded('images', fn (): array => ImageResource::collection($this->resource->getRelation('images'))->resolve($request)),
        ];
    }
}
