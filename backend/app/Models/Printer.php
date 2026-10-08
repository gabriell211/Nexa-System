<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Printer extends Model
{
    protected $fillable = ['customer_id', 'manufacturer', 'model', 'serial_number', 'ip_address', 'status'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function readings(): HasMany { return $this->hasMany(PrinterReading::class); }
}
