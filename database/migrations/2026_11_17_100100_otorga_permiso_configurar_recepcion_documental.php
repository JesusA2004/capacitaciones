<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;

/**
 * Recepción documental: permiso nuevo `recepcion_documental.configurar`
 * (catálogo de lo que se recibe). super_admin lo tiene por el Gate::before
 * global; aquí se otorga a RH Admin en bases existentes, igual que en
 * RolesYPermisosSeeder. Aditiva e idempotente; queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AlineacionPermisosRolService::class)->ajustar(
            ['super_admin' => ['recepcion_documental.configurar'], 'rh_admin' => ['recepcion_documental.configurar']],
            [],
            'Recepción documental: catálogo configurable de lo que se recibe.',
        );
    }

    public function down(): void {}
};
