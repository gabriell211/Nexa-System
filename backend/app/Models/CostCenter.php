<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CostCenter extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
