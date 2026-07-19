<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('depth')->default(0);
            $table->string('name');
            $table->string('slug');
            $table->string('slug_normalized');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'id'], 'categories_company_id_unique');
            $table->unique(['company_id', 'slug_normalized'], 'categories_company_slug_unique');
            $table->index(['company_id', 'parent_id'], 'categories_company_parent_index');
            $table->index(['company_id', 'status'], 'categories_company_status_index');
            $table->index(['company_id', 'sort_order'], 'categories_company_sort_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_company_parent_foreign FOREIGN KEY (company_id, parent_id) REFERENCES categories(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_depth_check CHECK (depth >= 0 AND depth <= 5)');
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_sort_order_check CHECK (sort_order >= 0)');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_prevent_self_parent_insert
            BEFORE INSERT ON categories
            FOR EACH ROW
            BEGIN
                IF NEW.parent_id IS NOT NULL AND NEW.id IS NOT NULL AND NEW.id <> 0 AND NEW.parent_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A category cannot be its own parent.';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_prevent_self_parent_update
            BEFORE UPDATE ON categories
            FOR EACH ROW
            BEGIN
                IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A category cannot be its own parent.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS categories_prevent_self_parent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS categories_prevent_self_parent_insert');

        Schema::dropIfExists('categories');
    }
};
