<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockLotBalance extends Model
{
    protected $fillable = ['tenant_id', 'lot_id', 'location_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'integer']; }
    public function lot(): BelongsTo { return $this->belongsTo(StockLot::class, 'lot_id'); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
}
