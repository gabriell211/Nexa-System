<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServiceTicketComment extends Model
{
    protected $fillable = ['tenant_id','ticket_id','author_user_id','internal','body'];
    protected function casts(): array { return ['internal'=>'boolean']; }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_user_id'); }
}
