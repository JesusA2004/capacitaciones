<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diseño de página de los documentos maestros (docs/MOTOR_DOCUMENTOS_MAESTROS.md,
 * «Diseño de página y fondos»):
 *  - document_assets: biblioteca de fondos/logos/sellos/marcas de agua.
 *    Nunca se sobrescribe un archivo: reemplazar crea otra versión (mismo
 *    slug, version+1) y se identifica por SHA-256, no por nombre.
 *  - document_layout_presets: diseño base reutilizable (p. ej. «Contrato
 *    indeterminado MR. LANA»).
 *  - document_family_layouts: qué preset usa cada familia + sus overrides.
 *  - document_templates.layout_overrides: override por versión.
 *  - document_templates.layout_qa: páginas esperadas con el diseño
 *    aplicado (por hash del diseño) para la validación de desbordes.
 *  - generated_documents.layout_snapshot: diseño exacto con el que se
 *    generó cada documento (fondo id + hash, ajuste, opacidad…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 20);
            $table->string('nombre');
            $table->string('slug', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('disk', 40);
            $table->string('path');
            $table->string('mime_type', 80);
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->string('sha256', 64)->index();
            $table->string('fit_mode', 20)->default('stretch');
            $table->unsignedTinyInteger('default_opacity')->default(100);
            $table->decimal('safe_area_top_mm', 6, 2)->nullable();
            $table->decimal('safe_area_right_mm', 6, 2)->nullable();
            $table->decimal('safe_area_bottom_mm', 6, 2)->nullable();
            $table->decimal('safe_area_left_mm', 6, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('document_assets')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'version'], 'document_assets_slug_version_unique');
        });

        Schema::create('document_layout_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('nombre');
            $table->json('config');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('document_family_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('familia', 150)->unique();
            $table->foreignId('preset_id')->nullable()->constrained('document_layout_presets')->nullOnDelete();
            $table->json('overrides')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->json('layout_overrides')->nullable();
            $table->json('layout_qa')->nullable();
        });

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->json('layout_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', fn (Blueprint $table) => $table->dropColumn('layout_snapshot'));
        Schema::table('document_templates', fn (Blueprint $table) => $table->dropColumn(['layout_overrides', 'layout_qa']));
        Schema::dropIfExists('document_family_layouts');
        Schema::dropIfExists('document_layout_presets');
        Schema::dropIfExists('document_assets');
    }
};
