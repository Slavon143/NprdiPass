<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attribute_definitions DROP CHECK attr_defs_status_check');
        DB::statement('ALTER TABLE attribute_options DROP CHECK attr_options_status_check');

        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->boolean('vocabulary_controlled')->default(false)->after('searchable');
            $table->unsignedBigInteger('replaced_by_attribute_definition_id')->nullable()->after('status');
        });

        Schema::table('attribute_options', function (Blueprint $table): void {
            $table->unsignedBigInteger('replaced_by_attribute_option_id')->nullable()->after('status');
            $table->string('approval_status')->default('approved')->after('replaced_by_attribute_option_id');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
        });

        Schema::create('category_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->boolean('active_slot')->nullable();
            $table->string('readiness_profile');
            $table->unsignedInteger('readiness_profile_version');
            $table->char('readiness_rule_set_fingerprint', 64);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'cat_tpl_company_id_unique');
            $table->unique(['company_id', 'category_id', 'id'], 'cat_tpl_company_cat_id_unique');
            $table->unique(['company_id', 'category_id', 'version'], 'cat_tpl_company_cat_version_unique');
            $table->unique(['company_id', 'category_id', 'active_slot'], 'cat_tpl_one_active_unique');
            $table->index(['company_id', 'status'], 'cat_tpl_company_status_index');

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('activated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE category_templates ADD CONSTRAINT cat_tpl_company_category_fk FOREIGN KEY (company_id, category_id) REFERENCES categories(company_id, id) ON DELETE CASCADE');

        Schema::create('category_template_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('category_template_id');
            $table->unsignedBigInteger('attribute_definition_id');
            $table->string('requirement');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'category_template_id', 'attribute_definition_id'],
                'cat_tpl_attrs_assignment_unique',
            );
            $table->index(['company_id', 'category_template_id', 'requirement'], 'cat_tpl_attrs_requirement_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE category_template_attributes ADD CONSTRAINT cat_tpl_attrs_template_fk FOREIGN KEY (company_id, category_template_id) REFERENCES category_templates(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE category_template_attributes ADD CONSTRAINT cat_tpl_attrs_definition_fk FOREIGN KEY (company_id, attribute_definition_id) REFERENCES attribute_definitions(company_id, id) ON DELETE RESTRICT');

        Schema::create('category_template_dpp_defaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('category_template_id');
            $table->string('section_key');
            $table->json('default_data');
            $table->timestamps();

            $table->unique(
                ['company_id', 'category_template_id', 'section_key'],
                'cat_tpl_dpp_defaults_unique',
            );
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE category_template_dpp_defaults ADD CONSTRAINT cat_tpl_dpp_template_fk FOREIGN KEY (company_id, category_template_id) REFERENCES category_templates(company_id, id) ON DELETE CASCADE');

        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_template_id')->nullable()->after('parent_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('taxonomy_template_id')->nullable()->after('primary_category_id');
            $table->boolean('taxonomy_needs_review')->default(false)->after('taxonomy_template_id');
            $table->timestamp('taxonomy_reviewed_at')->nullable()->after('taxonomy_needs_review');
            $table->string('readiness_profile_override')->nullable()->after('taxonomy_reviewed_at');
            $table->unsignedInteger('readiness_profile_version_override')->nullable()->after('readiness_profile_override');
            $table->char('readiness_rule_set_fingerprint_override', 64)->nullable()->after('readiness_profile_version_override');
            $table->index(['company_id', 'taxonomy_needs_review'], 'products_company_taxonomy_review_index');
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_active_template_fk FOREIGN KEY (company_id, id, active_template_id) REFERENCES category_templates(company_id, category_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_taxonomy_template_fk FOREIGN KEY (company_id, taxonomy_template_id) REFERENCES category_templates(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_replacement_fk FOREIGN KEY (company_id, replaced_by_attribute_definition_id) REFERENCES attribute_definitions(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE attribute_options ADD CONSTRAINT attr_options_replacement_fk FOREIGN KEY (company_id, attribute_definition_id, replaced_by_attribute_option_id) REFERENCES attribute_options(company_id, attribute_definition_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE attribute_options ADD CONSTRAINT attr_options_approved_by_fk FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_status_check CHECK (status IN ('active', 'deprecated', 'archived'))");
        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_controlled_type_check CHECK (vocabulary_controlled = 0 OR type IN ('select', 'multiselect'))");
        DB::statement("ALTER TABLE attribute_options ADD CONSTRAINT attr_options_status_check CHECK (status IN ('active', 'deprecated', 'archived'))");
        DB::statement("ALTER TABLE attribute_options ADD CONSTRAINT attr_options_approval_check CHECK (approval_status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE category_templates ADD CONSTRAINT cat_tpl_status_check CHECK (status IN ('draft', 'active', 'retired'))");
        DB::statement("ALTER TABLE category_templates ADD CONSTRAINT cat_tpl_active_slot_check CHECK ((status = 'active' AND active_slot = 1) OR (status <> 'active' AND active_slot IS NULL))");
        DB::statement('ALTER TABLE category_templates ADD CONSTRAINT cat_tpl_version_check CHECK (version > 0)');
        DB::statement("ALTER TABLE category_templates ADD CONSTRAINT cat_tpl_fingerprint_check CHECK (readiness_rule_set_fingerprint REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE category_template_attributes ADD CONSTRAINT cat_tpl_attrs_requirement_check CHECK (requirement IN ('required', 'recommended'))");
        DB::statement('ALTER TABLE category_template_attributes ADD CONSTRAINT cat_tpl_attrs_sort_check CHECK (sort_order >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_profile_override_check CHECK ((readiness_profile_override IS NULL AND readiness_profile_version_override IS NULL AND readiness_rule_set_fingerprint_override IS NULL) OR (readiness_profile_override IS NOT NULL AND readiness_profile_version_override IS NOT NULL AND readiness_rule_set_fingerprint_override REGEXP '^[0-9a-f]{64}$'))");

        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();

        DB::statement('ALTER TABLE categories DROP FOREIGN KEY categories_active_template_fk');
        DB::statement('ALTER TABLE products DROP FOREIGN KEY products_taxonomy_template_fk');
        DB::statement('ALTER TABLE attribute_options DROP FOREIGN KEY attr_options_approved_by_fk');
        DB::statement('ALTER TABLE attribute_options DROP FOREIGN KEY attr_options_replacement_fk');
        DB::statement('ALTER TABLE attribute_definitions DROP FOREIGN KEY attr_defs_replacement_fk');
        DB::statement('ALTER TABLE products DROP CHECK products_profile_override_check');
        DB::statement('ALTER TABLE attribute_definitions DROP CHECK attr_defs_controlled_type_check');
        DB::statement('ALTER TABLE attribute_options DROP CHECK attr_options_approval_check');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('active_template_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_company_taxonomy_review_index');
            $table->dropColumn([
                'taxonomy_template_id',
                'taxonomy_needs_review',
                'taxonomy_reviewed_at',
                'readiness_profile_override',
                'readiness_profile_version_override',
                'readiness_rule_set_fingerprint_override',
            ]);
        });

        Schema::dropIfExists('category_template_dpp_defaults');
        Schema::dropIfExists('category_template_attributes');
        Schema::dropIfExists('category_templates');

        DB::statement("UPDATE attribute_definitions SET status = 'archived' WHERE status = 'deprecated'");
        DB::statement("UPDATE attribute_options SET status = 'archived' WHERE status = 'deprecated'");
        DB::statement('ALTER TABLE attribute_definitions DROP CHECK attr_defs_status_check');
        DB::statement('ALTER TABLE attribute_options DROP CHECK attr_options_status_check');

        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->dropColumn(['vocabulary_controlled', 'replaced_by_attribute_definition_id']);
        });

        Schema::table('attribute_options', function (Blueprint $table): void {
            $table->dropColumn([
                'replaced_by_attribute_option_id',
                'approval_status',
                'approved_at',
                'approved_by',
            ]);
        });

        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement("ALTER TABLE attribute_options ADD CONSTRAINT attr_options_status_check CHECK (status IN ('active', 'archived'))");
    }

    private function createImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attr_defs_prevent_self_replacement
            BEFORE UPDATE ON attribute_definitions
            FOR EACH ROW
            BEGIN
                IF NEW.replaced_by_attribute_definition_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An attribute cannot replace itself.';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attr_options_prevent_self_replacement
            BEFORE UPDATE ON attribute_options
            FOR EACH ROW
            BEGIN
                IF NEW.replaced_by_attribute_option_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An option cannot replace itself.';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cat_tpl_immutable_update
            BEFORE UPDATE ON category_templates
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' AND (
                    NOT (NEW.company_id <=> OLD.company_id)
                    OR NOT (NEW.category_id <=> OLD.category_id)
                    OR NOT (NEW.version <=> OLD.version)
                    OR NOT (NEW.name <=> OLD.name)
                    OR NOT (NEW.readiness_profile <=> OLD.readiness_profile)
                    OR NOT (NEW.readiness_profile_version <=> OLD.readiness_profile_version)
                    OR NOT (NEW.readiness_rule_set_fingerprint <=> OLD.readiness_rule_set_fingerprint)
                    OR (OLD.status = 'retired' AND NOT (NEW.status <=> OLD.status))
                    OR (OLD.status = 'active' AND NEW.status NOT IN ('active', 'retired'))
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activated category template versions are immutable.';
                END IF;
            END
        SQL);

        foreach (['category_template_attributes', 'category_template_dpp_defaults'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $suffix = strtolower($operation);
                $row = $operation === 'DELETE' ? 'OLD' : 'NEW';
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$table}_draft_{$suffix}
                    BEFORE {$operation} ON {$table}
                    FOR EACH ROW
                    BEGIN
                        IF (SELECT status FROM category_templates WHERE id = {$row}.category_template_id) <> 'draft' THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only draft category templates can be edited.';
                        END IF;
                    END
                SQL);
            }
        }
    }

    private function dropImmutabilityTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS attr_defs_prevent_self_replacement');
        DB::unprepared('DROP TRIGGER IF EXISTS attr_options_prevent_self_replacement');
        DB::unprepared('DROP TRIGGER IF EXISTS cat_tpl_immutable_update');

        foreach (['category_template_attributes', 'category_template_dpp_defaults'] as $table) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_draft_{$operation}");
            }
        }
    }
};
