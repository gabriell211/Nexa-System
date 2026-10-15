<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectorNotificationReplay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempted_at'=>'immutable_datetime','accepted_at'=>'immutable_datetime'];
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(CollectorIncidentNotification::class,'original_notification_id');
    }
}
