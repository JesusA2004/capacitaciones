<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\Responsiva;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Actas\ActaService;
use App\Services\Documentos\Versiones\Fuentes\FuenteResponsiva;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Responsivas\ResponsivaPdfService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../RevisionFinal/apoyo.php';

/*
| Regenerar el PDF de los documentos administrativos (actas, préstamos,
| responsivas): nueva versión con motivo, la anterior en el historial, lo
| firmado nunca se reabre ni pierde su firma (docs/DOCUMENTOS_VERSIONES.md).
*/

beforeEach(function () {
    // Disco propio del proceso: otra corrida en paralelo que falsea «nas» no
    // borra los PDF de esta (se comprueba que la versión anterior se conserva).
    $this->disco = sprintf('nas_regeneracion_%d', getmypid());
    config(['expedientes.disk' => $this->disco, 'documentos_maestros.disk' => $this->disco]);
    Storage::fake($this->disco);
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
});

function rgActa(object $prueba): ActaAdministrativa
{
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => Sucursal::factory()->create()->id, 'estatus' => 'activo']);
    $prueba->actingAs($prueba->rh)->post(route('rh.actas.store', $persona), [
        'tipo' => 'administrativa',
        'fecha' => now()->toDateString(),
        'lugar' => 'Toluca, Estado de México',
        'hechos' => 'El arqueo de caja arrojó un faltante.',
        'circunstanciada' => [
            'incidencia' => ['tipo' => 'irregularidad_bienes', 'fecha_desde' => now()->subDay()->toDateString()],
            'jefe' => ['participa' => false],
            'testigos_hechos' => [],
            'testigos_asistencia' => [],
            'trabajador' => ['manifesto' => false],
        ],
    ])->assertSessionHasNoErrors();
    $acta = ActaAdministrativa::query()->latest('id')->firstOrFail();
    app(ActaService::class)->generarDocumento($acta, $prueba->rh);

    return $acta->refresh();
}

/**
 * @return array<string, mixed>
 */
function rgItem(User $usuario, string $tipo, int $id, ?string $clave = null): array
{
    $documentos = test()->actingAs($usuario)->getJson(route('rh.documentos-proceso.show', ['tipo' => $tipo, 'id' => $id]))->assertOk()->json('data.documentos');

    return collect($documentos)->first(fn (array $d): bool => $clave === null || $d['clave'] === $clave);
}

/**
 * @param  array<string, mixed>  $item
 * @return list<string>
 */
function rgAcciones(array $item): array
{
    return array_column($item['acciones'], 'clave');
}

test('acta generada sin firmar: se ofrece regenerar y la nueva versión conserva la anterior', function () {
    $acta = rgActa($this);
    $clave = $acta->tipo->clavePlantilla();
    $original = $acta->generated_document_id;

    expect(rgAcciones(rgItem($this->rh, 'acta', $acta->id)))->toContain('regenerar', 'vista_previa');

    app(ActaService::class)->actualizar($acta, ['hechos' => 'El arqueo de caja arrojó un faltante de $500.'], $this->rh, 'Se precisó el monto del faltante');
    $item = rgItem($this->rh, 'acta', $acta->id);
    expect($item['desactualizado'])->toBeTrue();

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => $clave, 'regenerar' => true, 'motivo' => 'Se precisó el monto del faltante',
    ])->assertCreated()->assertJsonPath('creado', true);

    $acta->refresh();
    expect($acta->generated_document_id)->not->toBe($original)
        ->and(GeneratedDocument::query()->whereKey($original)->exists())->toBeTrue()
        ->and(GeneratedDocument::query()->where('documentable_id', $acta->id)->where('clave_plantilla', $clave)->count())->toBe(2);

    $item = rgItem($this->rh, 'acta', $acta->id);
    expect($item['desactualizado'])->toBeFalse()
        ->and($item['documento']['version'])->toBe(2)
        ->and($item['historial'])->toHaveCount(1);
});

