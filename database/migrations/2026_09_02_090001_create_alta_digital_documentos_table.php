<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos capturados durante la liga publica de alta digital, antes de
     * que exista un User/EmployeeDocument. Al aprobar el alta, cada fila se
     * convierte en un EmployeeDocument del colaborador nuevo (ver
     * App\Services\AltaDigital\ConversionColaboradorService).
     */
    public function up(): void
    {
        Schema::create('alta_digital_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alta_digital_id')->constrained('altas_digitales')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->unique(['alta_digital_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alta_digital_documentos');
    }
};
