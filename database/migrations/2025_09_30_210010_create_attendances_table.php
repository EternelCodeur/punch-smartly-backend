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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employe_id')->index();
            $table->date('date');
            $table->dateTime('check_in_at')->nullable();
            $table->text('check_in_signature')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->text('check_out_signature')->nullable();
            $table->timestamps();

            $table->unique(['employe_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
