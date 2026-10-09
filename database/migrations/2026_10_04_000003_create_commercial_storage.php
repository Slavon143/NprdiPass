<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reservations commit independently from catalogue transactions.
        // A Company FK would wait on the caller's own uncommitted tenant row.
        Schema::create('commercial_storage_balances', function (Blueprint $table): void {
            $table->uuid('company_uuid')->primary();
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->dateTime('initialized_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_storage_objects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_uuid');
            $table->char('physical_key', 64);
            $table->string('disk', 64);
            $table->text('storage_key');
            $table->uuid('operation_uuid')->nullable();
            $table->string('state', 16);
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedBigInteger('charged_bytes');
            $table->timestamps();
            $table->unique(['company_uuid', 'physical_key'], 'commercial_storage_physical_unique');
            $table->index(['company_uuid', 'state'], 'commercial_storage_state_index');
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_storage_objects')->exists()) {
            throw new RuntimeException('Retain storage accounting during code rollback; physical reservations or history exist.');
        }
        Schema::dropIfExists('commercial_storage_objects');
        Schema::dropIfExists('commercial_storage_balances');
    }
};
