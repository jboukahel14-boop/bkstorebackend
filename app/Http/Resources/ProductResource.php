<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\AbsoluteImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    use AbsoluteImageUrl;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'slug' => $this->slug,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'stock' => $this->stock,
            'description' => $this->description,
            'image_url' => $this->absoluteImageUrl($this->image_url),
            'is_featured' => (bool) $this->is_featured,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'category' => $this->whenLoaded(
                'category',
                fn () => CategoryResource::make($this->category)
            ),
        ];
    }
}