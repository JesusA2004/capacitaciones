<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sugerencias de datos detectados automaticamente en un documento de
     * expediente (INE, CURP, RFC, NSS, comprobante de domicilio, acta de
     * nacimiento), para que RH las compare contra los datos capturados del
     * colaborador. Nunca aprueba nada por si sola — ver
     * App\Services\Documentos\DocumentExtractionService y docs/DOCUMENT_EXTRACTION.md.
     */
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_document_id')->constrained('employee_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->longText('extracted_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->json('confidence')->nullable();
            $table->json('differences')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Lo normal es una extraccion vigente por documento (se
            // reprocesa sobre la misma fila, ver reprocesar()); el indice
            // tambien acelera "dame la extraccion de este documento".
            $table->unique('employee_document_id');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
