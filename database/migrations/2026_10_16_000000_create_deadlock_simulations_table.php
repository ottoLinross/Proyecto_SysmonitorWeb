<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlite';

    public function up(): void
    {
        Schema::connection('sqlite')->create('deadlock_simulations', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('n');
            $table->unsignedSmallInteger('m');
            $table->json('allocation');
            $table->json('max');
            $table->json('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('sqlite')->dropIfExists('deadlock_simulations');
    }
};
