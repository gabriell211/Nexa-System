<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReceiptDetail extends Model
{
    protected $fillable = [
        'tenant_id','movement_id','supplier_id','invoice_reference',
        'lot_number','expires_on','unit_cost_cents',
    ];

    protected function casts(): array
    {
        return ['expires_on'=>'immutable_date','unit_cost_cents'=>'integer'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(StockSupplier::class, 'supplier_id'); }
    public function movement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'movement_id'); }
}
