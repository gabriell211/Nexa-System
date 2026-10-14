<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrinterAlert extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','printer_id','ticket_id','created_by_user_id',
        'origin','telemetry_anchor_id','code','category','severity','status','title','description',
        'acknowledged_at','resolved_at','ignored_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'ignored_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function printer(): BelongsTo { return $this->belongsTo(Printer::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(ServiceTicket::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
