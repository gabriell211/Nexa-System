<?php
declare(strict_types=1);

// Playwright needs a second operator; the production bootstrap intentionally
// provisions only the owner. Refuse databases outside an isolated E2E file.
require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || !str_contains(strtolower(basename($database)), 'e2e') || !is_file($database)) {
    fwrite(STDERR, "Stock count fixture requires an existing SQLite *e2e* database file.\n");
    exit(1);
}

$password = (string) env('NEXA_BOOTSTRAP_PASSWORD', '');
$slug = (string) env('NEXA_BOOTSTRAP_TENANT', 'nexa-demo');
if (strlen($password) < 12) {
    fwrite(STDERR, "Set NEXA_BOOTSTRAP_PASSWORD for the isolated browser test.\n");
    exit(1);
}

$tenant = App\Models\Tenant::query()->where('slug', $slug)->firstOrFail();
$user = App\Models\User::query()->updateOrCreate(
    ['email' => 'stock-counter@example.test'],
    ['name' => 'Contador E2E', 'password' => Illuminate\Support\Facades\Hash::make($password)]
);
$tenant->users()->syncWithoutDetaching([$user->id => ['role' => 'warehouse']]);

fwrite(STDOUT, "Warehouse browser fixture ready.\n");
