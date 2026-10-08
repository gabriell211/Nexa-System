<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function printers(): HasMany { return $this->hasMany(Printer::class); }
}
