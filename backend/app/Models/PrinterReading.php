<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrinterReading extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array { return ['collected_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime']; }
    public function printer(): BelongsTo { return $this->belongsTo(Printer::class); }
}
