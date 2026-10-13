<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockItem extends Model
{
    protected $fillable = ['tenant_id', 'sku', 'name', 'category', 'unit', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function balances(): HasMany { return $this->hasMany(StockBalance::class, 'item_id'); }
}
