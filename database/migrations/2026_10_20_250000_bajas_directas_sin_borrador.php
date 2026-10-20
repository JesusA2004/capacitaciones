<?php

use App\Enums\AccionBitacoraBaja;
use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\BajaBitacora;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * La baja es DIRECTA (2026-10-20, docs/BAJAS_Y_CAUSALES.md): ya no existen
 * el borrador del gerente, «Enviar a RH» ni el rechazo al gerente. Esta
 * migración, idempotente y sin borrar nada:
 *
 *  1. Convierte los estatus del flujo anterior, con una entrada en la
 *     bitácora de cada baja:
 *     - Borrador → «No procede»: nunca se aplicó (sin cierre ni suspensión
 *       de acceso), así que convertirlo en baja aplicada dejaría sin acceso a
 *       alguien que sigue laborando. Sus datos quedan intactos; si procede,
 *       se registra de nuevo.
 *     - Rechazada → «En trámite»: ya estaba aplicada (cierre y acceso
 *       suspendido); el motivo del rechazo se conserva.
 *  2. «Bajas» solo para gerentes, RH y super admin (+ Jurídico y Contraloría
 *     de solo lectura): revoca SOLO los permisos de bajas a jefe_directo,
 *     auditor y sistemas (y a jefe_directo el atajo cierres.solicitar /
 *     solicitudes.bajas.crear). No toca ningún otro permiso de esos roles.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const REVOCAR = [
        'jefe_directo' => ['bajas.ver', 'bajas.solicitar', 'bajas.ver_todos', 'bajas.ver_region', 'bajas.ver_sucursal', 'bajas.ver_equipo', 'cierres.solicitar', 'solicitudes.bajas.crear'],
        'auditor' => ['bajas.ver', 'bajas.solicitar', 'bajas.ver_todos', 'bajas.ver_region', 'bajas.ver_sucursal', 'bajas.ver_equipo'],
        'sistemas' => ['bajas.ver', 'bajas.solicitar', 'bajas.ver_todos', 'bajas.ver_region', 'bajas.ver_sucursal', 'bajas.ver_equipo'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('bajas')) {
            $this->convertirEstatus();
        }

        if (Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            $this->revocarPermisos();
        }
    }

    public function down(): void
    {
        // Sin reversa: el flujo anterior ya no existe y volver a dar los
        // permisos ampliaría el acceso de esos roles.
    }

    private function convertirEstatus(): void
    {
        Baja::query()->whereIn('estatus', [EstatusBaja::Borrador->value, EstatusBaja::Rechazada->value])->orderBy('id')->each(function (Baja $baja): void {
            DB::transaction(function () use ($baja): void {
                $anterior = $baja->estatus;

                if ($anterior === EstatusBaja::Borrador) {
                    $motivo = 'Borrador del flujo anterior que nunca se envió: la baja ahora es directa. No se aplicó nada; si procede, regístrala de nuevo.';
                    $baja->forceFill(['estatus' => EstatusBaja::Cancelada, 'cancelado_en' => now(), 'motivo_cancelacion' => $motivo])->save();
                } else {
                    $motivo = 'Flujo de baja directa: la baja rechazada (ya aplicada) vuelve a «En trámite» para que RH la complete o la marque «No procede».';
                    $baja->forceFill(['estatus' => EstatusBaja::EnRevisionRh])->save();
                }

                BajaBitacora::query()->create([
                    'baja_id' => $baja->id,
                    'user_id' => null,
                    'accion' => AccionBitacoraBaja::ConversionFlujo,
                    'campo' => 'estatus',
                    'valor_anterior' => $anterior->etiqueta(),
                    'valor_nuevo' => $baja->estatus->etiqueta(),
                    'motivo' => $motivo,
                ]);
            });

            Log::info('Bajas directas: estatus convertido.', ['baja_id' => $baja->id, 'folio' => $baja->folio, 'estatus' => $baja->estatus->value]);
        });
    }

    private function revocarPermisos(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::REVOCAR as $nombre => $permisos) {
            $rol = Role::query()->where('name', $nombre)->where('guard_name', 'web')->first();

            if ($rol === null) {
                continue;
            }

            $actuales = $rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all();

            foreach (array_intersect($permisos, $actuales) as $permiso) {
                $rol->revokePermissionTo($permiso);
            }
        }

        $registrar->forgetCachedPermissions();
    }
};
