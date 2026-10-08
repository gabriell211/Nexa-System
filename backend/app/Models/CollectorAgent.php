<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CollectorAgent extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'immutable_datetime'];
    }
}
