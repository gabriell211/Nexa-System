<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockReplenishmentPlanItem extends Model
{
    protected $fillable = [
        'tenant_id', 'plan_id', 'item_id', 'location_id', 'policy_id', 'origin',
        'available_quantity', 'suggested_quantity', 'critical_quantity',
        'safety_quantity', 'minimum_quantity', 'target_quantity', 'quantity',
        'safety_item', 'note',
    ];

    protected function casts(): array
    {
        return [
            'available_quantity' => 'integer', 'suggested_quantity' => 'integer',
            'critical_quantity' => 'integer', 'safety_quantity' => 'integer',
            'minimum_quantity' => 'integer', 'target_quantity' => 'integer',
            'quantity' => 'integer', 'safety_item' => 'boolean',
        ];
    }

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
    public function purchaseOrderLines(): HasMany { return $this->hasMany(StockPurchaseOrderLine::class, 'plan_line_id'); }
    public function shipments(): HasMany { return $this->hasMany(StockShipment::class, 'replenishment_plan_item_id'); }
}
