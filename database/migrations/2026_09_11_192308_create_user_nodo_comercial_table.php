<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de asignaciones gestor/apoyo/volante por nodo de la matriz
 * comercial (docs/HEADCOUNT_Y_VACANTES.md, sección "Matriz comercial"). A
 * diferencia de `nodos_comerciales.responsable_user_id` (caché del gestor
 * activo actual, mantenido en sincronía por
 * App\Services\MatrizComercial\MatrizComercialService), esta tabla es la
 * fuente de verdad real: permite varias asignaciones activas por nodo
 * (varios apoyos/volantes) y conserva el historial de quién cubrió qué y
 * cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_nodo_comercial', function (Blueprint $table): void {
            $table->id();
            // user_id se conserva por compatibilidad histórica, ya no se
            // escribe desde el código: colaborador_id es la fuente real.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('nodo_comercial_id')->constrained('nodos_comerciales')->cascadeOnDelete();
            $table->string('tipo_asignacion', 20);
            $table->boolean('activo')->default(true);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->timestamps();

            $table->index(['nodo_comercial_id', 'tipo_asignacion', 'activo'], 'user_nodo_comercial_nodo_tipo_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nodo_comercial');
    }
};
