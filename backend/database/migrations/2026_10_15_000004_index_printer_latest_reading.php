<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The latest-of-many relationship filters by printer_id, then
        // maximizes collection time and breaks ties with the insertion ID.
        Schema::table('printer_readings', static function (Blueprint $table): void {
            $table->index(
                ['printer_id', 'collected_at', 'id'],
                'printer_latest_collected_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('printer_readings', static function (Blueprint $table): void {
            $table->dropIndex('printer_latest_collected_idx');
        });
    }
};
