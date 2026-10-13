<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class StockMovement extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'tenant_id', 'request_id', 'kind', 'item_id', 'source_location_id', 'destination_location_id',
        'quantity', 'source_before', 'source_after', 'destination_before', 'destination_after',
        'actor_user_id', 'reason',
    ];
    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'source_before' => 'integer', 'source_after' => 'integer',
            'destination_before' => 'integer', 'destination_after' => 'integer',
        ];
    }
    public function documentedReceipt(): HasOne { return $this->hasOne(StockReceiptDetail::class, 'movement_id'); }
    public function lotAllocations(): HasMany
    {
        return $this->hasMany(StockMovementLotAllocation::class, 'movement_id');
    }

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function source(): BelongsTo { return $this->belongsTo(StockLocation::class, 'source_location_id'); }
    public function destination(): BelongsTo { return $this->belongsTo(StockLocation::class, 'destination_location_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
