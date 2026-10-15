<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acuse del descriptivo de puesto: al terminar y aprobar su evaluación de
 * inducción, RH le «Envía el descriptivo de puesto» al colaborador; él lo
 * lee en la app, marca «OK, enterado» y deja su comentario. Cada fila
 * apunta a la VERSIÓN exacta del descriptivo oficial que se envió (las
 * versiones de descriptivos_puesto nunca se editan). Ver
 * App\Services\Onboarding\AcuseDescriptivoPuestoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acuses_descriptivo_puesto', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->foreignId('descriptivo_puesto_id')->constrained('descriptivos_puesto');
            // Copia de la versión y del puesto al enviarlo (auditable aunque
            // después cambie el puesto del colaborador).
            $table->unsignedInteger('descriptivo_version');
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('evaluacion_induccion_id')->nullable()->constrained('evaluaciones_induccion')->nullOnDelete();
            $table->foreignId('enviado_por')->constrained('users');
            $table->timestamp('enviado_en');
            $table->timestamp('leido_en')->nullable();
            $table->boolean('enterado')->default(false);
            $table->text('comentario')->nullable();
            $table->timestamp('acusado_en')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'acusado_en'], 'acuses_descriptivo_colab_acusado_idx');
            $table->index(['colaborador_id', 'descriptivo_puesto_id'], 'acuses_descriptivo_colab_desc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuses_descriptivo_puesto');
    }
};
