<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CollectorHealthIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at'=>'immutable_datetime',
            'detected_at'=>'immutable_datetime',
            'resolved_at'=>'immutable_datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CollectorAgent::class, 'collector_agent_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CollectorIncidentNotification::class,'collector_health_incident_id');
    }

    public function notificationReplays(): HasMany
    {
        return $this->hasMany(CollectorNotificationReplay::class,'collector_health_incident_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
