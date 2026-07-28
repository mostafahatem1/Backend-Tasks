<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_title' => $this->product_title,
            'unit_price' => number_format((float) $this->unit_price, 2, '.', ''),
            'quantity' => (int) $this->quantity,
            'line_total' => number_format((float) $this->line_total, 2, '.', ''),
        ];
    }
}
