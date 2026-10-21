<?php

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Puesto;
use App\Models\User;
use App\Rules\NombreSinApellidos;
use App\Services\Expedientes\ExpedienteService;
use App\Support\NombrePersona;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia;

/*
| Regresión (producción): el listado de expedientes decía «78 % · 3
| documento(s) pendiente(s)» para alguien en contratación con sus 11/11
| documentos aprobados, porque contaba los contratos (los genera RH) como
| documentos del colaborador. El expediente decía 100 %. Una sola regla.
|
| Y «NANCY NALLELY LOPEZ GARCIA Lopez Garcia»: el nombre traía los apellidos.
*/

beforeEach(function () {
    $this->seed([RolesYPermisosSeeder::class, DocumentTypeSeeder::class]);
    Storage::fake('nas');
    Notification::fake();
});

function enContratacionConDocumentosDelAlta(): Colaborador
{
    $colaborador = clColaboradorEnContratacion([
        'puesto_id' => Puesto::factory()->create()->id,
        'fecha_ingreso' => now()->toDateString(),
    ]);

    foreach (DocumentType::query()->where('requerido', true)->where('activo', true)->where('aplica_alta', true)->get() as $tipo) {
        EmployeeDocument::factory()->aprobado()->create([
            'user_id' => null,
            'colaborador_id' => $colaborador->id,
            'document_type_id' => $tipo->id,
        ]);
    }

    return $colaborador;
}

