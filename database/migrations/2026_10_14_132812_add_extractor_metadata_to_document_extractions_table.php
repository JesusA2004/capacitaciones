<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotencia del comando `expedientes:extraer-existentes`: una fila ya
     * procesada con el mismo hash del archivo (`EmployeeDocument::hash`) y la
     * misma versión del extractor no se vuelve a leer del NAS en una
     * corrida posterior. `ocr_used` distingue una sugerencia leída del texto
     * nativo del PDF de una leída por Tesseract (confianza distinta).
     */
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable()->after('error_message');
            $table->unsignedTinyInteger('extractor_version')->nullable()->after('source_hash');
            $table->boolean('ocr_used')->default(false)->after('extractor_version');
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropColumn(['source_hash', 'extractor_version', 'ocr_used']);
        });
    }
};
