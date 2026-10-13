<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Motor documental NO-CODE (docs/MOTOR_DOCUMENTOS_MAESTROS.md): el mapa de
| campos de cada VERSIÓN de un documento maestro vive en base de datos, no
| en config/documentos_maestros.php (que queda como legado: migración
| inicial y valores por defecto).
|
|  - document_template_fields: una fila por zona del documento (dato,
|    firma o zona ignorada). DOCX = ancla serializada en `selector` (mismo
|    formato que las reglas del preparador); PDF = caja en mm sobre la
|    página. El mapa compilado sigue en document_templates.mapping.
|  - document_templates.configuracion: opciones de la versión que antes
|    solo estaban en config (representante, páginas, copias, ejemplos…).
|  - document_templates.qa_entorno: huella del servidor donde se validó el
|    diseño (SO, Chrome, Word/LibreOffice, fuentes) para pedir revalidar
|    si cambia.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->string('zona', 20)->default('dato');
            $table->string('estado', 20)->default('sugerido');
            $table->string('source_key', 80)->nullable();
            $table->json('expression')->nullable();
            $table->string('transform', 20)->nullable();
            $table->string('label', 191)->nullable();
            $table->string('type', 20)->default('texto');
            $table->unsignedSmallInteger('page')->nullable();
            $table->decimal('x', 8, 2)->nullable();
            $table->decimal('y', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->string('font_family', 80)->nullable();
            $table->decimal('font_size', 5, 2)->nullable();
            $table->decimal('font_size_min', 5, 2)->nullable();
            $table->string('font_weight', 10)->nullable();
            $table->string('text_align', 1)->nullable();
            $table->decimal('line_height', 5, 2)->nullable();
            $table->boolean('multiline')->default(false);
            $table->boolean('required')->default(true);
            $table->string('background_color', 9)->nullable();
            $table->json('selector')->nullable();
            $table->text('placeholder_original')->nullable();
            $table->string('detection_method', 30)->nullable();
            $table->decimal('detection_confidence', 4, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_template_id', 'field_key'], 'dtf_template_field_unique');
            $table->index(['document_template_id', 'sort_order'], 'dtf_template_orden_index');
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('configuracion')->nullable()->after('mapping');
            $table->json('qa_entorno')->nullable()->after('diagnostico_fuentes');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['configuracion', 'qa_entorno']);
        });

        Schema::dropIfExists('document_template_fields');
    }
};
