<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formatos oficiales de MR. LANA (PDF/DOCX fijos, importados desde
     * claude/formatos/originales via `php artisan formatos:importar-originales`
     * — ver docs/FORMATOS_OFICIALES.md). A diferencia de document_templates,
     * el contenido del documento NUNCA se edita desde el sistema: solo se
     * pinta encima ("overlay") los datos del colaborador en las coordenadas
     * de overlay_config.
     */
    public function up(): void
    {
        Schema::create('official_formats', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('tipo', 30);
            $table->string('source_disk');
            $table->string('source_path');
            $table->string('original_filename');
            $table->string('file_type', 10)->default('pdf');
            $table->boolean('is_active')->default(true);
            $table->json('overlay_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_formats');
    }
};
