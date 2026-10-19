<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceTicket extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','printer_id','assigned_user_id','opened_by_user_id',
        'subject','description','category','priority','status','origin',
        'first_responded_at','resolved_at','closed_at',
    ];
    protected function casts(): array
    {
        return [
            'first_responded_at'=>'immutable_datetime',
            'resolved_at'=>'immutable_datetime',
            'closed_at'=>'immutable_datetime',
        ];
    }
    public function partRequests(): HasMany { return $this->hasMany(ServiceTicketPartRequest::class, 'ticket_id'); }
    public function signature(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ServiceTicketSignature::class, 'ticket_id');
    }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function printer(): BelongsTo { return $this->belongsTo(Printer::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function opener(): BelongsTo { return $this->belongsTo(User::class, 'opened_by_user_id'); }
    public function comments(): HasMany { return $this->hasMany(ServiceTicketComment::class, 'ticket_id'); }
    public function attachments(): HasMany { return $this->hasMany(ServiceTicketAttachment::class, 'ticket_id'); }
    public function linkedPrinters(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Printer::class, 'service_ticket_printers', 'ticket_id', 'printer_id');
    }
}
