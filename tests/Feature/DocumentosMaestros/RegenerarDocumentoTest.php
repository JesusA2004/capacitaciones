<?php

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoContratacion;
use App\Enums\TipoEmisionDocumento;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Documentos\Versiones\Fuentes\FuenteMaestro;
use App\Services\Documentos\Versiones\VersionadoDocumentoService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosLaborales\TestigoDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| «Regenerar» un documento del paquete de contratación (nueva versión con
| los datos actuales, con motivo) y la vista previa que siempre refleja los
| datos vigentes (p. ej. un sueldo recién corregido de 6,000 a 12,000).
*/

function rgColaborador(array $estructura, array $extra = []): Colaborador
{
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 3]);

    return clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Juan',
        'apellidos' => 'Pérez López',
        'estatus' => 'en_incorporacion',
        'estado_alta' => 'pendiente_contrato',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'masculino',
        'fecha_nacimiento' => '1990-05-10',
        'curp' => 'PELJ900510HMSRPN01',
        'rfc' => 'PELJ900510AB1',
        'nss' => '12345678901',
        'telefono' => '7771234567',
        'correo_personal' => 'juan@example.com',
        'domicilio' => 'Calle Morelos 10, Col. Centro, Cuernavaca, Morelos',
        'domicilio_colonia' => 'Centro',
        'domicilio_municipio' => 'Cuernavaca',
        'domicilio_estado' => 'Morelos',
        'domicilio_cp' => '62000',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Cuernavaca, Morelos',
        'clave_elector' => 'PELJ900510HMS',
        'profesion' => 'Licenciado',
        'fecha_ingreso' => '2026-10-20',
        'salario_diario' => 200,
        'sueldo_mensual' => 6000,
        'beneficiario_nombre' => 'María López Ruiz',
        'beneficiario_parentesco' => 'Madre',
        ...$extra,
    ]));
}

function rgContrato(Colaborador $colaborador): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => '2026-10-20',
        'fecha_fin' => '2027-01-19',
        'estado' => 'vigente',
        'sueldo_mensual' => 6000,
        'puesto_id' => $colaborador->puesto_id,
        'sucursal_id' => $colaborador->sucursal_principal_id,
    ]);
}

/** Texto plano del DOCX que el motor mandó convertir (conversor de prueba). */
function rgTextoUltimoDocx(): string
{
    $docx = test()->conversor->ultimoDocx;
    expect($docx)->not->toBeNull();
    $temporal = tempnam(sys_get_temp_dir(), 'rg');
    file_put_contents($temporal, (string) $docx);
    $zip = new ZipArchive;
    $zip->open($temporal);
    $texto = strip_tags((string) $zip->getFromName('word/document.xml'));
    $zip->close();
    @unlink($temporal);

    return $texto;
}

function rgCambiarSueldo(Colaborador $colaborador, int $mensual): void
{
    test()->put("/rh/expedientes/{$colaborador->id}/datos-laborales", [
        'sueldo_mensual' => $mensual,
        'motivo' => 'Corrección: el sueldo se capturó mal en el alta.',
    ])->assertSessionHasNoErrors()->assertRedirect();
}

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->conversor = dmMotorFielDePrueba()['conversor'];
    // La vista previa también por el conversor fiel (sin la aproximación de PhpWord).
    config(['formatos_oficiales.conversor' => 'auto']);
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);
    $this->rh = clUsuario('rh_admin');
    $this->actingAs($this->rh);
});

test('cambiar el sueldo (6,000 → 12,000): la vista previa del contrato muestra el nuevo y nunca se guarda en caché', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);

    $this->get("/rh/documentos-proceso/contrato/{$contrato->id}/vista-previa?clave=contrato_capacitacion&proceso=alta")->assertOk();
    expect(rgTextoUltimoDocx())->toContain('6,000.00')->not->toContain('12,000.00');

    rgCambiarSueldo($colaborador, 12000);

    $respuesta = $this->get("/rh/documentos-proceso/contrato/{$contrato->id}/vista-previa?clave=contrato_capacitacion&proceso=alta")->assertOk();
    expect((string) $respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(rgTextoUltimoDocx())->toContain('12,000.00')->not->toContain('6,000.00')
        // El registro del contrato vigente también queda con el sueldo vigente.
        ->and((float) $contrato->refresh()->sueldo_mensual)->toBe(12000.0);
});

