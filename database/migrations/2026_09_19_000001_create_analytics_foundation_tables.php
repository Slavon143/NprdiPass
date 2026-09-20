<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('published_version_id')->nullable()->constrained('product_passport_versions')->nullOnDelete();
            $table->string('passport_public_id', 64);
            $table->string('event_type', 32);
            $table->char('country_code', 2)->default('XX');
            $table->string('device_category', 16);
            $table->string('referrer_category', 16);
            $table->boolean('bot_flag')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'occurred_at'], 'analytics_events_company_occurred_index');
            $table->index(['passport_public_id', 'occurred_at'], 'analytics_events_passport_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'analytics_events_type_occurred_index');
            $table->index(['bot_flag', 'occurred_at'], 'analytics_events_bot_occurred_index');
        });

        Schema::create('analytics_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->string('passport_public_id', 64);
            $table->string('event_type', 32);
            $table->date('aggregate_date');
            $table->char('country_code', 2)->default('XX');
            $table->string('device_category', 16);
            $table->string('referrer_category', 16);
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamps();

            $table->unique([
                'company_id',
                'product_id',
                'published_version_id',
                'passport_public_id',
                'event_type',
                'aggregate_date',
                'country_code',
                'device_category',
                'referrer_category',
            ], 'analytics_daily_unique');
            $table->index(['company_id', 'aggregate_date'], 'analytics_daily_company_date_index');
        });

        Schema::create('analytics_monthly_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->string('passport_public_id', 64);
            $table->string('event_type', 32);
            $table->date('aggregate_month');
            $table->char('country_code', 2)->default('XX');
            $table->string('device_category', 16);
            $table->string('referrer_category', 16);
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamps();

            $table->unique([
                'company_id',
                'product_id',
                'published_version_id',
                'passport_public_id',
                'event_type',
                'aggregate_month',
                'country_code',
                'device_category',
                'referrer_category',
            ], 'analytics_monthly_unique');
            $table->index(['company_id', 'aggregate_month'], 'analytics_monthly_company_month_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_monthly_aggregates');
        Schema::dropIfExists('analytics_daily_aggregates');
        Schema::dropIfExists('analytics_events');
    }
};
