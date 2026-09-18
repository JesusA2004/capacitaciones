<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            // Dueño real del expediente. Nullable: un colaborador sin cuenta
            // de acceso también tiene documentos. `user_id` se conserva solo
            // por compatibilidad histórica y ya no se escribe desde el código.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();

            $table->string('disk')->default('nas');
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime')->nullable();
            $table->string('extension', 10)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('hash', 64)->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->foreignId('previous_version_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->string('status')->default('pendiente');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comments')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('change_requested_at')->nullable();
            $table->foreignId('change_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('change_authorized_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'document_type_id']);
            $table->index(['colaborador_id', 'document_type_id'], 'employee_documents_colaborador_document_type_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