test('regenerar crea la v2 con los datos actuales, sustituye la v1 (sin borrarla) y registra el motivo', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $url = "/rh/documentos-proceso/contrato/{$contrato->id}/generar";

    $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $v1 = GeneratedDocument::query()->where('clave_plantilla', 'contrato_capacitacion')->firstOrFail();
    expect($v1->payload['sueldo_mensual_numero'])->toBe('6,000.00')
        ->and(TareaRh::query()->where('relacionado_id', $v1->id)->where('tipo', TipoTarea::ImpresionPendiente->value)->whereNull('resuelta_en')->exists())->toBeTrue();

    rgCambiarSueldo($colaborador, 12000);

    // Sin motivo no se regenera.
    $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('motivo');

    $motivo = 'El sueldo estaba mal capturado: se corrigió de 6,000 a 12,000.';
    $respuesta = $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true, 'motivo' => $motivo])->assertCreated();
    $v2 = GeneratedDocument::query()->findOrFail($respuesta->json('documento_id'));

    expect($v2->id)->not->toBe($v1->id)
        ->and($v2->revision_de_id)->toBe($v1->id)
        ->and($v2->motivo_revision)->toBe($motivo)
        ->and($v2->payload['sueldo_mensual_numero'])->toBe('12,000.00')
        // v1: sustituida, en el historial y su archivo intacto en el NAS.
        ->and($v1->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Cancelado)
        ->and($v1->motivo_cancelacion)->toContain($motivo)
        ->and(Storage::disk($v1->disk)->exists($v1->path))->toBeTrue()
        // El contrato apunta a la versión vigente.
        ->and($contrato->refresh()->generated_document_id)->toBe($v2->id)
        // Pendiente de impresión: el de la v1 se cierra, la v2 abre el suyo.
        ->and(TareaRh::query()->where('relacionado_id', $v1->id)->whereNull('resuelta_en')->exists())->toBeFalse()
        ->and(TareaRh::query()->where('relacionado_id', $v2->id)->where('tipo', TipoTarea::ImpresionPendiente->value)->whereNull('resuelta_en')->exists())->toBeTrue();

    $bitacora = Activity::query()->where('event', 'documento_regenerado')->latest('id')->firstOrFail();
    expect($bitacora->properties['motivo'])->toBe($motivo)
        ->and($bitacora->properties['documento_anterior_id'])->toBe($v1->id)
        ->and($bitacora->properties['version'])->toBe(2)
        ->and($bitacora->causer_id)->toBe($this->rh->id);

    $item = collect($respuesta->json('data.documentos'))->firstWhere('clave', 'contrato_capacitacion');
    expect($item['documento']['version'])->toBe(2)
        ->and(array_column($item['historial'], 'id'))->toContain($v1->id);
});

test('regenerar un documento ya impreso o firmado: se permite con motivo; el firmado se conserva intacto', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $url = "/rh/documentos-proceso/contrato/{$contrato->id}/generar";
    $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $firmado = GeneratedDocument::query()->firstOrFail();
    $firmado->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);

    $item = collect($this->getJson("/rh/documentos-proceso/colaborador/{$colaborador->id}")->json('data.0.documentos'))->firstWhere('clave', 'contrato_capacitacion');
    expect(collect($item['acciones'])->firstWhere('clave', 'regenerar')['tipo'] ?? null)->toBe('peligro');

    $colaborador->update(['domicilio' => 'Calle Corregida 123, Colonia Centro']);
    $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true, 'motivo' => 'El domicilio estaba mal escrito en el contrato firmado.'])->assertCreated();

    $nueva = GeneratedDocument::query()->latest('id')->firstOrFail();
    expect($nueva->revision_de_id)->toBe($firmado->id)
        ->and($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente)
        ->and(Storage::disk($firmado->disk)->exists($firmado->path))->toBeTrue();
});

test('sin permiso no se regenera: 403 para quien no genera documentos y para RH auxiliar sobre un firmado', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $url = "/rh/documentos-proceso/contrato/{$contrato->id}/generar";
    $this->postJson($url, ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $datos = ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true, 'motivo' => 'Intento de regenerar sin permiso suficiente.'];

    // Solo consulta (sin documentos_laborales.generar).
    $consulta = clUsuario('colaborador');
    $consulta->givePermissionTo('documentos_laborales.ver');
    $this->actingAs($consulta)->postJson($url, $datos)->assertForbidden();

    // RH auxiliar sí regenera uno sin firmar…
    $auxiliar = clUsuario('rh_auxiliar');
    $colaborador->update(['domicilio' => 'Calle Corregida 123, Colonia Centro']);
    $this->actingAs($auxiliar)->postJson($url, $datos)->assertCreated();

    // …pero no uno ya firmado (documentos_laborales.revisar_firmado).
    GeneratedDocument::query()->latest('id')->firstOrFail()->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);
    $this->actingAs($auxiliar)->postJson($url, $datos)->assertForbidden();

    expect(GeneratedDocument::query()->count())->toBe(2);
});

