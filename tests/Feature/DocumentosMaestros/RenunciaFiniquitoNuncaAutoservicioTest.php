<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * Renuncia/finiquito (baja): nunca autoservicio, igual que el recibo de
 * nómina — se entrega impreso/en persona por RH. El colaborador se informa
 * de su situación laboral en la app, pero nunca ve este documento legal ahí
 * (pedido explícito del negocio, cierre de MR. LANA PEOPLE).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador no puede ver ni firmar su propio documento de renuncia o finiquito', function () {
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $colaborador->assignRole('colaborador');

    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => $colaborador->colaborador_id,
        'categoria' => CategoriaDocumento::BajaFiniquito->value,
        'estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador->value,
    ]);

    expect($colaborador->can('ver', $documento))->toBeFalse()
        ->and($colaborador->can('descargar', $documento))->toBeFalse()
        ->and($colaborador->can('firmar', $documento))->toBeFalse();
});

test('un colaborador si puede ver su propio contrato (otra categoria) una vez firmado', function () {
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $colaborador->assignRole('colaborador');

    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => $colaborador->colaborador_id,
        'categoria' => CategoriaDocumento::Contratos->value,
        'estado_flujo' => EstadoFlujoDocumento::Archivado->value,
    ]);

    expect($colaborador->can('ver', $documento))->toBeTrue();
});

test('rh con permiso si puede ver el documento de renuncia o finiquito de otro colaborador', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => Colaborador::factory()->create()->id,
        'categoria' => CategoriaDocumento::BajaFiniquito->value,
        'estado_flujo' => EstadoFlujoDocumento::Archivado->value,
    ]);

    expect($rh->can('ver', $documento))->toBeTrue();
});

test('super_admin puede ver su propio documento de renuncia o finiquito (operacion administrativa, queda auditado)', function () {
    $admin = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $admin->assignRole('super_admin');

    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => $admin->colaborador_id,
        'categoria' => CategoriaDocumento::BajaFiniquito->value,
        'estado_flujo' => EstadoFlujoDocumento::Archivado->value,
    ]);

    expect($admin->can('ver', $documento))->toBeTrue();
});
