<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->string('failure_code')->nullable()->after('response_body_excerpt');
            $table->text('failure_message')->nullable()->after('failure_code');
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body_excerpt')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_delivery_id', 'attempt']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['failure_code', 'failure_message']);
        });
    }
};
