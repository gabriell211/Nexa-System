<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrinterAssignment extends Model
{
    protected $fillable = [
        'tenant_id', 'customer_id', 'printer_id', 'location_id', 'department_id',
        'cost_center_id', 'actor_user_id', 'reason', 'assigned_at', 'released_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function location(): BelongsTo { return $this->belongsTo(CustomerLocation::class); }
    public function department(): BelongsTo { return $this->belongsTo(CustomerDepartment::class); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CostCenter::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function printer(): BelongsTo { return $this->belongsTo(Printer::class); }
}
