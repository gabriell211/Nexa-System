<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerLocation extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'code', 'document', 'address_line', 'number',
        'district', 'city', 'state', 'postal_code', 'country', 'active',
    ];

    protected function casts(): array { return ['active' => 'boolean']; }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function departments(): HasMany { return $this->hasMany(CustomerDepartment::class, 'location_id'); }
}
