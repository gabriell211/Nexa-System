<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Printer extends Model
{
    protected $fillable = ['customer_id', 'manufacturer', 'model', 'serial_number', 'ip_address', 'status', 'active', 'asset_number', 'mac_address', 'firmware_version', 'owner_type', 'connection_type', 'color_capability'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function readings(): HasMany { return $this->hasMany(PrinterReading::class); }
    /**
     * Most recent collected sample (source time), with insertion ID breaking
     * timestamp ties. Null meter fields mean unavailable, never zero.
     */
    public function latestReading(): HasOne
    {
        return $this->hasOne(PrinterReading::class)
            ->ofMany(['collected_at' => 'max', 'id' => 'max']);
    }

    public function assignments(): HasMany { return $this->hasMany(PrinterAssignment::class); }
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(PrinterAssignment::class)->whereNull('released_at');
    }
}
