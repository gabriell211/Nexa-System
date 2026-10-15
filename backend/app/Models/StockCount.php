<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockCount extends Model
{
    protected $fillable = [
        'tenant_id', 'request_id', 'item_id', 'location_id', 'expected_quantity',
        'counted_quantity', 'observed_movement_id', 'reason', 'status', 'counted_by_user_id', 'counted_at',
        'reviewed_by_user_id', 'reviewed_at', 'review_reason', 'movement_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer', 'counted_quantity' => 'integer',
            'observed_movement_id' => 'integer',
            'counted_at' => 'datetime', 'reviewed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
    public function countedBy(): BelongsTo { return $this->belongsTo(User::class, 'counted_by_user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function movement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'movement_id'); }
}
