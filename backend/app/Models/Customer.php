<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Customer extends Model
{
    protected $fillable = ['name', 'document', 'email', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function printers(): HasMany { return $this->hasMany(Printer::class); }
    public function locations(): HasMany { return $this->hasMany(CustomerLocation::class); }
    public function costCenters(): HasMany { return $this->hasMany(CostCenter::class); }
}
