<?php

namespace App\Models;

use App\Enums\CatalogSyncStatus;
use Database\Factories\CatalogSyncLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
