<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReceiptBatch extends Model
{
    protected $fillable = [
        'tenant_id', 'request_id', 'request_hash', 'actor_user_id',
        'supplier_id', 'invoice_reference', 'line_count',
    ];

    protected function casts(): array
    {
        return ['line_count' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(StockSupplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockReceiptBatchLine::class, 'batch_id');
    }
}