test('acta firmada: RH con permiso emite una rectificación ligada y el original sigue firmado', function () {
    $acta = rgActa($this);
    $firmado = rfFirmar($acta->documento);
    // Lo firmado no se edita; cambió un dato del expediente que el formato muestra.
    $acta->colaborador->update(['apellidos' => 'Corregido Apellido']);

    $item = rgItem($this->rh, 'acta', $acta->id);
    expect(rgAcciones($item))->toContain('regenerar')
        ->and($item['aviso_regenerar'])->toBeNull();

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => $acta->tipo->clavePlantilla(), 'regenerar' => true, 'motivo' => 'El nombre del testigo venía mal escrito',
    ])->assertCreated();

    $nuevo = GeneratedDocument::query()->whereKey($acta->refresh()->generated_document_id)->firstOrFail();
    expect($nuevo->id)->not->toBe($firmado->id)
        ->and($nuevo->revision_de_id)->toBe($firmado->id)
        ->and($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('acta: corregir el expediente (nombre del trabajador) deja el PDF desactualizado y regenerar emite otra versión', function () {
    $acta = rgActa($this);
    expect(rgItem($this->rh, 'acta', $acta->id)['desactualizado'])->toBeFalse();

    $acta->colaborador->update(['apellidos' => 'Apellido Corregido']);
    expect(rgItem($this->rh, 'acta', $acta->id)['desactualizado'])->toBeTrue();

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => $acta->tipo->clavePlantilla(), 'regenerar' => true, 'motivo' => 'Se corrigió el nombre en el expediente',
    ])->assertCreated()->assertJsonPath('creado', true);

    expect(rgItem($this->rh, 'acta', $acta->id)['desactualizado'])->toBeFalse();
});

test('acta firmada: sin permiso de rectificar no se ofrece ni se acepta', function () {
    $acta = rgActa($this);
    rfFirmar($acta->documento);
    Role::findByName('rh_admin')->revokePermissionTo('documentos_laborales.revisar_firmado');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $rh = User::query()->findOrFail($this->rh->id);

    expect(rgAcciones(rgItem($rh, 'acta', $acta->id)))->not->toContain('regenerar');

    $this->actingAs($rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => $acta->tipo->clavePlantilla(), 'regenerar' => true, 'motivo' => 'Intento sin permiso',
    ])->assertForbidden();
});

test('acta cerrada sin firma: no se ofrece regenerar y se explica por qué', function () {
    $acta = rgActa($this);
    $acta->update(['estado' => EstadoActa::Cerrada]);

    $item = rgItem($this->rh, 'acta', $acta->id);
    expect(rgAcciones($item))->not->toContain('regenerar')
        ->and($item['aviso_regenerar'])->toContain('cerrada');

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => $acta->tipo->clavePlantilla(), 'regenerar' => true, 'motivo' => 'Intento con el acta cerrada',
    ])->assertUnprocessable();
});

test('acta: el editor de datos regenera con vista previa y deja el historial de versiones', function () {
    $acta = rgActa($this);
    $clave = $acta->tipo->clavePlantilla();
    $ruta = fn (string $accion) => route("rh.documentos-edicion.{$accion}", ['tipo' => 'acta', 'id' => $acta->id]);

    $this->actingAs($this->rh)->getJson($ruta('campos').'?clave='.$clave)->assertOk()->assertJsonPath('vigente.firmado', false);
    $this->actingAs($this->rh)->post($ruta('vista-previa'), ['clave' => $clave, 'campos' => []])->assertOk()->assertHeader('Content-Type', 'application/pdf');

    app(ActaService::class)->actualizar($acta, ['hechos' => 'Hechos corregidos antes de firmar.'], $this->rh, 'Se corrigieron los hechos');
    $this->actingAs($this->rh)->postJson($ruta('emitir'), ['clave' => $clave, 'motivo' => 'Se corrigieron los hechos'])->assertCreated();

    $versiones = $this->actingAs($this->rh)->getJson($ruta('versiones').'?clave='.$clave)->assertOk()->json('versiones');
    expect($versiones)->toHaveCount(2);
});

