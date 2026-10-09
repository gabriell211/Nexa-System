<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Printer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

            $before = $printer->only(self::FIELDS);
            $printer->update(['active' => false]);
            $this->audit->write($tenant, $actor, 'printer.deactivated', $printer, $before, $printer->only(self::FIELDS));
        });
    }
}
