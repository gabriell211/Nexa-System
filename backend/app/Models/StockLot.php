<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockLot extends Model
{
    protected $fillable = [
        'tenant_id', 'item_id', 'receipt_detail_id', 'lot_number', 'expires_on', 'unit_cost_cents',
    ];
    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'unit_cost_cents' => 'integer'];
    }
    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function receipt(): BelongsTo { return $this->belongsTo(StockReceiptDetail::class, 'receipt_detail_id'); }
    public function balances(): HasMany { return $this->hasMany(StockLotBalance::class, 'lot_id'); }
}