test('RH no edita sus propios datos (pide una corrección); sí edita el nombre de otro colaborador con historial', function () {
    $propio = $this->rh->colaborador;
    $this->put("/rh/expedientes/{$propio->id}/datos-personales", ['name' => 'Otro nombre', 'motivo' => 'Prueba'])->assertForbidden();
    $this->put("/rh/expedientes/{$propio->id}/datos-laborales", ['sueldo_mensual' => 99999, 'motivo' => 'Prueba'])->assertForbidden();

    $otro = rgColaborador($this->estructura);
    $this->put("/rh/expedientes/{$otro->id}/datos-personales", [
        'name' => 'Juan Carlos',
        'apellidos' => 'Pérez López',
        'motivo' => 'El nombre se capturó incompleto en el alta.',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($otro->refresh()->name)->toBe('Juan Carlos');
    $bitacora = Activity::query()->where('event', 'expediente_datos_editados')->latest('id')->firstOrFail();
    expect($bitacora->properties['motivo'])->toBe('El nombre se capturó incompleto en el alta.')
        ->and(collect($bitacora->properties['cambios'])->firstWhere('campo', 'name')['despues'] ?? null)->toBe('Juan Carlos');
});

test('RH corrige la fecha de ingreso de otro colaborador (con motivo) y las fechas del contrato la siguen', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['fecha_ingreso' => '2026-10-21'])
        ->assertSessionHasErrors('motivo');

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['fecha_ingreso' => '2026-10-21', 'motivo' => 'La fecha de ingreso real fue el 21.'])
        ->assertSessionHasNoErrors();

    expect($colaborador->refresh()->fecha_ingreso->toDateString())->toBe('2026-10-21');

    $this->get("/rh/documentos-proceso/contrato/{$contrato->id}/vista-previa?clave=contrato_capacitacion&proceso=alta")->assertOk();
    expect(rgTextoUltimoDocx())->toContain('21 de octubre de 2026')->toContain('20 de enero de 2027');

    $bitacora = Activity::query()->where('event', 'expediente_datos_laborales_editados')->latest('id')->firstOrFail();
    expect(collect($bitacora->properties['cambios'])->firstWhere('campo', 'fecha_ingreso')['despues'] ?? null)->toBe('21/10/2026');
});

test('un colaborador sin cuenta de RH no ve la acción «Regenerar»', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();

    $titular = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $titular->assignRole('colaborador');
    $documento = GeneratedDocument::query()->firstOrFail();

    expect($titular->can('regenerar', $documento))->toBeFalse()
        ->and($this->rh->can('regenerar', $documento))->toBeTrue();
});

/*
| Flujo uniforme (docs/DOCUMENTOS_VERSIONES.md), módulo de contratación.
*/

function rgUrlEdicion(ContratoLaboral $contrato, string $accion): string
{
    return "/rh/documentos-edicion/contrato/{$contrato->id}/{$accion}";
}

