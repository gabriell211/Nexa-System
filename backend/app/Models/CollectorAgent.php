<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectorAgent extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime'];
    }
}
