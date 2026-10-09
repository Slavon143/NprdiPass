<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_replies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('feedback_id')->constrained('platform_incidents')->cascadeOnDelete();
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20);
            $table->text('message');
            $table->timestamps();
            $table->index(['feedback_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_replies');
    }
};
