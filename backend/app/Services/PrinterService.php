<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Printer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrinterService
{
    private const FIELDS = ['customer_id', 'manufacturer', 'model', 'serial_number', 'ip_address', 'status', 'active'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function create(Tenant $tenant, User $actor, array $data): Printer
    {
        return DB::transaction(function () use ($tenant, $actor, $data): Printer {
            $printer = $tenant->printers()->create($data + ['status' => 'unknown', 'active' => true]);
            $this->audit->write($tenant, $actor, 'printer.created', $printer, null, $printer->only(self::FIELDS));

            return $printer;
        });
    }

    public function update(Tenant $tenant, User $actor, int $id, array $data): Printer
    {
        return DB::transaction(function () use ($tenant, $actor, $id, $data): Printer {
            $printer = $tenant->printers()->lockForUpdate()->findOrFail($id);
            if (array_key_exists('customer_id', $data) &&
                (int) $data['customer_id'] !== (int) $printer->customer_id) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Transferência entre clientes exige processo específico e auditado.',
                ]);
            }

            $before = $printer->only(self::FIELDS);
            $printer->update($data);
            $printer->refresh();

            if ($before !== $printer->only(self::FIELDS)) {
                $this->audit->write($tenant, $actor, 'printer.updated', $printer, $before, $printer->only(self::FIELDS));
            }

            return $printer;
        });
    }

    public function deactivate(Tenant $tenant, User $actor, int $id): void
    {
        DB::transaction(function () use ($tenant, $actor, $id): void {
            $printer = $tenant->printers()->lockForUpdate()->findOrFail($id);
            if (!$printer->active) {
                return;
            }
            if ($printer->assignments()->whereNull('released_at')->exists()) {
                throw ValidationException::withMessages([
                    'printer' => 'Libere a localização da impressora antes de inativá-la.',
                ]);
            }

            $before = $printer->only(self::FIELDS);
            $printer->update(['active' => false]);
            $this->audit->write($tenant, $actor, 'printer.deactivated', $printer, $before, $printer->only(self::FIELDS));
        });
    }
}
