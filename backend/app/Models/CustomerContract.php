<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerContract extends Model
{
    protected $fillable = [
        'tenant_id', 'customer_id', 'document_number', 'title', 'provider_unit_name',
        'billing_address', 'billing_timezone', 'effective_on', 'billing_starts_on',
        'ends_on', 'active', 'is_default', 'version', 'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'immutable_date',
            'billing_starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'active' => 'boolean', 'is_default' => 'boolean', 'version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
