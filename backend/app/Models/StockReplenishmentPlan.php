<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockReplenishmentPlan extends Model
{
    protected $fillable = [
        'tenant_id', 'request_id', 'request_hash', 'status', 'version', 'due_on',
        'responsible_user_id', 'notes', 'created_by_user_id', 'approved_by_user_id',
        'approved_at', 'review_note', 'cancelled_by_user_id', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'due_on' => 'date:Y-m-d',
            'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(StockReplenishmentPlanItem::class, 'plan_id'); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
}
