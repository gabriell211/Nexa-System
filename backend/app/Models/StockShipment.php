<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockShipment extends Model
{
    protected $fillable = [
        'tenant_id','request_id','item_id','source_location_id','destination_location_id',
        'quantity','shipped_quantity','received_quantity','lost_quantity','cancelled_quantity',
        'status','reason','created_by',
    ];
    protected function casts(): array
    {
        return [
            'quantity'=>'integer','shipped_quantity'=>'integer','received_quantity'=>'integer',
            'lost_quantity'=>'integer','cancelled_quantity'=>'integer',
        ];
    }
    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function source(): BelongsTo { return $this->belongsTo(StockLocation::class, 'source_location_id'); }
    public function destination(): BelongsTo { return $this->belongsTo(StockLocation::class, 'destination_location_id'); }
    public function events(): HasMany { return $this->hasMany(StockShipmentEvent::class, 'shipment_id'); }
}
