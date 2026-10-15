<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockPurchaseOrderLine extends Model
{
    protected $fillable = [
        'tenant_id','plan_id','purchase_order_id','plan_line_id','quantity','unit_cost_cents',
    ];
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost_cents' => 'integer'];
    }
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(StockPurchaseOrder::class, 'purchase_order_id');
    }
    public function receiptMatches(): HasMany { return $this->hasMany(StockPurchaseReceiptMatch::class, 'purchase_order_line_id'); }
    public function planLine(): BelongsTo
    {
        return $this->belongsTo(StockReplenishmentPlanItem::class, 'plan_line_id');
    }
}
