<?php

use App\Models\User;
use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Contratos: solo RH los genera (decisión del negocio, 2026-11-06).
 *
 * Generar un contrato (individual, «Generar todos», paquete de contratación o
 * regenerar) ahora exige SOLO contratos.generar; antes también bastaba
 * documentos_laborales.generar, así que quitar «Generar contratos» en
 * «Roles y permisos» no lo quitaba. Esta migración:
 *
 *  1) Quita a Gerente, Gerente de Sucursal, Gerente Regional y Dirección
 *     Comercial cualquier permiso de generación de contratos que tengan de
 *     más (contratos.generar y el general documentos_laborales.generar; ninguno
 *     está en su base del seeder).
 *  2) Conserva lo que ya hacía cualquier otro rol (RH, o uno personalizado)
 *     que generaba contratos con el general: si tiene
 *     documentos_laborales.generar y le falta contratos.generar /
 *     ver_documento / descargar, se los otorga (mismo comportamiento que
 *     antes). Igual para los usuarios con el permiso directo.
 *
 * Idempotente y quirúrgica (AlineacionPermisosRolService::ajustar): no toca
 * nada más de cada rol; un rol inexistente se omite; queda en la bitácora.
 */
return new class extends Migration
{
    private const COMERCIALES = ['gerente', 'gerente_sucursal', 'gerente_regional', 'director_comercial'];

    private const GENERAR = ['contratos.generar', 'documentos_laborales.generar'];

    private const CONTRATO = ['contratos.generar', 'contratos.ver_documento', 'contratos.descargar'];

    private const GENERAL = 'documentos_laborales.generar';

    public function up(): void
    {
        $existe = Permission::query()->where('name', self::GENERAL)->where('guard_name', 'web')->exists();

        $otorgar = [];

        if ($existe) {
            $roles = Role::query()->where('guard_name', 'web')
                ->whereNotIn('name', self::COMERCIALES)
                ->whereHas('permissions', fn ($q) => $q->where('name', self::GENERAL))
                ->pluck('name');

            foreach ($roles as $nombre) {
                $otorgar[(string) $nombre] = self::CONTRATO;
            }
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            array_fill_keys(self::COMERCIALES, self::GENERAR),
            'Contratos: solo RH los genera; la generación exige contratos.generar (2026-11-06).',
        );

        if ($existe) {
            foreach (self::CONTRATO as $permiso) {
                Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            User::query()
                ->whereHas('permissions', fn ($q) => $q->where('name', self::GENERAL))
                ->each(function (User $usuario): void {
                    $faltan = array_values(array_diff(self::CONTRATO, $usuario->getDirectPermissions()->pluck('name')->all()));

                    if ($faltan !== []) {
                        $usuario->givePermissionTo($faltan);
                    }
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Sin reversa automática: los permisos se ajustan desde «Roles y permisos». */
    public function down(): void {}
};
