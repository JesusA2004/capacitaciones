<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Certificación Mr. Lana por formato oficial (RH-CC-R-001): permiso propio
 * `certificacion.gestionar` (descargar el formato con el encabezado lleno,
 * subir el firmado y emitir el certificado). Se otorga a RH y a la
 * administración de capacitación, igual que en RolesYPermisosSeeder.
 * Aditiva e idempotente; no revoca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AlineacionPermisosRolService::class)->ajustar(
            [
                'super_admin' => ['certificacion.gestionar'],
                'rh_admin' => ['certificacion.gestionar'],
                'administrador_capacitacion' => ['certificacion.gestionar'],
            ],
            [],
            'Certificación Mr. Lana: descargar el formato RH-CC-R-001, subir el firmado y emitir el certificado.',
        );
    }

    public function down(): void {}
};
