<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CollectorAgent;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RegisterCollector extends Command
{
    protected $signature = 'nexa:collector-register {customer_id}';
    protected $description = 'Register a collector for an existing customer and print a one-time secret';

    public function handle(): int
    {
        $customer = Customer::query()->find((int) $this->argument('customer_id'));
        if (!$customer || !$customer->active) {
            $this->error('Customer not found or inactive.');
            return self::FAILURE;
        }

        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::query()->create([
            'collector_id' => $collectorId,
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);
        $this->warn('Store the token securely. It will not be shown again.');
        $this->line('Collector ID: '.$collectorId);
        $this->line('Collector token (one time): '.$token);
        return self::SUCCESS;
    }
}
