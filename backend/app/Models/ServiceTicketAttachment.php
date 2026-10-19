<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServiceTicketAttachment extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'tenant_id', 'ticket_id', 'uploaded_by_user_id',
        'original_name', 'storage_path', 'mime_type', 'bytes',
    ];
    protected function casts(): array { return ['created_at'=>'immutable_datetime']; }
    public function ticket(): BelongsTo { return $this->belongsTo(ServiceTicket::class, 'ticket_id'); }
}
