<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'idempotency_key',
        'request_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'idempotency_key',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('status', $filters['status']);
                }
            )
            ->when(
                isset($filters['min_total']) && $filters['min_total'] !== null && $filters['min_total'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('total_amount', '>=', $filters['min_total']);
                }
            )
            ->when(
                isset($filters['max_total']) && $filters['max_total'] !== null && $filters['max_total'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('total_amount', '<=', $filters['max_total']);
                }
            )
            ->when(
                isset($filters['created_from']) && $filters['created_from'] !== null && $filters['created_from'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('created_at', '>=', $filters['created_from'] . ' 00:00:00');
                }
            )
            ->when(
                isset($filters['created_to']) && $filters['created_to'] !== null && $filters['created_to'] !== '',
                function (Builder $q) use ($filters) {
                    $q->where('created_at', '<=', $filters['created_to'] . ' 23:59:59');
                }
            );
    }

    public function scopeSortByOptions(
        Builder $query,
        string $sortBy = 'id',
        string $sortDirection = 'desc'
    ): Builder {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $direction);

        if ($sortBy !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('changed_at', 'asc')->orderBy('id', 'asc');
    }
}
