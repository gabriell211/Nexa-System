<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockMovementLotAllocation extends Model
{
    protected $fillable = ['tenant_id', 'movement_id', 'lot_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'integer']; }
    public function lot(): BelongsTo { return $this->belongsTo(StockLot::class, 'lot_id'); }
}
