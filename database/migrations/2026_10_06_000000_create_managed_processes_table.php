<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pid');
            $table->string('process_type');
            $table->string('command_label');
            $table->unsignedInteger('owner_uid');
            $table->unsignedBigInteger('start_time_ticks');
            $table->string('status');
            $table->timestamp('launched_at');
            $table->timestamps();
            $table->unique(['pid', 'start_time_ticks']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_processes');
    }
};
