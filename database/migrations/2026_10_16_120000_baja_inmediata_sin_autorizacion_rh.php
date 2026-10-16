<?php

use App\Models\CierreLaboral;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| 2026-10-16: la baja iniciada por el jefe directo o por RH se APLICA DE
| INMEDIATO (sin preautorización ni «Autorización final de RH»).
|
| Los cierres que seguían abiertos en «solicitado» o «pendiente_rh» pasan a
| «iniciado» (baja aplicada · documentos pendientes) SIN perder historial.
| Cualquier aprobación de cierre que siguiera pendiente queda «omitida» con
| el motivo y los pendientes de preautorizar/autorizar baja se resuelven.
| Las aprobaciones ya decididas y los cierres rechazados/cancelados se
| conservan tal cual.
*/
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        $nota = 'Baja aplicada de inmediato: se retiró la autorización de RH del cierre laboral (2026-10-16).';
        $tipoCierre = (new CierreLaboral)->getMorphClass();

        DB::transaction(function () use ($ahora, $nota, $tipoCierre): void {
            $ids = DB::table('cierres_laborales')
                ->whereIn('estado', ['solicitado', 'pendiente_rh'])
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('cierres_laborales')
                    ->whereIn('id', $ids)
                    ->whereNull('autorizado_rh_en')
                    ->update(['autorizado_rh_en' => $ahora, 'autorizado_rh_por' => DB::raw('iniciado_por')]);

                DB::table('cierres_laborales')
                    ->whereIn('id', $ids)
                    ->update(['estado' => 'iniciado', 'updated_at' => $ahora]);
            }

            DB::table('aprobaciones')
                ->where('aprobable_type', $tipoCierre)
                ->where('proceso', 'cierre_laboral')
                ->where('estado', 'pendiente')
                ->update(['estado' => 'omitido', 'comentario' => $nota, 'decidido_en' => $ahora, 'updated_at' => $ahora]);

            DB::table('tareas_rh')
                ->where('relacionado_type', $tipoCierre)
                ->whereIn('tipo', ['cierre_preautorizacion', 'cierre_autorizacion_rh'])
                ->whereNull('resuelta_en')
                ->update(['resuelta_en' => $ahora, 'clave_abierta' => null, 'updated_at' => $ahora]);
        });
    }

    public function down(): void
    {
        // Sin reversa: devolver cierres a una autorización que ya no existe
        // dejaría trámites sin acción posible.
    }
};
