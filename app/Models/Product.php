<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'price',
        'description',
        'available_stock',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'available_stock' => 'integer',
        ];
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                isset($filters['search']) && $filters['search'] !== null && $filters['search'] !== '',
                function (Builder $q) use ($filters) {
                    $search = $filters['search'];
                    $q->where(function (Builder $sub) use ($search) {
                        $sub->where('title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                isset($filters['min_price']) && $filters['min_price'] !== null && $filters['min_price'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('price', '>=', $filters['min_price']);
                }
            )
            ->when(
                isset($filters['max_price']) && $filters['max_price'] !== null && $filters['max_price'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('price', '<=', $filters['max_price']);
                }
            )
            ->when(
                isset($filters['stock_status']) && $filters['stock_status'] !== null && $filters['stock_status'] !== '',
                function (Builder $q) use ($filters) {
                    if ($filters['stock_status'] === 'in_stock') {
                        $q->where('available_stock', '>', 0);
                    } elseif ($filters['stock_status'] === 'out_of_stock') {
                        $q->where('available_stock', '=', 0);
                    }
                }
            );
    }

    public function scopeSortByOptions(
        Builder $query,
        string $sortBy = 'id',
        string $sortDirection = 'desc'
    ): Builder {
        return $query->orderBy($sortBy, strtolower($sortDirection));
    }

    public function stockNotificationRequests(): HasMany
    {
        return $this->hasMany(ProductStockNotificationRequest::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
