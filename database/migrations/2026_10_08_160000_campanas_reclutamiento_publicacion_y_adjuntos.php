<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaña de reclutamiento completa: además del gasto (`monto`), lo que se
 * publicó (sueldo, copy, URL, fechas), el presupuesto autorizado, quién la
 * lleva y sus adjuntos (arte/PDF) guardados en el NAS privado.
 * Empresa/sucursal/departamento/puesto siguen saliendo de la vacante real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->decimal('presupuesto', 12, 2)->nullable()->after('monto');
            $table->decimal('sueldo_publicado', 12, 2)->nullable()->after('presupuesto');
            $table->date('fecha_inicio')->nullable()->after('sueldo_publicado');
            $table->date('fecha_fin')->nullable()->after('fecha_inicio');
            $table->text('copy')->nullable()->after('fecha_fin');
            $table->string('url', 500)->nullable()->after('copy');
            $table->foreignId('responsable_id')->nullable()->after('url')->constrained('users')->nullOnDelete();
        });

        Schema::create('campana_reclutamiento_adjuntos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campana_reclutamiento_id')->constrained('campanas_reclutamiento')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('nombre_original');
            $table->string('mime', 120);
            $table->unsignedBigInteger('tamano');
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campana_reclutamiento_adjuntos');

        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsable_id');
            $table->dropColumn(['presupuesto', 'sueldo_publicado', 'fecha_inicio', 'fecha_fin', 'copy', 'url']);
        });
    }
};