test('escenario 1: contratación con corrección y regeneración, de principio a fin', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $v1 = GeneratedDocument::query()->sole();
    expect($v1->huella_datos)->not->toBeNull()->and($v1->tipo_emision)->toBe(TipoEmisionDocumento::Original);

    // Editar datos: lista blanca, sin datos de la empresa.
    $respuesta = $this->getJson(rgUrlEdicion($contrato, 'campos').'?clave=contrato_capacitacion')->assertOk()->json();
    $claves = array_column($respuesta['campos'], 'clave');
    expect($claves)->toContain('domicilio_mayusculas')
        ->and($claves)->not->toContain('nombre_completo_mayusculas')
        ->and($claves)->not->toContain('sueldo_mensual_numero')
        ->and($claves)->not->toContain('representante_legal')
        ->and($claves)->not->toContain('empresa_razon_social');

    // Vista previa = datos nuevos y no guarda nada.
    $this->postJson(rgUrlEdicion($contrato, 'vista-previa'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77']])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(rgTextoUltimoDocx())->toContain('CALLE NUEVA 77')
        ->and(GeneratedDocument::query()->count())->toBe(1);

    // Un dato ajeno a la lista blanca se rechaza; sin motivo no se corrige.
    $this->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['representante_legal' => 'Otra Persona'], 'motivo' => 'Intento de pisar el dato'])->assertUnprocessable();
    $this->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77']])->assertUnprocessable()->assertJsonValidationErrors('motivo');

    // Regenerar: versión ligada, anterior cancelada y conservada, historial de correcciones.
    $this->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77'], 'motivo' => 'El domicilio se capturó mal.'])
        ->assertCreated()->assertJsonPath('creado', true);
    $v2 = GeneratedDocument::query()->latest('id')->firstOrFail();
    expect($v2->revision_de_id)->toBe($v1->id)
        ->and($v2->payload['domicilio_mayusculas'])->toBe('CALLE NUEVA 77')
        ->and($v2->correcciones['domicilio_mayusculas']['nuevo'])->toBe('CALLE NUEVA 77')
        ->and($v2->tipo_emision)->toBe(TipoEmisionDocumento::Correccion)
        ->and($v1->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Cancelado);
    Storage::disk($v1->disk)->assertExists($v1->path);
    $this->assertDatabaseHas('correcciones', ['dominio' => 'documento_generado', 'sujeto_id' => $v2->id, 'actor_id' => $this->rh->id]);

    // Idempotente: sin cambios, misma versión, sin archivos nuevos.
    $archivos = count(Storage::disk($v2->disk)->allFiles());
    $this->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77'], 'motivo' => 'Otro clic.'])
        ->assertOk()->assertJsonPath('creado', false)->assertJsonPath('documento_id', $v2->id);
    expect(count(Storage::disk($v2->disk)->allFiles()))->toBe($archivos);

    // Descarga = versión vigente; la anterior solo con permiso; sin rutas físicas.
    $this->get(route('rh.documentos-laborales.descargar', $v2))->assertOk();
    $versiones = $this->getJson(rgUrlEdicion($contrato, 'versiones').'?clave=contrato_capacitacion')->assertOk();
    expect($versiones->json('versiones.0.id'))->toBe($v2->id)
        ->and($versiones->json('versiones.0.vigente'))->toBeTrue()
        ->and($versiones->json('versiones.1.id'))->toBe($v1->id)
        ->and($versiones->getContent())->not->toMatch('/"(path|disk|docx_path)"|expedientes\//');

    $ajeno = clUsuario('colaborador');
    $this->actingAs($ajeno)->getJson(rgUrlEdicion($contrato, 'versiones').'?clave=contrato_capacitacion')->assertForbidden();
    $this->actingAs($ajeno)->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion'])->assertForbidden();
    $this->actingAs($ajeno)->get(route('rh.documentos-laborales.descargar', $v1))->assertForbidden();
});

