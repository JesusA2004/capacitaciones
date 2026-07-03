<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada reenvio (por rechazo del instructor) crea una fila nueva con
     * version incrementada, en vez de una tabla de historial aparte: la
     * propia tabla de entregas es su historial.
     */
    public function up(): void
    {
        Schema::create('entregas_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('contenido_texto')->nullable();
            $table->string('url')->nullable();
            $table->foreignId('recurso_multimedia_id')->nullable()->constrained('recursos_multimedia')->nullOnDelete();
            $table->string('estado')->default('entregada');
            $table->unsignedTinyInteger('calificacion')->nullable();
            $table->text('retroalimentacion')->nullable();
            $table->timestamp('entregado_en');
            $table->timestamp('calificado_en')->nullable();
            $table->foreignId('calificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['actividad_id', 'user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_actividad');
    }
};
