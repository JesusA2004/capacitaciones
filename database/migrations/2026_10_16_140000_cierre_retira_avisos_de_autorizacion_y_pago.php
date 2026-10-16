<?php

use App\Models\CierreLaboral;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| 2026-10-16: el cierre laboral ya no tiene autorización de la baja ni del
| finiquito, pago programado ni cita con el excolaborador: RH calcula el
| finiquito y registra su entrega.
|
| - Los avisos SIN LEER que piden una de esas acciones retiradas se marcan
|   como leídos (no se borran: quedan en el historial de notificaciones).
| - Los pendientes de «programar pago» / «citar al excolaborador» que
|   siguieran abiertos se resuelven; el cierre conserva el pendiente único
|   «Entregar finiquito» (CierreLaboralService::sincronizarPendientes).
|
| Solo toca read_at / resuelta_en: nunca montos ni finiquitos entregados.
*/
return new class extends Migration
{
    /** @var list<string> */
    private const ACCIONES_RETIRADAS = ['autorizar_cierre', 'autorizar_rh', 'autorizar_finiquito', 'reenviar_cierre', 'programar_pago', 'citar_excolaborador'];

    public function up(): void
    {
        $ahora = now();

        DB::table('notifications')
            ->whereNull('read_at')
            ->where(function ($q): void {
                foreach (self::ACCIONES_RETIRADAS as $accion) {
                    $q->orWhere('data', 'like', sprintf('%%"accion":"%s"%%', $accion));
                }
            })
            ->update(['read_at' => $ahora]);

        DB::table('tareas_rh')
            ->where('relacionado_type', (new CierreLaboral)->getMorphClass())
            ->whereIn('tipo', ['pago_por_programar', 'cita_finiquito'])
            ->whereNull('resuelta_en')
            ->update(['resuelta_en' => $ahora, 'clave_abierta' => null, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        // Sin reversa: reabrir avisos de acciones que ya no existen dejaría
        // pendientes imposibles de atender.
    }
};
