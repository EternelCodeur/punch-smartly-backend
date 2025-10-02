<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->date('attendance_date')->nullable()->after('position');
            $table->boolean('arrival_signed')->default(false)->after('attendance_date');
            $table->boolean('departure_signed')->default(false)->after('arrival_signed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->dropColumn(['attendance_date', 'arrival_signed', 'departure_signed']);
        });
    }
};
