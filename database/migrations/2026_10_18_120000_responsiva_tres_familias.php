<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tres familias oficiales de responsiva (docs/responsiva/*.docx): bien mueble,
 * equipo electrónico y vehículo. Cada familia tiene campos legales propios que
 * se guardan en la columna `datos` (JSON tipado por familia, congelado también
 * en `snapshot` al generar). Es documental, no inventario: sin stock ni serial
 * único. Ver App\Services\Responsivas\ResponsivaService.
 *
 * La columna `datos` se agrega como nullable para no romper responsivas
 * previas basadas en la tabla `responsiva_activos` (que se conserva como
 * compatibilidad legacy de solo lectura); las responsivas nuevas siempre la
 * llenan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responsivas', function (Blueprint $table): void {
            if (! Schema::hasColumn('responsivas', 'datos')) {
                $table->json('datos')->nullable()->after('snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('responsivas', function (Blueprint $table): void {
            if (Schema::hasColumn('responsivas', 'datos')) {
                $table->dropColumn('datos');
            }
        });
    }
};
