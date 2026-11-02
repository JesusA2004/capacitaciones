<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico individual de rotación (docs/ROTACION.md):
 *
 *  - unidad_externa: bajas de unidades de negocio que NO son sucursales de
 *    People (Itzamarketing, restaurantes Lerom). La baja queda con
 *    sucursal_id nulo y solo cuenta en Rotación para quien tiene alcance
 *    global; no crea sucursales, empresas ni nada que afecte headcount,
 *    vacantes, organigrama, matriz comercial, alcances o el menú.
 *  - antiguedad_dias + rango_antiguedad: antigüedad que reporta la fuente
 *    cuando NO hay fecha de ingreso exacta (p. ej. «2.5 a»). Es aproximada:
 *    la fecha de ingreso sigue vacía y se puede completar después desde
 *    Rotación → Histórico (la fecha real siempre manda).
 *
 * Solo agrega columnas nulas: no cambia ni borra ningún registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bajas_historicas', function (Blueprint $table) {
            $table->string('unidad_externa', 120)->nullable()->after('sucursal_id');
            $table->unsignedInteger('antiguedad_dias')->nullable()->after('fecha_baja');
            $table->string('rango_antiguedad', 20)->nullable()->after('antiguedad_dias');

            $table->index(['unidad_externa', 'fecha_baja'], 'bajas_hist_externa_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bajas_historicas', function (Blueprint $table) {
            $table->dropIndex('bajas_hist_externa_fecha_idx');
            $table->dropColumn(['unidad_externa', 'antiguedad_dias', 'rango_antiguedad']);
        });
    }
};
