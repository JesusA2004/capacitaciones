<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timeline de seguimiento de un candidato (equivalente en espanol de lo
     * solicitado como "candidate_followups": el resto del proyecto usa
     * nombres de tabla en espanol, ver docs/ARQUITECTURA.md).
     */
    public function up(): void
    {
        Schema::create('seguimientos_candidato', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->text('nota')->nullable();
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30)->nullable();
            $table->dateTime('fecha');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimientos_candidato');
    }
};
