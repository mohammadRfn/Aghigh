<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Models\ProductImage;

/** @mixin ProductImage */
final class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => rtrim((string) config('catalog.image_base_url'), '/').'/'.ltrim($this->path, '/'),
            'path' => $this->path,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
            'alt' => $this->alt,
        ];
    }
}
