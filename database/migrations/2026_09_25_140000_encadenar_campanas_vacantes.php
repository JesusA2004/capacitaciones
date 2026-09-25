<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo de reclutamiento encadenado (docs/RECLUTAMIENTO.md → "Costo por
 * contratación"): un gasto puede ser de una vacante concreta, cada
 * candidato puede registrar de qué campaña vino, y cada gasto se clasifica
 * como costo interno o externo (fórmula ANSI/SHRM 06001.2012). Solo agrega
 * columnas nullable: no altera datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->foreignId('vacante_id')->nullable()->after('puesto_id')->constrained('vacantes')->nullOnDelete();
            $table->string('tipo_costo', 30)->default('publicidad')->after('canal');
            $table->string('nombre', 150)->nullable()->after('id');
        });

        Schema::table('candidatos', function (Blueprint $table): void {
            $table->foreignId('campana_reclutamiento_id')->nullable()->after('vacante_id')->constrained('campanas_reclutamiento')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('campana_reclutamiento_id');
        });

        Schema::table('campanas_reclutamiento', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vacante_id');
            $table->dropColumn(['tipo_costo', 'nombre']);
        });
    }
};
