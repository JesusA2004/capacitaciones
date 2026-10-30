<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propuesta de mejoras Etapa 1 (Pantalla 4): se retira la compuerta OBLIGATORIA
 * de Jurídico en el flujo de bajas. Jurídico ya no autoriza una baja (conserva
 * acceso de consulta). Las bajas que estuvieran «En revisión Jurídico»
 * (en_revision_juridico) pasan a «En trámite» (en_revision_rh) de forma
 * auditable, con un registro en baja_bitacora por cada una.
 *
 * Aditiva y segura sobre datos existentes; no usa migrate:fresh. Idempotente:
 * si no hay filas en ese estatus, no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bajas')) {
            return;
        }

        $bajas = DB::table('bajas')->where('estatus', 'en_revision_juridico')->get(['id']);

        if ($bajas->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($bajas): void {
            $ahora = now();

            foreach ($bajas as $baja) {
                DB::table('bajas')->where('id', $baja->id)->update([
                    'estatus' => 'en_revision_rh',
                    'updated_at' => $ahora,
                ]);

                // Bitácora auditable (misma tabla que BajaRegistroService::bitacora).
                if (Schema::hasTable('baja_bitacora')) {
                    DB::table('baja_bitacora')->insert([
                        'baja_id' => $baja->id,
                        'user_id' => null, // Sistema (migración).
                        'accion' => 'conversion_flujo',
                        'campo' => 'estatus',
                        'valor_anterior' => 'En revisión Jurídico',
                        'valor_nuevo' => 'En trámite',
                        'motivo' => 'Retiro de la compuerta obligatoria de Jurídico (propuesta de mejoras Etapa 1). Jurídico conserva acceso de consulta.',
                        // baja_bitacora solo tiene created_at (sin updated_at).
                        'created_at' => $ahora,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // No se revierte: no es posible reconstruir con certeza qué bajas
        // estaban «En revisión Jurídico» antes de la conversión (el estatus
        // anterior se registró en la bitácora, pero restaurarlo reintroduciría
        // una compuerta que el negocio decidió retirar). Operación de un solo
        // sentido, auditada en baja_bitacora.
    }
};
