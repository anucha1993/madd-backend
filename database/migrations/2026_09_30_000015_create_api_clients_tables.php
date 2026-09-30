<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Rate API (POST /api/public/v1/rates) for external sites such as the company WordPress
 * site — see App\Http\Middleware\AuthenticateApiClient and App\Services\PublicRateService.
 * - api_clients: one API key per consuming site. Only a SHA-256 hash of the key is stored
 *   (the plain key is shown once on create/regenerate); key_prefix finds the row quickly.
 *   Quotes use the carrier accounts/services of `branch_id` and the same markup as the counter.
 * - api_request_logs: one row per call (usage stats / abuse checks); pruned after 180 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('key_prefix', 16)->nullable()->unique();
            $table->string('key_hash', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_city', 100)->default('Bangkok');
            $table->string('origin_postcode', 10)->default('10110');
            $table->json('carriers')->nullable();
            $table->unsignedTinyInteger('max_results')->default(6);
            $table->unsignedSmallInteger('price_rounding')->default(0);
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->unsignedSmallInteger('end_user_limit_per_minute')->default(10);
            $table->json('allowed_ips')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('end_user_ip', 45)->nullable();
            $table->string('destination_country', 2)->nullable();
            $table->decimal('total_weight', 10, 2)->nullable();
            $table->unsignedSmallInteger('pieces')->nullable();
            $table->unsignedSmallInteger('result_count')->default(0);
            $table->decimal('lowest_price', 12, 2)->nullable();
            $table->boolean('cached')->default(false);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['api_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
    }
};
