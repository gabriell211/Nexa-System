<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

Artisan::command('nexa:version', function (): void {
    $this->info('Nexa API — foundation 0.1.0');
})->purpose('Show Nexa API version');
