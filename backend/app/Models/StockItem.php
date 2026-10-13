<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockItem extends Model
{
    protected $fillable = [
        'tenant_id','sku','name','category','unit','active','brand','color',
        'price_cents','cost_cents','yield_pages',
    ];
    protected function casts(): array { return ['active'=>'boolean','price_cents'=>'integer','cost_cents'=>'integer','yield_pages'=>'integer']; }
    public function compatibilities(): HasMany { return $this->hasMany(StockItemCompatibility::class, 'item_id'); }
    public function balances(): HasMany { return $this->hasMany(StockBalance::class, 'item_id'); }
}
