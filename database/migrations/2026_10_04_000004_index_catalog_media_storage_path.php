<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->index('storage_path', 'media_storage_path_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropIndex('media_storage_path_index');
        });
    }
};
