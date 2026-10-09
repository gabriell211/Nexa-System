<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerDepartment extends Model
{
    protected $fillable = ['tenant_id', 'customer_id', 'name', 'code', 'responsible_name', 'responsible_email', 'active'];

    protected function casts(): array { return ['active' => 'boolean']; }

    public function location(): BelongsTo { return $this->belongsTo(CustomerLocation::class, 'location_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
