<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Complemento de 2026_11_03_100000_sistemas_sin_documentos_con_importes:
 * aquella retiró a Sistemas nomina.recibos.ver y finiquitos.ver, pero no las
 * acciones y alcances que people:sincronizar-permisos le había heredado de
 * ellos (nomina.recibos.descargar, *.ver_todos…). Con descargar + ver_todos
 * el lote de recibos se podía descargar completo para imprimir
 * (LoteNominaController::descargarImpresion solo revisa descargar y el
 * alcance «Todos»). Sistemas no consulta documentos con importes (Etapa 1
 * §7): se le revocan SOLO esos permisos, nada más cambia. Idempotente y
 * auditada; si el rol no existe, no hace nada.
 */
return new class extends Migration
{
    private const REVOCAR = [
        'nomina.recibos.ver',
        'nomina.recibos.descargar',
        'nomina.recibos.ver_todos',
        'nomina.recibos.ver_region',
        'nomina.recibos.ver_sucursal',
        'nomina.recibos.ver_equipo',
        'finiquitos.ver',
        'finiquitos.descargar',
        'finiquitos.ver_todos',
        'finiquitos.ver_region',
        'finiquitos.ver_sucursal',
        'finiquitos.ver_equipo',
    ];

    public function up(): void
    {
        app(AlineacionPermisosRolService::class)->ajustar(
            [],
            ['sistemas' => self::REVOCAR],
            'Sistemas no consulta ni descarga recibos de nómina ni finiquitos (importes, Etapa 1 §7).',
        );
    }

    /** Sin reversa automática: se devuelven desde «Roles y permisos». */
    public function down(): void {}
};
