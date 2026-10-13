<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockBalance extends Model
{
    protected $fillable = ['tenant_id', 'item_id', 'location_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'integer']; }
    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
}
