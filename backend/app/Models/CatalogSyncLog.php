<?php

namespace App\Models;

use App\Enums\CatalogSyncStatus;
use Database\Factories\CatalogSyncLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property CatalogSyncStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array<array-key, mixed>|null $summary
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Restaurant $restaurant
 */
#[Fillable([
    'restaurant_id',
    'status',
    'started_at',
    'finished_at',
    'summary',
    'error_message',
])]
class CatalogSyncLog extends Model
{
    /** @use HasFactory<CatalogSyncLogFactory> */
    use HasFactory;

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogSyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }
}
