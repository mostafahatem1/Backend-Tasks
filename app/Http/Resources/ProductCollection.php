<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ProductResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => 'Products retrieved successfully.',
            'data' => $this->collection,
        ];
    }

    /**
     * Customize the pagination information for the resource.
     *
     * @param  Request  $request
     * @param  array  $paginated
     * @param  array  $default
     * @return array
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'links' => [
                'first' => $paginated['first_page_url'] ?? null,
                'last' => $paginated['last_page_url'] ?? null,
                'prev' => $paginated['prev_page_url'] ?? null,
                'next' => $paginated['next_page_url'] ?? null,
            ],
            'meta' => [
                'current_page' => (int) $paginated['current_page'],
                'per_page' => (int) $paginated['per_page'],
                'last_page' => (int) $paginated['last_page'],
                'total' => (int) $paginated['total'],
                'from' => $paginated['from'] !== null ? (int) $paginated['from'] : null,
                'to' => $paginated['to'] !== null ? (int) $paginated['to'] : null,
            ],
        ];
    }
}
