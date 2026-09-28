<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de variables manuales que RH declara para los placeholders
     * {{...}} de una plantilla DOCX que no corresponden a ningún dato real
     * del colaborador/candidato (App\Services\Plantillas\PlaceholderResolver).
     * Cada entrada: {clave, etiqueta, descripcion, tipo, requerido, valor_por_defecto, opciones}.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->json('variables_manuales')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('variables_manuales');
        });
    }
};
