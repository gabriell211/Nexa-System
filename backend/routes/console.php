<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('nexa:version', function (): void {
    $this->info('Nexa API — foundation 0.1.0');
})->purpose('Show Nexa API version');

Schedule::command('nexa:alerts:check-telemetry')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('nexa:alerts:check-collectors')->everyMinute()->withoutOverlapping();
Schedule::command('nexa:alerts:notify-collectors')->everyFiveMinutes()->withoutOverlapping();
