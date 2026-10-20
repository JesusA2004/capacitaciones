<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadatos auditables de cada importación de Excel (hoy los usa el
 * importador de contactos de emergencia): hash real del contenido, qué
 * encabezado del archivo se tomó para cada campo, columnas que se ignoraron
 * y el resumen final de la aplicación (actualizados / sin cambios /
 * omitidos con motivo). `archivo_hash` sigue siendo único; cuando el mismo
 * archivo se vuelve a analizar después de aplicado, el hash del contenido
 * vive aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones_excel', function (Blueprint $table): void {
            $table->json('metadatos')->nullable()->after('detalle');
        });
    }

    public function down(): void
    {
        Schema::table('importaciones_excel', function (Blueprint $table): void {
            $table->dropColumn('metadatos');
        });
    }
};
