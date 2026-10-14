<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CollectorAgent extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function healthIncidents(): HasMany { return $this->hasMany(CollectorHealthIncident::class, 'collector_agent_id'); }
    public function activeHealthIncident(): HasOne { return $this->hasOne(CollectorHealthIncident::class, 'collector_agent_id')->where('status','active'); }
    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime',
            'watchdog_recent_incidents' => 'integer',
            'watchdog_circuit_open_reported' => 'boolean',
            'watchdog_reported_at' => 'immutable_datetime'];
    }
}
