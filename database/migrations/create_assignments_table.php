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

        Schema::create($prefix.'assignments', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->morphs('subject');
            $table->string('role_id');
            $table->string('scope')->nullable();
            $table->timestamps();

            $table->unique(['subject_id', 'subject_type', 'role_id', 'scope']);

            $table->foreign('role_id')
                ->references('id')
                ->on($prefix.'roles')
                ->cascadeOnDelete();

            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('policy-engine.table_prefix', '').'assignments');
    }
};
