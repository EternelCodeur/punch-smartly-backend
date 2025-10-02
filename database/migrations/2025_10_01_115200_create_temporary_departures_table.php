<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_departures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employe_id')->index();
            $table->date('date');
            $table->time('departure_time');
            $table->text('reason')->nullable();
            $table->time('return_time')->nullable();
            $table->text('return_signature')->nullable();
            $table->string('return_signature_file_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_departures');
    }
};
