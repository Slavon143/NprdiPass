<?php

use App\Enums\CompanyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('organization_number')->nullable();
            $table->char('country_code', 2)->default('SE');
            $table->string('billing_email')->nullable();
            $table->string('status')->default(CompanyStatus::Active->value);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_code', 'organization_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
