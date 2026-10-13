<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockLocation extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'kind', 'customer_id', 'customer_location_id', 'technician_user_id', 'active',
    ];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function balances(): HasMany { return $this->hasMany(StockBalance::class, 'location_id'); }
}
