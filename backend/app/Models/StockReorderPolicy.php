<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReorderPolicy extends Model
{
    protected $fillable = [
        'tenant_id', 'item_id', 'location_id', 'critical_quantity',
        'safety_quantity', 'minimum_quantity', 'target_quantity',
    ];

    protected function casts(): array
    {
        return [
            'critical_quantity' => 'integer',
            'safety_quantity' => 'integer',
            'minimum_quantity' => 'integer',
            'target_quantity' => 'integer',
        ];
    }

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
}
