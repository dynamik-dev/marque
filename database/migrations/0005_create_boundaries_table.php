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

        Schema::create($prefix.'boundaries', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();
            $table->json('max_permissions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('marque.table_prefix', '').'boundaries');
    }
};
