<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_usage_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'metric', 'window_start'], 'commercial_usage_window_unique');
        });
        Schema::create('commercial_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bucket_id')->constrained('commercial_usage_buckets')->cascadeOnDelete();
            $table->string('metric', 32);
            $table->uuid('operation_id');
            $table->unsignedBigInteger('units');
            $table->dateTime('created_at');
            $table->unique(['company_id', 'metric', 'operation_id'], 'commercial_usage_operation_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_usage_events')->exists()) {
            throw new RuntimeException('Commercial usage exists; retain the additive schema during code rollback.');
        }
        Schema::dropIfExists('commercial_usage_events');
        Schema::dropIfExists('commercial_usage_buckets');
    }
};
