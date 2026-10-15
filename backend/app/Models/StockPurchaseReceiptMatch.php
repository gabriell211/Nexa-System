<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockPurchaseReceiptMatch extends Model
{
    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'purchase_order_line_id', 'receipt_detail_id',
        'quantity', 'received_unit_cost_cents', 'variance_reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_unit_cost_cents' => 'integer'];
    }

    public function supplierReturns(): HasMany
    {
        return $this->hasMany(StockSupplierReturn::class, 'purchase_receipt_match_id');
    }
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(StockPurchaseOrder::class, 'purchase_order_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceiptDetail::class, 'receipt_detail_id');
    }
}
