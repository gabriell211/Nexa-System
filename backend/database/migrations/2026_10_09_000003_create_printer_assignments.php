<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'customer_id', 'id'], 'printer_assignment_scope_unique');
        });
        Schema::table('customer_departments', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'customer_id', 'location_id', 'id'], 'department_assignment_scope_unique');
        });
        Schema::table('cost_centers', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'customer_id', 'id'], 'cost_center_assignment_scope_unique');
        });

        Schema::create('printer_assignments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('customer_id');
            $table->foreignId('printer_id');
            $table->foreignId('location_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'customer_id', 'printer_id'], 'assignment_printer_scope_fk')
                ->references(['tenant_id', 'customer_id', 'id'])->on('printers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id', 'location_id'], 'assignment_location_scope_fk')
                ->references(['tenant_id', 'customer_id', 'id'])->on('customer_locations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id', 'location_id', 'department_id'], 'assignment_department_scope_fk')
                ->references(['tenant_id', 'customer_id', 'location_id', 'id'])->on('customer_departments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id', 'cost_center_id'], 'assignment_center_scope_fk')
                ->references(['tenant_id', 'customer_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->index(['tenant_id', 'customer_id', 'assigned_at']);
            $table->index(['printer_id', 'assigned_at']);
        });

        // PostgreSQL and SQLite support partial unique indexes, preventing two
        // simultaneous open intervals even under concurrent application requests.
        DB::statement('CREATE UNIQUE INDEX printer_assignment_one_open ON printer_assignments (printer_id) WHERE released_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_assignments');
        Schema::table('cost_centers', static function (Blueprint $table): void {
            $table->dropUnique('cost_center_assignment_scope_unique');
        });
        Schema::table('customer_departments', static function (Blueprint $table): void {
            $table->dropUnique('department_assignment_scope_unique');
        });
        Schema::table('printers', static function (Blueprint $table): void {
            $table->dropUnique('printer_assignment_scope_unique');
        });
    }
};