test('el listado muestra el mismo porcentaje que el expediente para alguien en contratación con sus documentos completos', function () {
    $colaborador = enContratacionConDocumentosDelAlta();
    $servicio = app(ExpedienteService::class);

    // Hay obligatorios que NO son del alta (contratos): son los que el listado contaba de más.
    expect(DocumentType::query()->where('requerido', true)->where('activo', true)->where('aplica_alta', false)->count())->toBeGreaterThan(0);

    $detalle = $servicio->resumenCompletitud($colaborador);
    $listado = $servicio->resumenesCompletitud(collect([$colaborador->id]))[$colaborador->id];

    expect($detalle['porcentaje'])->toBe(100.0)
        ->and($listado)->toBe($detalle);

    $this->actingAs(clUsuario('rh_admin'))
        ->get(route('rh.expedientes.index', ['estatus' => 'todos']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('colaboradores.data', fn ($filas) => collect($filas)->contains(fn ($fila) => $fila['id'] === $colaborador->id
                && (float) $fila['expediente_porcentaje'] === 100.0
                && $fila['documentos_pendientes'] === 0
                && str_starts_with((string) ($fila['contratos_pendientes']['etiqueta'] ?? ''), 'Contratos por generar')
                && $fila['contratos_pendientes']['estado'] === 'por_generar')));
});

test('una persona activa sigue respondiendo por todos los obligatorios en el listado', function () {
    $colaborador = enContratacionConDocumentosDelAlta();
    $colaborador->forceFill(['estado_alta' => 'activo', 'estatus' => 'activo'])->save();
    $servicio = app(ExpedienteService::class);

    $detalle = $servicio->resumenCompletitud($colaborador);

    expect($detalle['porcentaje'])->toBeLessThan(100.0)
        ->and($servicio->resumenesCompletitud(collect([$colaborador->id]))[$colaborador->id])->toBe($detalle);
});

test('con documentos del colaborador pendientes el listado conserva el aviso de documentos y no el de contratos', function () {
    $colaborador = clColaboradorEnContratacion();

    $this->actingAs(clUsuario('rh_admin'))
        ->get(route('rh.expedientes.index', ['estatus' => 'todos']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('colaboradores.data', fn ($filas) => collect($filas)->contains(fn ($fila) => $fila['id'] === $colaborador->id
                && $fila['documentos_pendientes'] > 0
                && $fila['contratos_pendientes'] === null)));
});

test('el nombre completo no duplica los apellidos que ya vienen dentro del nombre', function () {
    expect(NombrePersona::completo('NANCY NALLELY LOPEZ GARCIA', 'Lopez Garcia'))->toBe('NANCY NALLELY Lopez Garcia')
        ->and(NombrePersona::completo('María José Pérez Núñez', 'PEREZ NUNEZ'))->toBe('María José PEREZ NUNEZ')
        ->and(NombrePersona::completo('Nancy Nallely', 'Lopez Garcia'))->toBe('Nancy Nallely Lopez Garcia')
        // Un solo apellido que coincide con el último nombre es un nombre real.
        ->and(NombrePersona::completo('José Cruz', 'Cruz'))->toBe('José Cruz Cruz');

    $colaborador = new Colaborador(['name' => 'NANCY NALLELY LOPEZ GARCIA', 'apellidos' => 'Lopez Garcia']);
    expect($colaborador->nombreCompleto())->toBe('NANCY NALLELY Lopez Garcia');
});

test('al guardar, el nombre que trae los apellidos se limpia (colaborador y cuenta)', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Nancy Nallely López García', 'apellidos' => 'Lopez Garcia']);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id, 'name' => 'NANCY NALLELY LOPEZ GARCIA', 'apellidos' => 'LOPEZ GARCIA']);

    expect($colaborador->fresh()->name)->toBe('Nancy Nallely')
        ->and($cuenta->fresh()->name)->toBe('NANCY NALLELY');
});

test('la captura rechaza un nombre que incluye los apellidos con un mensaje claro', function () {
    $validador = Validator::make(
        ['nombre' => 'NANCY NALLELY LOPEZ GARCIA', 'apellidos' => 'López García'],
        ['nombre' => ['required', new NombreSinApellidos]],
    );

    expect($validador->fails())->toBeTrue()
        ->and($validador->errors()->first('nombre'))->toContain('no debe incluir los apellidos')
        ->and(Validator::make(['nombre' => 'Nancy Nallely', 'apellidos' => 'López García'], ['nombre' => [new NombreSinApellidos]])->passes())->toBeTrue();

    // En un formulario real (alta de candidato por RH).
    $this->actingAs(clUsuario('rh_admin'))
        ->post(route('rh.candidatos.store'), ['nombre' => 'Nancy Nallely Lopez Garcia', 'apellidos' => 'Lopez Garcia'])
        ->assertSessionHasErrors(['nombre']);
});

test('el comando lista en simulación y luego corrige el nombre duplicado y su cuenta', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Nancy Nallely', 'apellidos' => 'Lopez Garcia', 'numero_empleado' => 'EMP-0130']);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id, 'name' => 'Nancy Nallely', 'apellidos' => 'Lopez Garcia']);
    // Dato heredado (capturado antes de la protección del modelo).
    DB::table('colaboradores')->where('id', $colaborador->id)->update(['name' => 'NANCY NALLELY LOPEZ GARCIA']);
    DB::table('users')->where('id', $cuenta->id)->update(['name' => 'NANCY NALLELY LOPEZ GARCIA']);

    $this->artisan('colaboradores:corregir-nombres-duplicados', ['--simular' => true])
        ->expectsOutputToContain('EMP-0130')
        ->expectsOutputToContain('se corregirían 1')
        ->assertSuccessful();

    expect($colaborador->fresh()->name)->toBe('NANCY NALLELY LOPEZ GARCIA');

    $this->artisan('colaboradores:corregir-nombres-duplicados')
        ->expectsOutputToContain('Corregidos: 1')
        ->assertSuccessful();

    expect($colaborador->fresh()->name)->toBe('NANCY NALLELY')
        ->and($colaborador->fresh()->nombreCompleto())->toBe('NANCY NALLELY Lopez Garcia')
        ->and($cuenta->fresh()->name)->toBe('NANCY NALLELY');

    $this->artisan('colaboradores:corregir-nombres-duplicados', ['--simular' => true])
        ->expectsOutputToContain('No hay colaboradores')
        ->assertSuccessful();
});
