<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlite';

    public function up(): void
    {
        Schema::connection('sqlite')->create('scheduling_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('mode');
            $table->string('algorithm')->nullable();
            $table->unsignedBigInteger('quantum')->nullable();
            $table->json('input_processes');
            $table->json('results');
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('sqlite')->dropIfExists('scheduling_runs');
    }
};
