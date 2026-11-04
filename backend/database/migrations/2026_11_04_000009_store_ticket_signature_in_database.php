<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_ticket_signatures', static function (Blueprint $table): void {
            // Encrypted PNG payload, private and durable across stateless
            // Laravel/Vercel instances; null for historical on-prem evidence.
            $table->longText('png_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_ticket_signatures', static function (Blueprint $table): void {
            $table->dropColumn('png_ciphertext');
        });
    }
};
