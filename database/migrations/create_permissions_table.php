<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('policy-engine.table_prefix', '');

        Schema::create($prefix.'permissions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('policy-engine.table_prefix', '').'permissions');
    }
};
