<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reaperturas', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 40);
            $table->morphs('reabrible');
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reabierto_por')->constrained('users')->restrictOnDelete();
            $table->text('motivo');
            $table->json('decision_anterior');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reaperturas');
    }
};
