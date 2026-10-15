<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockPurchaseOrder extends Model
{
    protected $fillable = [
        'tenant_id','request_id','request_hash','plan_id','supplier_id','status',
        'version','notes','created_by_user_id','approved_by_user_id','approved_at',
        'review_note','cancelled_by_user_id','cancelled_at','cancel_reason',
    ];
    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockPurchaseOrderLine::class, 'purchase_order_id');
    }
    public function supplier(): BelongsTo { return $this->belongsTo(StockSupplier::class, 'supplier_id'); }
    public function plan(): BelongsTo { return $this->belongsTo(StockReplenishmentPlan::class, 'plan_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
}