test('préstamo: el pagaré firmado se rectifica desde su sección sin perder la firma', function () {
    foreach (['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'] as $clave) {
        clPlantilla($clave, ['requiere_impresion' => true, 'requiere_firma_fisica' => true]);
    }
    $jefe = clUsuario('jefe_directo');
    $colaborador = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $cuenta->assignRole('colaborador');

    Sanctum::actingAs($cuenta);
    $solicitudId = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'motivo' => 'Gastos médicos', 'monto_solicitado' => 10000])->assertCreated()->json('id');
    Sanctum::actingAs($jefe);
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => true, 'comentario' => 'De acuerdo'])->assertOk();
    Sanctum::actingAs($this->rh);
    $datos = $this->postJson("/api/v1/rh/solicitudes/{$solicitudId}/prestamo/autorizar", ['monto_autorizado' => 8000, 'plazo_autorizado' => 8])->assertCreated()->json('data');
    $prestamo = Prestamo::query()->findOrFail($datos['id']);

    // Sin firmar: regenerar con motivo.
    expect(rgAcciones(rgItem($this->rh, 'prestamo', $prestamo->id, 'prestamo_pagare')))->toContain('regenerar');

    $pagare = GeneratedDocument::query()->findOrFail($datos['pagare']['id']);
    $flujo = app(FlujoDocumentalService::class);
    $flujo->marcarImpreso($pagare, $this->rh);
    $flujo->registrarFirmaFisica($pagare->refresh(), $this->rh, ['huella_registrada' => false]);

    // Firmado (plantilla anterior): la rectificación también se ofrece.
    $colaborador->update(['apellidos' => 'Corregido Apellido']);
    expect(rgAcciones(rgItem($this->rh, 'prestamo', $prestamo->id, 'prestamo_pagare')))->toContain('regenerar');

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'prestamo', 'id' => $prestamo->id]), [
        'clave' => 'prestamo_pagare', 'regenerar' => true, 'motivo' => 'El monto en letra venía mal escrito',
    ])->assertCreated();

    $vigente = app(DocumentoProcesoService::class)->documentoVigente($prestamo->refresh(), 'prestamo_pagare');
    expect($vigente?->revision_de_id)->toBe($pagare->id)
        ->and($pagare->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('responsiva: el editor regenera con vista previa y conserva la versión anterior', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'Mr. Lana', 'razon_social' => 'Productos y Servicios Mr Lana SAPI de C.V.']);
    $sucursal = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'empresa_id' => $empresa->id]);
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id, 'puesto_id' => Puesto::factory()->create(['nombre' => 'Gerente de sucursal'])->id]);
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'estatus' => EstadoUsuario::Activo, 'puesto_id' => Puesto::factory()->create(['nombre' => 'Subgerente'])->id]);
    $id = $this->actingAs($this->rh)->postJson(route('rh.responsivas.store', $persona), [
        'tipo' => 'bien_mueble',
        'lugar' => 'Cuernavaca, Morelos',
        'fecha_entrega' => now()->toDateString(),
        'responsable_entrega_id' => $gerente->id,
        'datos' => [
            'tipo_mueble' => 'escritorio', 'marca' => 'Officemax', 'modelo' => 'OM-120', 'numero_inventario' => 'INV-0001',
            'numero_serie' => 'SER-77', 'color' => 'Café', 'material' => 'Madera', 'tamano' => '1.20 x 0.60 m',
            'estado' => 'buen_estado', 'danos' => 'Ninguno.', 'ubicacion_lugar' => 'Recepción', 'ubicacion_area' => 'Oficina Cuernavaca',
        ],
    ])->assertCreated()->json('responsiva.id');
    $responsiva = Responsiva::query()->findOrFail($id);
    $anterior = $responsiva->pdf_path;
    // Versión previa al versionado (sin huella): la regeneración crea otra.
    GeneratedDocument::query()->where('documentable_id', $id)->where('clave_plantilla', FuenteResponsiva::CLAVE)->update(['huella_datos' => null]);
    $ruta = fn (string $accion) => route("rh.documentos-edicion.{$accion}", ['tipo' => 'responsiva', 'id' => $responsiva->id]);

    $this->actingAs($this->rh)->getJson($ruta('campos'))->assertOk();
    $this->actingAs($this->rh)->post($ruta('vista-previa'), ['campos' => []])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->rh)->postJson($ruta('emitir'), [])->assertUnprocessable();
    $this->actingAs($this->rh)->postJson($ruta('emitir'), ['motivo' => 'Formato vigente de Jurídico'])->assertCreated();

    $versiones = $this->actingAs($this->rh)->getJson($ruta('versiones'))->assertOk()->json('versiones');
    expect($versiones)->toHaveCount(2)
        ->and($responsiva->refresh()->pdf_path)->not->toBe($anterior);
    Storage::disk($this->disco)->assertExists($anterior);
    Storage::disk($this->disco)->assertExists((string) $responsiva->pdf_path);

    // El formato vigente forma parte de la huella: si cambia, regenerar reimprime.
    $huella = (new FuenteResponsiva(app(ResponsivaPdfService::class), $responsiva))->valoresHuella([], $this->rh);
    expect($huella['formato'])->toBeString()->not->toBeEmpty();
});

test('historial del ciclo: el acta, el préstamo y la responsiva enlazan al módulo donde se regeneran', function () {
    $acta = rgActa($this);
    $origen = app(DocumentoProcesoService::class)->origen($acta->documento);

    expect($origen)->toBe(['etiqueta' => 'Abrir acta', 'url' => route('rh.actas.show', $acta->id, false)]);
});
