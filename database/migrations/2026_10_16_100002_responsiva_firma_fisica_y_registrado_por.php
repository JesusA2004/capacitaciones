<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsivas: separación de QUIÉN CAPTURA de QUIÉN ENTREGA + firma física
 * sin escaneo (nueva regla de negocio).
 *
 *  - registrado_por: usuario que capturó la responsiva en PEOPLE (el actor).
 *  - entregado_por (columna existente): se reinterpreta como la persona que
 *    realmente ENTREGÓ el equipo (responsable de entrega). Cuando el que
 *    captura coincide con quien entrega (p.ej. un gerente), ambos apuntan al
 *    mismo usuario; cuando RH captura por otro, entregado_por es el
 *    responsable real seleccionado.
 *  - firmada_fisicamente_en / _por: constancia de que la responsiva impresa
 *    se firmó en papel, sin volver a escanearla. firmada_path (legacy) se
 *    conserva: las responsivas antiguas con escaneo siguen legibles.
 *
 * Backfill: las responsivas existentes toman registrado_por = entregado_por
 * (antes de este cambio, ambos eran el mismo actor que creaba la responsiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responsivas', function (Blueprint $table): void {
            if (! Schema::hasColumn('responsivas', 'registrado_por')) {
                $table->foreignId('registrado_por')->nullable()->after('entregado_por')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('responsivas', 'firmada_fisicamente_en')) {
                $table->timestamp('firmada_fisicamente_en')->nullable()->after('firmada_subida_por');
            }

            if (! Schema::hasColumn('responsivas', 'firmada_fisicamente_por')) {
                $table->foreignId('firmada_fisicamente_por')->nullable()->after('firmada_fisicamente_en')->constrained('users')->nullOnDelete();
            }
        });

        // Responsivas previas: quien capturó era el mismo que "entregaba".
        DB::table('responsivas')->whereNull('registrado_por')->update([
            'registrado_por' => DB::raw('entregado_por'),
        ]);
    }

    public function down(): void
    {
        Schema::table('responsivas', function (Blueprint $table): void {
            foreach (['firmada_fisicamente_por', 'registrado_por'] as $columna) {
                if (Schema::hasColumn('responsivas', $columna)) {
                    $table->dropConstrainedForeignId($columna);
                }
            }

            if (Schema::hasColumn('responsivas', 'firmada_fisicamente_en')) {
                $table->dropColumn('firmada_fisicamente_en');
            }
        });
    }
};
