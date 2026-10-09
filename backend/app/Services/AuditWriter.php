<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditWriter
{
    public function write(
        Tenant $tenant,
        User $actor,
        string $action,
        Model $subject,
        ?array $before,
        array $after
    ): void {
        AuditEntry::query()->create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => class_basename($subject),
            'entity_id' => $subject->getKey(),
            'origin' => 'backoffice-api',
            'before_state' => $before,
            'after_state' => $after,
            'created_at' => now(),
        ]);
    }
}
