<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MigrateOnStart extends Command
{
    protected $signature = 'nexa:migrate-on-start';

    protected $description = 'Apply pending Nexa migrations before accepting production traffic';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('Production migration startup requires PostgreSQL.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $lockId = 716977731;
        $locked = false;

        try {
            // Serialize startup migrations across concurrent container instances.
            $connection->select('SELECT pg_advisory_lock(?)', [$lockId]);
            $locked = true;

            return $this->call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to prepare Nexa database. The API will not start.');

            return self::FAILURE;
        } finally {
            if ($locked) {
                try {
                    $connection->select('SELECT pg_advisory_unlock(?)', [$lockId]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }
}
