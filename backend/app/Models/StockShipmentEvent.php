<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockShipmentEvent extends Model
{
    protected $fillable = [
        'tenant_id','shipment_id','request_id','kind','quantity','reason','actor_user_id','movement_id',
    ];
    protected function casts(): array { return ['quantity'=>'integer']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function movement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'movement_id'); }
}
