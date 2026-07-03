<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de cargas multimedia en progreso o incompletas, para poder
     * mostrar estado/reintentar y para limpiar temporales huerfanos.
     */
    public function up(): void
    {
        Schema::create('cargas_multimedia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurso_multimedia_id')->nullable()->constrained('recursos_multimedia')->nullOnDelete();
            $table->string('nombre_original');
            $table->string('ruta_temporal')->nullable();
            $table->unsignedBigInteger('tamano_total_bytes')->nullable();
            $table->unsignedBigInteger('bytes_recibidos')->default(0);
            $table->string('estado')->default('cargando');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargas_multimedia');
    }
};
