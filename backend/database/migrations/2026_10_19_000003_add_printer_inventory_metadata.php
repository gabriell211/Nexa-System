<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', static function (Blueprint $table): void {
            // Manual, audit-tracked inventory metadata. Unknown remains NULL;
            // no data is invented from IP/MAC or unverified vendor SNMP.
            $table->string('asset_number', 80)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('firmware_version', 160)->nullable();
            $table->string('owner_type', 12)->default('unknown');
            $table->string('connection_type', 12)->default('unknown');
            $table->string('color_capability', 12)->default('unknown');
            $table->index(['tenant_id', 'asset_number'], 'printer_asset_number_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('printers', static function (Blueprint $table): void {
            $table->dropIndex('printer_asset_number_lookup');
            $table->dropColumn([
                'asset_number', 'mac_address', 'firmware_version',
                'owner_type', 'connection_type', 'color_capability',
            ]);
        });
    }
};
