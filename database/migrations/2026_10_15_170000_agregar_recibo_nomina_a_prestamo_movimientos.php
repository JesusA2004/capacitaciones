<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada movimiento de préstamo con el recibo de nómina que lo originó.
 *
 * Antes, ReciboNominaService registraba el descuento del préstamo al CREAR
 * el recibo (aunque quedara en borrador): cancelar o recalcular el borrador
 * dejaba el saldo descontado. Ahora el descuento se registra SOLO al emitir,
 * y el índice único (recibo, préstamo, tipo) garantiza que un reintento de
 * emisión nunca descuente dos veces ni que una cancelación revierta dos veces
 * (tipo `nomina` vs. `reverso_nomina`).
 *
 * Además enlaza los movimientos `nomina` ya existentes con su recibo cuando
 * la coincidencia es EXACTA y ÚNICA (mismo préstamo, mismo monto, mismo
 * colaborador, línea de préstamo en el snapshot del recibo y creados con
 * menos de 5 s de diferencia): así un borrador viejo que ya había descontado
 * no vuelve a descontar al emitirse. Lo que no cuadra se deja sin enlazar y
 * se reporta en el log (auditable), nunca se adivina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamo_movimientos', function (Blueprint $table): void {
            $table->foreignId('recibo_nomina_id')->nullable()->after('tipo')->constrained('recibos_nomina')->nullOnDelete();
            $table->unique(['recibo_nomina_id', 'prestamo_id', 'tipo'], 'prestamo_mov_recibo_prestamo_tipo_unique');
        });

        $this->enlazarMovimientosExistentes();
    }

    public function down(): void
    {
        Schema::table('prestamo_movimientos', function (Blueprint $table): void {
            $table->dropForeign(['recibo_nomina_id']);
            $table->dropUnique('prestamo_mov_recibo_prestamo_tipo_unique');
            $table->dropColumn('recibo_nomina_id');
        });
    }

    private function enlazarMovimientosExistentes(): void
    {
        $movimientos = DB::table('prestamo_movimientos')
            ->join('prestamos', 'prestamos.id', '=', 'prestamo_movimientos.prestamo_id')
            ->where('prestamo_movimientos.tipo', 'nomina')
            ->whereNull('prestamo_movimientos.recibo_nomina_id')
            ->orderBy('prestamo_movimientos.id')
            ->get(['prestamo_movimientos.id', 'prestamo_movimientos.prestamo_id', 'prestamo_movimientos.monto', 'prestamo_movimientos.created_at', 'prestamos.colaborador_id']);

        $sinEnlace = [];

        foreach ($movimientos as $movimiento) {
            if ($movimiento->created_at === null) {
                $sinEnlace[] = (int) $movimiento->id;

                continue;
            }

            $creado = Carbon::parse((string) $movimiento->created_at);
            $candidatos = DB::table('recibos_nomina')
                ->where('colaborador_id', $movimiento->colaborador_id)
                ->whereBetween('created_at', [$creado->copy()->subSeconds(5), $creado->copy()->addSeconds(5)])
                ->get(['id', 'deducciones'])
                ->filter(function (object $recibo) use ($movimiento): bool {
                    $deducciones = json_decode((string) $recibo->deducciones, true);

                    if (! is_array($deducciones)) {
                        return false;
                    }

                    foreach ($deducciones as $deduccion) {
                        if (is_array($deduccion)
                            && (int) ($deduccion['prestamo_id'] ?? 0) === (int) $movimiento->prestamo_id
                            && round((float) ($deduccion['monto'] ?? 0), 2) === round((float) $movimiento->monto, 2)) {
                            return true;
                        }
                    }

                    return false;
                });

            $reciboId = $candidatos->count() === 1 ? (int) $candidatos->first()->id : null;
            $yaEnlazado = $reciboId !== null && DB::table('prestamo_movimientos')
                ->where('recibo_nomina_id', $reciboId)
                ->where('prestamo_id', $movimiento->prestamo_id)
                ->where('tipo', 'nomina')
                ->exists();

            if ($reciboId === null || $yaEnlazado) {
                $sinEnlace[] = (int) $movimiento->id;

                continue;
            }

            DB::table('prestamo_movimientos')->where('id', $movimiento->id)->update(['recibo_nomina_id' => $reciboId]);
        }

        if ($sinEnlace !== []) {
            Log::warning('Migración prestamo_movimientos: movimientos de nómina sin recibo identificable (revisar manualmente).', ['movimiento_ids' => $sinEnlace]);
        }
    }
};
