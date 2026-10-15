<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectorIncidentNotification extends Model
{
    protected $guarded = [];
    protected function casts(): array
    {
        return ['attempted_at'=>'immutable_datetime','accepted_at'=>'immutable_datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(CollectorHealthIncident::class,'collector_health_incident_id');
    }
}
