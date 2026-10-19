<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServiceTicketPartRequest extends Model
{
    protected $fillable = [
        'tenant_id','ticket_id','stock_item_id','requested_by_user_id','request_id',
        'quantity','reason','status','reviewed_by_user_id','review_note','reviewed_at',
    ];

    protected function casts(): array
    {
        return ['quantity'=>'integer','reviewed_at'=>'immutable_datetime'];
    }

    public function stockItem(): BelongsTo { return $this->belongsTo(StockItem::class, 'stock_item_id'); }
    public function technician(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
}
