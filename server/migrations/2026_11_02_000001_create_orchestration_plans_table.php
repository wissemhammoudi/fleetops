<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orchestration_plans', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('company_uuid')->index();
            $table->longText('snapshot');
            $table->timestamp('expires_at');
            $table->uuid('request_id')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->longText('response')->nullable();
            $table->timestamps();
            $table->unique(['company_uuid', 'request_id'], 'orchestration_company_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_plans');
    }
};
