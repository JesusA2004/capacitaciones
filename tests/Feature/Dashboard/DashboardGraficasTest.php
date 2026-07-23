<?php

use App\Enums\EstadoDocumento;
use App\Models\Departamento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Cubre el dashboard RH (App\Services\Reportes\MetricasRhDashboardService),
 * que reemplazo al dashboard de cumplimiento de capacitacion (ver
 * docs/CAPACITACION_PROXIMAMENTE.md). Se enfoca en la forma de `cards`/
 * `graficas` por rol y en que los desgloses respeten el aislamiento por
 * sucursal, igual que el resto del sistema.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('el dashboard global incluye el desglose completo de cards y graficas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props['cards'])->toHaveKeys([
            'colaboradores_activos', 'altas_en_proceso', 'bajas_del_mes',
            'expedientes_completos', 'expedientes_incompletos', 'documentos_pendientes',
            'solicitudes_pendientes', 'vacaciones_pendientes',
        ]);
        expect($props['graficas'])->toHaveKeys([
            'colaboradoresPorEmpresa', 'colaboradoresPorSucursal', 'colaboradoresPorDepartamento',
            'colaboradoresPorPuesto', 'expedientesEstado', 'documentosPorEstado',
        ]);
        expect($props)->toHaveKeys(['proximosAniversarios', 'documentosPendientesRevision', 'alertas']);
    });
});

test('el dashboard de colaborador solo incluye su subconjunto personal, sin graficas organizacionales', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->actingAs($colaborador)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props)->toHaveKeys(['miExpediente', 'misDocumentosPendientes', 'misVacaciones', 'misSolicitudes', 'avisosPendientes']);
        expect($props)->not->toHaveKey('graficas');
        expect($props)->not->toHaveKey('cards');
    });
});

test('el desglose por departamento respeta el aislamiento por sucursal de un gerente', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();
    $departamentoPropio = Departamento::factory()->create(['nombre' => 'Departamento propio']);
    $departamentoAjeno = Departamento::factory()->create(['nombre' => 'Departamento ajeno']);

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id, 'departamento_id' => $departamentoPropio->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id, 'departamento_id' => $departamentoAjeno->id]);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $etiquetas = collect($page->toArray()['props']['graficas']['colaboradoresPorDepartamento'])->pluck('etiqueta');

        expect($etiquetas)->toContain('Departamento propio');
        expect($etiquetas)->not->toContain('Departamento ajeno');
    });
});

test('los documentos pendientes cuentan los que estan pendientes, en revision o requieren correccion', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $tipo = DocumentType::factory()->create();
    $colaborador = User::factory()->create();

    EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id, 'status' => EstadoDocumento::EnRevision->value]);

    $otroTipo = DocumentType::factory()->create();
    EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'document_type_id' => $otroTipo->id, 'status' => EstadoDocumento::Aprobado->value]);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        expect($page->toArray()['props']['cards']['documentos_pendientes'])->toEqual(1);
    });
});

test('un colaborador con todos sus documentos requeridos aprobados tiene expediente 100% completo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    DocumentType::query()->delete();
    $tipo = DocumentType::factory()->create(['requerido' => true, 'activo' => true]);

    $colaborador = User::factory()->create();
    EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id]);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $completos = $page->toArray()['props']['cards']['expedientes_completos'];

        expect($completos)->toBeGreaterThanOrEqual(1);
    });
});
