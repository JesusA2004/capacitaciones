<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartas compromiso de documentos pendientes: quien se registra en la app y
 * marca «No tengo este documento» se compromete a entregarlo en un plazo
 * (config('expedientes.carta_compromiso.dias_plazo')). `documentos` congela
 * la lista comprometida al generarla; el PDF vive en el expediente (NAS) y
 * se registra como GeneratedDocument. Ver
 * App\Services\Expedientes\CartaCompromisoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartas_compromiso', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('folio', 20)->nullable()->unique('cartas_compromiso_folio_unique');
            $table->string('estado', 20)->default('vigente');
            $table->json('documentos');
            $table->date('fecha_limite');
            $table->timestamp('generada_en');
            $table->timestamp('cumplida_en')->nullable();
            $table->timestamp('vencida_en')->nullable();
            $table->timestamp('sustituida_en')->nullable();
            $table->foreignId('generada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'cartas_compromiso_colab_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartas_compromiso');
    }
};
