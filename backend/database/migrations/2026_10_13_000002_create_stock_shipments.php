<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_shipments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->uuid('request_id');
            $table->foreignId('item_id');
            $table->foreignId('source_location_id');
            $table->foreignId('destination_location_id');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('shipped_quantity')->default(0);
            $table->unsignedBigInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('lost_quantity')->default(0);
            $table->unsignedBigInteger('cancelled_quantity')->default(0);
            $table->string('status', 32)->default('reserved');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id'], 'stock_shipment_create_once');
            $table->unique(['tenant_id', 'id'], 'stock_shipment_scope_id');
            $table->foreign(['tenant_id', 'item_id'], 'stock_shipment_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'source_location_id'], 'stock_shipment_source_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_location_id'], 'stock_shipment_dest_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'item_id', 'source_location_id', 'status'], 'shipment_reservations_lookup');
        });

        Schema::create('stock_shipment_events', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('shipment_id');
            $table->uuid('request_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('quantity');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'request_id'], 'stock_shipment_event_once');
            $table->foreign(['tenant_id', 'shipment_id'], 'shipment_event_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_shipments')->restrictOnDelete();
            $table->index(['shipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_shipment_events');
        Schema::dropIfExists('stock_shipments');
    }
};
