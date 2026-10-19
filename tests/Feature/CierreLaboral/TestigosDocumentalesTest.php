<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\TipoBaja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\CierreLaboral\ProcedimientoBajaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\TestigoDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| Testigos de los instrumentos de la baja (acta de negativa y firma física):
| se eligen de un BUSCADOR de personas activas del alcance (igual que las
| actas circunstanciadas), nunca texto libre de alguien que ya existe en
| PEOPLE; su nombre/puesto/sucursal se CONGELAN desde el expediente. El
| afectado nunca es su propio testigo, no se admiten duplicados y un testigo
| fuera de alcance se rechaza; solo un testigo externo se captura a mano.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->centro = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->otra = Sucursal::factory()->create(['nombre' => 'Orizaba']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Cajero']);

    $this->rh = clUsuario('rh_admin');
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);

    $this->afectado = Colaborador::factory()->create(['sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigoA = Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Centro', 'sucursal_principal_id' => $this->centro->id, 'puesto_id' => $this->puesto->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigoB = Colaborador::factory()->create(['name' => 'Beto', 'apellidos' => 'Centro', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->deOtra = Colaborador::factory()->create(['name' => 'Diana', 'apellidos' => 'Lejos', 'sucursal_principal_id' => $this->otra->id, 'estatus' => EstadoUsuario::Activo]);
    $this->inactivo = Colaborador::factory()->create(['sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Inactivo]);
});

test('congela el snapshot (nombre, puesto, sucursal) desde PEOPLE, ignorando el texto que mande el cliente', function () {
    $resueltos = app(TestigoDocumentalService::class)->resolver($this->rh, $this->afectado, [
        ['colaborador_id' => $this->testigoA->id, 'nombre' => 'NOMBRE FALSO', 'cargo' => 'CARGO INVENTADO'],
    ]);

    expect($resueltos)->toHaveCount(1)
        ->and($resueltos[0]['colaborador_id'])->toBe($this->testigoA->id)
        ->and($resueltos[0]['nombre'])->toBe($this->testigoA->nombreCompleto())
        ->and($resueltos[0]['puesto'])->toBe('Cajero')
        ->and($resueltos[0]['sucursal'])->toBe('Toluca Centro')
        ->and($resueltos[0]['externo'])->toBeFalse();
});

test('el afectado no puede ser su propio testigo', function () {
    expect(fn () => app(TestigoDocumentalService::class)->resolver($this->rh, $this->afectado, [
        ['colaborador_id' => $this->afectado->id],
    ]))->toThrow(ValidationException::class);
});

test('no se admiten testigos duplicados', function () {
    expect(fn () => app(TestigoDocumentalService::class)->resolver($this->rh, $this->afectado, [
        ['colaborador_id' => $this->testigoA->id],
        ['colaborador_id' => $this->testigoA->id],
    ]))->toThrow(ValidationException::class);
});

test('un testigo fuera del alcance del actor se rechaza', function () {
    // El gerente de Toluca no alcanza a alguien de Orizaba.
    expect(fn () => app(TestigoDocumentalService::class)->resolver($this->gerente, $this->afectado, [
        ['colaborador_id' => $this->deOtra->id],
    ]))->toThrow(ValidationException::class);

    // Un colaborador inactivo tampoco es testigo válido.
    expect(fn () => app(TestigoDocumentalService::class)->resolver($this->rh, $this->afectado, [
        ['colaborador_id' => $this->inactivo->id],
    ]))->toThrow(ValidationException::class);
});

test('un testigo EXTERNO se captura a mano y queda marcado como externo', function () {
    $resueltos = app(TestigoDocumentalService::class)->resolver($this->rh, $this->afectado, [
        ['nombre' => 'Lic. Externo Pérez', 'cargo' => 'Notario'],
    ]);

    expect($resueltos)->toHaveCount(1)
        ->and($resueltos[0]['colaborador_id'])->toBeNull()
        ->and($resueltos[0]['nombre'])->toBe('Lic. Externo Pérez')
        ->and($resueltos[0]['puesto'])->toBe('Notario')
        ->and($resueltos[0]['externo'])->toBeTrue();
});

test('capturar testigos de la negativa congela el snapshot en el cierre (con sucursal y colaborador_id)', function () {
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $this->afectado->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado,
        'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(), 'negativa_firma_en' => now(),
    ]);

    app(ProcedimientoBajaService::class)->capturarTestigos($cierre, [
        ['colaborador_id' => $this->testigoA->id],
        ['colaborador_id' => $this->testigoB->id],
    ], [], $this->rh);

    $testigos = $cierre->refresh()->testigos;

    expect($testigos)->toHaveCount(2)
        ->and($testigos[0]['colaborador_id'])->toBe($this->testigoA->id)
        ->and($testigos[0]['nombre'])->toBe($this->testigoA->nombreCompleto())
        // Se conserva la llave `cargo` (= puesto real) por el formato del acta.
        ->and($testigos[0]['cargo'])->toBe('Cajero')
        ->and($testigos[0]['sucursal'])->toBe('Toluca Centro');
});

test('el buscador de testigos devuelve activos del alcance y excluye al afectado', function () {
    $ids = collect(
        $this->actingAs($this->gerente)
            ->getJson(route('rh.documentos-proceso.testigos', ['colaborador' => $this->afectado->id]))
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    expect($ids)->toContain($this->testigoA->id)->toContain($this->testigoB->id)
        ->not->toContain($this->afectado->id)
        ->not->toContain($this->deOtra->id)
        ->not->toContain($this->inactivo->id);
});

test('la firma física congela el snapshot del testigo elegido; sin testigos cuando se exigen, falla', function () {
    clPlantilla('contrato_testigos_prueba', [
        'requiere_impresion' => true,
        'requiere_firma_fisica' => true,
        'requiere_testigos' => true,
    ]);

    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->afectado->id}/documentos-laborales", ['clave' => 'contrato_testigos_prueba'])
        ->assertCreated()->json('data.id');

    $flujo = app(FlujoDocumentalService::class);
    $documento = GeneratedDocument::query()->findOrFail($id);
    $flujo->marcarImpreso($documento, $this->rh);

    // Sin testigos: se rechaza (los exige la plantilla).
    expect(fn () => $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, ['huella_registrada' => false]))
        ->toThrow(ValidationException::class);

    // Con un testigo elegido por colaborador_id: procede y congela el snapshot real.
    $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, [
        'testigos' => [['colaborador_id' => $this->testigoA->id, 'nombre' => 'IGNORADO']],
    ]);

    $documento->refresh()->load('seguimientoFisico');
    expect($documento->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    $testigos = $documento->seguimientoFisico?->testigos;
    expect($testigos)->not->toBeEmpty()
        ->and($testigos[0]['colaborador_id'])->toBe($this->testigoA->id)
        ->and($testigos[0]['nombre'])->toBe($this->testigoA->nombreCompleto())
        ->and($testigos[0]['puesto'])->toBe('Cajero');
});
