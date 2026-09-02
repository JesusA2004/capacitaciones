<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            // solicitud_id: sin FK todavia, la tabla solicitudes_internas se
            // crea en un bloque posterior (docs/SOLICITUDES_INTERNAS.md). La
            // constraint se agrega en una migracion aparte cuando exista.
            $table->unsignedBigInteger('solicitud_id')->nullable();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('generated_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 20)->default('generado');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
