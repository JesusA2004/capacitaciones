<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento (Fase 2): correspondencia AUDITABLE entre PEOPLE y Reportes.
 *
 * No existe una llave confiable compartida: el número de empleado de PEOPLE
 * no es la clave NOI de Reportes (y esa clave tampoco es única allá), y las
 * sucursales de PEOPLE no son los `branches` (oficinas/rutas) de Reportes.
 * Por eso cada vínculo lo confirma una persona y NUNCA se borra: cambiarlo
 * desactiva la fila anterior (desactivado_en/por + motivo) y crea otra.
 * La unicidad «un branch / un employee activo a la vez» la garantiza
 * App\Services\Seguimiento\VinculoReportesService dentro de una transacción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculos_reportes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales');
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores');
            // Id del branch o del employee en Reportes (no es FK: otra base).
            $table->unsignedBigInteger('reportes_id');
            // Foto de cómo se llamaba en Reportes al vincularlo (auditoría).
            $table->string('reportes_clave', 100)->nullable();
            $table->string('reportes_nombre', 200);
            $table->string('metodo', 30);
            $table->boolean('activo')->default(true);
            $table->foreignId('vinculado_por')->nullable()->constrained('users');
            $table->foreignId('desactivado_por')->nullable()->constrained('users');
            $table->timestamp('desactivado_en')->nullable();
            $table->string('motivo_desactivacion', 255)->nullable();
            $table->timestamps();

            $table->index(['tipo', 'reportes_id', 'activo'], 'vinculos_reportes_tipo_reportes_activo_idx');
            $table->index(['tipo', 'sucursal_id', 'activo'], 'vinculos_reportes_tipo_sucursal_activo_idx');
            $table->index(['tipo', 'colaborador_id', 'activo'], 'vinculos_reportes_tipo_colaborador_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos_reportes');
    }
};
