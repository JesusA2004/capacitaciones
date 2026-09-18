<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una fila por cada PDF generado a partir de un official_format (overlay
     * de datos sobre el documento oficial). data_snapshot guarda los valores
     * realmente usados (incluyendo correcciones manuales de RH para ese
     * documento) para auditoria, sin depender de que el colaborador no
     * cambie sus datos despues.
     */
    public function up(): void
    {
        Schema::create('official_format_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('official_format_id')->constrained('official_formats')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('generated_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('generated_disk');
            $table->string('generated_path');
            $table->string('generated_name');
            $table->json('data_snapshot')->nullable();
            // Vincula la generación a la solicitud interna que la disparó
            // (ver App\Services\Solicitudes\SolicitudFormatoOficialService) y
            // el ciclo de firma: generado -> firmado, con el PDF firmado
            // subido por RH (ver docs/FORMATOS_OFICIALES.md).
            $table->foreignId('solicitud_interna_id')->nullable()->constrained('solicitudes_internas')->nullOnDelete();
            $table->string('status')->default('generado');
            $table->string('signed_disk')->nullable();
            $table->string('signed_path')->nullable();
            $table->string('signed_name')->nullable();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_format_generations');
    }
};
