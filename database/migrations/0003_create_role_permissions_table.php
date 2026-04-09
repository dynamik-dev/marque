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

        Schema::create($prefix.'role_permissions', function (Blueprint $table) use ($prefix): void {
            $table->string('role_id');
            $table->string('permission_id');

            $table->primary(['role_id', 'permission_id']);

            $table->foreign('role_id')
                ->references('id')
                ->on($prefix.'roles')
                ->cascadeOnDelete();

            $table->foreign('permission_id')
                ->references('id')
                ->on($prefix.'permissions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('marque.table_prefix', '').'role_permissions');
    }
};
