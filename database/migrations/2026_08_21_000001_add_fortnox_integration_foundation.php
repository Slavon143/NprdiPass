<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortnox_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->unique();
            $table->string('provider')->default('fortnox');
            $table->string('status')->default('active');
            $table->string('external_tenant_id', 191)->nullable();
            $table->json('scopes')->nullable();
            $table->text('token_payload')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'fortnox_conn_company_id_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('connected_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('fortnox_oauth_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->foreignId('user_id')->nullable();
            $table->char('state_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'state_hash'], 'fortnox_states_company_hash_unique');
            $table->index(['company_id', 'expires_at'], 'fortnox_states_company_expiry_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('fortnox_article_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('fortnox_article_number', 100);
            $table->string('fortnox_article_id', 191)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->timestamp('fortnox_last_modified')->nullable();
            $table->json('last_applied_values')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'fortnox_map_company_id_unique');
            $table->unique(['company_id', 'connection_id', 'fortnox_article_number'], 'fortnox_map_article_unique');
            $table->index(['company_id', 'fortnox_article_number'], 'fortnox_map_article_lookup_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE fortnox_article_mappings ADD CONSTRAINT fortnox_map_connection_fk FOREIGN KEY (company_id, connection_id) REFERENCES fortnox_connections(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE fortnox_article_mappings ADD CONSTRAINT fortnox_map_product_fk FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE fortnox_article_mappings ADD CONSTRAINT fortnox_map_variant_fk FOREIGN KEY (company_id, variant_id) REFERENCES product_variants(company_id, id) ON DELETE RESTRICT');

        Schema::create('fortnox_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('trigger_type')->default('manual');
            $table->string('status')->default('running');
            $table->char('idempotency_key', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('articles_fetched')->default(0);
            $table->unsignedInteger('articles_created')->default(0);
            $table->unsignedInteger('articles_updated')->default(0);
            $table->unsignedInteger('articles_skipped')->default(0);
            $table->unsignedInteger('conflicts_raised')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('triggered_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'fortnox_runs_company_id_unique');
            $table->unique(['company_id', 'idempotency_key'], 'fortnox_runs_company_idem_unique');
            $table->index(['company_id', 'status', 'created_at'], 'fortnox_runs_company_status_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE fortnox_sync_runs ADD CONSTRAINT fortnox_runs_connection_fk FOREIGN KEY (company_id, connection_id) REFERENCES fortnox_connections(company_id, id) ON DELETE CASCADE');

        Schema::create('fortnox_sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('sync_run_id');
            $table->unsignedBigInteger('mapping_id');
            $table->string('field', 30);
            $table->json('fortnox_value')->nullable();
            $table->json('nordipass_value')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'fortnox_conf_company_id_unique');
            $table->unique(['company_id', 'sync_run_id', 'mapping_id', 'field'], 'fortnox_conf_run_mapping_field_unique');
            $table->index(['company_id', 'status'], 'fortnox_conf_company_status_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE fortnox_sync_conflicts ADD CONSTRAINT fortnox_conf_run_fk FOREIGN KEY (company_id, sync_run_id) REFERENCES fortnox_sync_runs(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE fortnox_sync_conflicts ADD CONSTRAINT fortnox_conf_mapping_fk FOREIGN KEY (company_id, mapping_id) REFERENCES fortnox_article_mappings(company_id, id) ON DELETE CASCADE');

        Schema::create('fortnox_field_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('mapping_id');
            $table->string('field', 30);
            $table->string('source', 20);
            $table->json('manual_value')->nullable();
            $table->foreignId('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'fortnox_ovr_company_id_unique');
            $table->unique(['company_id', 'mapping_id', 'field'], 'fortnox_ovr_mapping_field_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('overridden_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE fortnox_field_overrides ADD CONSTRAINT fortnox_ovr_mapping_fk FOREIGN KEY (company_id, mapping_id) REFERENCES fortnox_article_mappings(company_id, id) ON DELETE CASCADE');

        DB::statement("ALTER TABLE fortnox_connections ADD CONSTRAINT fortnox_conn_provider_check CHECK (provider IN ('fortnox'))");
        DB::statement("ALTER TABLE fortnox_connections ADD CONSTRAINT fortnox_conn_status_check CHECK (status IN ('active', 'reconnect_required', 'revoked'))");
        DB::statement("ALTER TABLE fortnox_oauth_states ADD CONSTRAINT fortnox_states_hash_check CHECK (state_hash REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE fortnox_sync_runs ADD CONSTRAINT fortnox_runs_trigger_check CHECK (trigger_type IN ('manual', 'scheduled', 'webhook'))");
        DB::statement("ALTER TABLE fortnox_sync_runs ADD CONSTRAINT fortnox_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE fortnox_sync_runs ADD CONSTRAINT fortnox_runs_idem_check CHECK (idempotency_key REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE fortnox_sync_conflicts ADD CONSTRAINT fortnox_conf_field_check CHECK (field IN ('article_number', 'name', 'description', 'price', 'stock', 'gtin'))");
        DB::statement("ALTER TABLE fortnox_sync_conflicts ADD CONSTRAINT fortnox_conf_status_check CHECK (status IN ('open', 'resolved_fortnox', 'resolved_nordipass'))");
        DB::statement("ALTER TABLE fortnox_field_overrides ADD CONSTRAINT fortnox_ovr_field_check CHECK (field IN ('article_number', 'name', 'description', 'price', 'stock', 'gtin'))");
        DB::statement("ALTER TABLE fortnox_field_overrides ADD CONSTRAINT fortnox_ovr_source_check CHECK (source IN ('fortnox', 'nordipass'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fortnox_field_overrides');
        Schema::dropIfExists('fortnox_sync_conflicts');
        Schema::dropIfExists('fortnox_sync_runs');
        Schema::dropIfExists('fortnox_article_mappings');
        Schema::dropIfExists('fortnox_oauth_states');
        Schema::dropIfExists('fortnox_connections');
    }
};
