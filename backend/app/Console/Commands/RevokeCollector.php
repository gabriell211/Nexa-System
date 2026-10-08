<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CollectorAgent;
use Illuminate\Console\Command;

final class RevokeCollector extends Command
{
    protected $signature = 'nexa:collector-revoke {collector_id}';
    protected $description = 'Revoke an existing collector immediately';

    public function handle(): int
    {
        $updated = CollectorAgent::query()
            ->where('collector_id', (string) $this->argument('collector_id'))
            ->where('active', true)
            ->update(['active' => false]);
        if (!$updated) {
            $this->error('Collector not found or already revoked.');
            return self::FAILURE;
        }
        $this->info('Collector revoked.');
        return self::SUCCESS;
    }
}
