<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\AbsoluteImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use AbsoluteImageUrl;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'slug' => $this->slug,
            'image_url' => $this->absoluteImageUrl($this->image_url),
            'products_count' => $this->products_count ?? null,
        ];
    }
}