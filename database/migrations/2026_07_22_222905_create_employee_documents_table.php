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
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
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

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'document_type_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
