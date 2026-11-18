<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificación Mr. Lana por formato oficial (RH-CC-R-001): una fila por
 * colaborador con el rastro del formato (descargado → firmado a pluma y
 * subido) y el certificado emitido. El escaneo firmado vive en el
 * expediente (employee_documents); el certificado, en la carpeta del
 * expediente. Aditiva: no toca ninguna tabla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certificaciones_mr_lana')) {
            return;
        }

        Schema::create('certificaciones_mr_lana', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->unique()->constrained('colaboradores')->cascadeOnDelete();
            $table->string('formato_clave', 30)->default('RH-CC-R-001');
            $table->timestamp('formato_descargado_en')->nullable();
            $table->foreignId('formato_descargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('descargas')->default(0);
            $table->foreignId('firmado_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->decimal('promedio', 5, 2)->nullable();
            $table->string('folio', 20)->nullable()->unique();
            $table->timestamp('certificado_en')->nullable();
            $table->foreignId('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('certificado_disk', 40)->nullable();
            $table->string('certificado_ruta')->nullable();
            $table->timestamps();

            $table->index('certificado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificaciones_mr_lana');
    }
};
