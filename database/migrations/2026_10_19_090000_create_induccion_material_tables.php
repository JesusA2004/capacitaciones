<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nuevo flujo de la Inducción Mr. Lana (2026): el colaborador ve las hojas
 * del PDF oficial y un material de explicación (audio XOR video) antes de su
 * evaluación. Dos tablas:
 *
 *  - induccion_materiales_explicacion: liga el RecursoMultimedia (audio/video)
 *    con el módulo institucional; uno solo activo a la vez.
 *  - induccion_video_intervalos: tramos realmente vistos del VIDEO por avance,
 *    para el anti-adelanto respaldado por el servidor (espejo dedicado de
 *    intervalos_video_vistos de Capacitación, para no tocar ese módulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('induccion_materiales_explicacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_modulo_id')->constrained('onboarding_modulos')->cascadeOnDelete();
            $table->foreignId('recurso_multimedia_id')->nullable()->constrained('recursos_multimedia')->nullOnDelete();
            $table->string('tipo', 10);
            // true = material vigente (único por módulo); NULL = retirado (se
            // conserva como historial). NULL permite varios retirados sin
            // colisionar con el índice único (MariaDB ignora NULLs en UNIQUE).
            $table->boolean('activo')->nullable();
            $table->foreignId('subido_por')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['onboarding_modulo_id', 'activo'], 'induccion_material_modulo_activo_unique');
        });

        Schema::create('induccion_video_intervalos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('onboarding_avance_id')->constrained('onboarding_avances')->cascadeOnDelete();
            $table->unsignedInteger('inicio_segundo');
            $table->unsignedInteger('fin_segundo');
            $table->timestamps();

            $table->index(['user_id', 'onboarding_avance_id'], 'induccion_video_intervalos_user_avance_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('induccion_video_intervalos');
        Schema::dropIfExists('induccion_materiales_explicacion');
    }
};
