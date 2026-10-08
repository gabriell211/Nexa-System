<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class BootstrapNexa extends Command
{
    protected $signature = 'nexa:bootstrap';
    protected $description = 'Create initial tenant and owner using environment secrets';

    public function handle(): int
    {
        $email = (string) env('NEXA_BOOTSTRAP_EMAIL', '');
        $password = (string) env('NEXA_BOOTSTRAP_PASSWORD', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Provide NEXA_BOOTSTRAP_EMAIL and a strong NEXA_BOOTSTRAP_PASSWORD (12+ chars).');
            return self::FAILURE;
        }
        DB::transaction(function () use ($email, $password): void {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => env('NEXA_BOOTSTRAP_TENANT', 'nexa-demo')],
                ['name' => env('NEXA_BOOTSTRAP_NAME', 'Nexa Demo')]
            );
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => 'Administrador Nexa', 'password' => Hash::make($password)]
            );
            $tenant->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
        });
        $this->info('Initial tenant and owner provisioned.');
        return self::SUCCESS;
    }
}
