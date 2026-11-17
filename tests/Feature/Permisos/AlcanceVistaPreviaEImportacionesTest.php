<?php

use App\Models\Colaborador;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Etapa 1 · R11: «Probar con colaborador» de un documento administrativo
| respeta el alcance. R6: el importador de trabajadores solo opera sus
| propios registros (un id de otra importación responde 404).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('la vista previa con colaborador fuera del alcance responde 403', function () {
    $estructura = clEstructura();
    $b = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id]);
    $gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $estructura['sucursal']->id]);
    $gerente->givePermissionTo('plantillas_documentales.administrar');
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $b->id]);

    $this->actingAs($gerente)
        ->get(route('rh.documentos-maestros.administrativos.vista-previa', ['familia' => 'recibo_nomina', 'colaborador' => $ajeno->id]))
        ->assertForbidden();
});

test('el importador de trabajadores no abre ni aplica una importación de otro tipo', function () {
    $admin = clUsuario('super_admin');
    $ajena = ImportacionExcel::query()->create([
        'archivo_hash' => str_repeat('a', 64), 'nombre_archivo' => 'contactos.xlsx', 'tipo' => 'contactos_emergencia',
        'subido_por' => $admin->id, 'estado' => 'previsualizado', 'total_filas' => 0, 'detalle' => [],
    ]);

    $this->actingAs($admin)->get(route('rh.importaciones.show', $ajena))->assertNotFound();
    $this->actingAs($admin)->post(route('rh.importaciones.aplicar', $ajena))->assertNotFound();

    expect($ajena->fresh()->estado)->toBe('previsualizado');
});
