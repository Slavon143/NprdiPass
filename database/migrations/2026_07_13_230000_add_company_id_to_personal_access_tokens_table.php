<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->index(['company_id', 'tokenable_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'tokenable_id']);
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropColumn('company_id');
        });
    }
};