test('una corrección de un colaborador no alcanza a otro y manuales no pisa el nombre', function () {
    $a = rgColaborador($this->estructura);
    $b = rgColaborador($this->estructura, ['name' => 'Beatriz', 'apellidos' => 'Solís Mora', 'curp' => 'SOMB900510MMSLRT02', 'rfc' => 'SOMB900510AB2', 'nss' => '12345678902', 'correo_personal' => 'b@example.com', 'domicilio' => 'Calle Hidalgo 5']);
    $contratoA = rgContrato($a);
    $contratoB = rgContrato($b);
    $generar = fn (ContratoLaboral $c, array $extra = []) => $this->postJson("/rh/documentos-proceso/contrato/{$c->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', ...$extra]);

    $generar($contratoB)->assertCreated();
    $generar($contratoA, ['manuales' => ['nombre_completo' => 'Beatriz Solís Mora']])->assertUnprocessable()->assertJsonValidationErrors('manuales');
    $generar($contratoA)->assertCreated();
    $this->postJson(rgUrlEdicion($contratoA, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE SOLO DE A'], 'motivo' => 'Corrección solo de A.'])->assertCreated();

    $documentoB = GeneratedDocument::query()->where('colaborador_id', $b->id)->sole();
    $documentoA = GeneratedDocument::query()->where('colaborador_id', $a->id)->latest('id')->firstOrFail();
    expect(mb_strtoupper($documentoB->payload['domicilio_mayusculas']))->toBe('CALLE HIDALGO 5')
        ->and($documentoB->correcciones)->toBeNull()
        ->and($documentoA->documentable_id)->toBe($contratoA->id)
        ->and($documentoA->payload['nombre_completo_mayusculas'] ?? '')->not->toContain('BEATRIZ');
});

test('corregir un dato también corrige su variante en mayúsculas dentro del PDF', function () {
    $contrato = rgContrato(rgColaborador($this->estructura));
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $campos = array_column($this->getJson(rgUrlEdicion($contrato, 'campos').'?clave=contrato_capacitacion')->json('campos'), 'clave');
    $variantes = array_filter($campos, fn (string $c): bool => str_ends_with($c, '_mayusculas') && in_array(substr($c, 0, -11), $campos, true));

    expect($variantes)->toBe([])
        ->and(FuenteMaestro::conVariantes(['telefono' => 'abc'])['telefono_mayusculas'])->toBe('ABC');
});

test('un fallo del render o de la base conserva la versión anterior y no deja archivos', function () {
    $colaborador = rgColaborador($this->estructura);
    $contrato = rgContrato($colaborador);
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $v1 = GeneratedDocument::query()->sole();
    $archivos = count(Storage::disk($v1->disk)->allFiles());
    $emitir = fn () => $this->postJson(rgUrlEdicion($contrato, 'emitir'), ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77'], 'motivo' => 'Corrección del domicilio.']);

    // Falla al cancelar la anterior (último paso): ni versión nueva ni archivo.
    $flujo = Mockery::mock(FlujoDocumentalService::class, [app(TareaService::class), app(NotificadorRhService::class), app(AuditoriaService::class), app(DocumentoStorageService::class), app(TestigoDocumentalService::class)])->makePartial();
    $flujo->shouldReceive('cancelar')->andThrow(new RuntimeException('falla simulada'));
    app()->instance(FlujoDocumentalService::class, $flujo);
    app()->forgetInstance(VersionadoDocumentoService::class);
    app()->forgetInstance(MotorDocumentalService::class);
    $emitir()->assertUnprocessable()->assertJsonValidationErrors('documento');

    expect(GeneratedDocument::query()->count())->toBe(1)
        ->and($v1->refresh()->estado_flujo)->not->toBe(EstadoFlujoDocumento::Cancelado)
        ->and(count(Storage::disk($v1->disk)->allFiles()))->toBe($archivos);
    app()->forgetInstance(FlujoDocumentalService::class);

    // Falla el render: la anterior sigue vigente y no se tocó nada.
    $fuente = Mockery::mock(FuenteMaestro::class, [app(DocumentoProcesoService::class), 'alta', $contrato, 'contrato_capacitacion'])->makePartial();
    $fuente->shouldReceive('renderizar')->andThrow(new RuntimeException('Word no responde'));

    expect(fn () => app(VersionadoDocumentoService::class)->emitir($fuente, ['domicilio_mayusculas' => 'CALLE NUEVA 77'], 'Corrección del domicilio.', $this->rh))
        ->toThrow(ValidationException::class);
    expect(GeneratedDocument::query()->count())->toBe(1)
        ->and($v1->refresh()->estado_flujo)->not->toBe(EstadoFlujoDocumento::Cancelado)
        ->and(count(Storage::disk($v1->disk)->allFiles()))->toBe($archivos);
});

test('firmado inmutable: con rectificación el original no cambia', function () {
    $contrato = rgContrato(rgColaborador($this->estructura));
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $firmado = GeneratedDocument::query()->sole();
    $firmado->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);
    $checksum = $firmado->checksum;
    $contenido = Storage::disk($firmado->disk)->get($firmado->path);
    $cuerpo = ['clave' => 'contrato_capacitacion', 'campos' => ['domicilio_mayusculas' => 'CALLE NUEVA 77'], 'motivo' => 'Domicilio mal escrito en lo firmado.'];

    // RH auxiliar no rectifica; regenerar encima de lo firmado tampoco se admite.
    $this->actingAs(clUsuario('rh_auxiliar'))->postJson(rgUrlEdicion($contrato, 'emitir'), [...$cuerpo, 'rectificar' => true])->assertForbidden();
    $this->actingAs($this->rh)->postJson(rgUrlEdicion($contrato, 'emitir'), [...$cuerpo, 'rectificar' => true])->assertCreated();

    $rectificacion = GeneratedDocument::query()->latest('id')->firstOrFail();
    expect($rectificacion->tipo_emision)->toBe(TipoEmisionDocumento::Rectificacion)
        ->and($rectificacion->revision_de_id)->toBe($firmado->id)
        ->and($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente)
        ->and($firmado->checksum)->toBe($checksum)
        ->and(Storage::disk($firmado->disk)->get($firmado->path))->toBe($contenido);
});
