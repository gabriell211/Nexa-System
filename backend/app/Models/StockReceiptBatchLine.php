<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReceiptBatchLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'batch_id', 'line_number', 'receipt_detail_id'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceiptDetail::class, 'receipt_detail_id');
    }
}
