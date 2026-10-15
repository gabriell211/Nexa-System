<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockSupplierReturn extends Model
{
    protected $fillable = [
        'tenant_id', 'request_id', 'request_hash', 'purchase_receipt_match_id',
        'quantity', 'reason', 'status', 'version', 'created_by_user_id',
        'reviewed_by_user_id', 'reviewed_at', 'review_note', 'movement_id',
    ];
    protected $hidden = ['request_hash'];
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'version' => 'integer', 'reviewed_at' => 'datetime'];
    }
    public function purchaseMatch(): BelongsTo
    {
        return $this->belongsTo(StockPurchaseReceiptMatch::class, 'purchase_receipt_match_id');
    }
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
