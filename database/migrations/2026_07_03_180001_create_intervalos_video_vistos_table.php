<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervalos_video_vistos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leccion_id')->constrained('lecciones')->cascadeOnDelete();
            $table->unsignedInteger('inicio_segundo');
            $table->unsignedInteger('fin_segundo');
            $table->timestamps();

            $table->index(['user_id', 'leccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervalos_video_vistos');
    }
};
