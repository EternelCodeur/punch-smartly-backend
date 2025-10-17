<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('set null');
            }
            // Add a proper FK for enterprise_id if not exists
            if (Schema::hasColumn('users', 'enterprise_id')) {
                // Ensure index exists (already defined in base migration). Try to add FK safely.
                try {
                    $table->foreign('enterprise_id')->references('id')->on('entreprises')->onUpdate('cascade')->onDelete('set null');
                } catch (\Throwable $e) {
                    // ignore if already exists
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'enterprise_id')) {
                try { $table->dropForeign(['enterprise_id']); } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('users', 'tenant_id')) {
                try { $table->dropForeign(['tenant_id']); } catch (\Throwable $e) {}
                $table->dropColumn('tenant_id');
            }
        });
    }
};
