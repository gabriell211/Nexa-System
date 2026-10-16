<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockSupplierReplacementReceipt extends Model
{
    protected $fillable = [
        'tenant_id', 'replacement_case_id', 'purchase_receipt_match_id', 'quantity',
        'request_id', 'request_hash', 'note', 'created_by_user_id',
    ];
    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function purchaseMatch(): BelongsTo
    {
        return $this->belongsTo(StockPurchaseReceiptMatch::class, 'purchase_receipt_match_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
