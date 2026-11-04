<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ServiceTicketSignature extends Model
{
    protected $fillable = [
        'tenant_id','ticket_id','technician_user_id','signer_name','service_summary',
        'storage_path','png_ciphertext','sha256','signed_at','ip_address','user_agent',
    ];
    protected $hidden = ['storage_path','png_ciphertext','ip_address','user_agent'];
    protected function casts(): array { return ['signed_at'=>'immutable_datetime']; }
}
