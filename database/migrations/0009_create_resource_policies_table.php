<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('marque.table_prefix', '');

        Schema::create($prefix.'resource_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->string('effect');
            $table->string('action');
            $table->string('principal_pattern')->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('marque.table_prefix', '').'resource_policies');
    }
};
