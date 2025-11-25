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
        Schema::create('lifts', function (Blueprint $table) {
            $table->id(); // internal PK
            $table->string('lift_id')->unique(); // human id l1,l2...
            $table->integer('current_floor')->default(0);
            $table->enum('direction', ['UP','DOWN','IDLE'])->default('IDLE');
            $table->string('status')->default('idle');
            $table->json('next_stops')->nullable(); // JSON array
            $table->unsignedInteger('available_at')->default(0); // optional, for non-blocking timing
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lifts');
    }
};
