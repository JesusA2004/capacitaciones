<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoValidacionVisual;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoBaja;
use App\Enums\TipoContratacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;

/*
| Reglas de fidelidad del motor documental con los ORIGINALES reales de
| Jurídico (docs/formatos_fuente; se omite si la carpeta no existe). La
| conversión Word→PDF y el QA visual son de prueba (tests/Support): aquí se
| prueba que el motor NUNCA emite un documento definitivo sin conversor
| fiel ni con una versión sin diseño validado, que guarda Word + PDF con su
| huella, que no deforma (desbordes / páginas extra), la activación segura,
| el histórico intacto, las revisiones de firmados y los permisos.
*/

function mfFuente(): string
{
    return base_path('docs/formatos_fuente');
}

/**
 * Importa SOLO las familias pedidas (más rápido que toda la carpeta).
 *
 * @param  list<string>  $familias
 */
function mfImportar(array $familias): void
{
    $catalogo = app(CatalogoMaestrosService::class);
    $importador = app(ImportadorFormatosJuridicosService::class);

    foreach (glob(mfFuente().'/*') ?: [] as $ruta) {
        $hash = (string) hash_file('sha256', $ruta);

        foreach ($catalogo->todasPorHash($hash) as $fuente) {
            if (in_array($fuente['familia'], $familias, true)) {
                $importador->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
            }
        }
    }
}

function mfColaborador(string $grupo, array $extra = []): Colaborador
{
    $estructura = clEstructura();
    $estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);
    $puesto = Puesto::factory()->create(['nombre' => 'Puesto '.$grupo, 'grupo_documental' => $grupo, 'meses_periodo_prueba' => $grupo === 'gestor' ? 2 : 3]);

    return clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Juan', 'apellidos' => 'Pérez López',
        'sucursal_principal_id' => $estructura['sucursal']->id, 'departamento_id' => $estructura['departamento']->id, 'puesto_id' => $puesto->id,
        'genero' => 'masculino', 'fecha_nacimiento' => '1990-05-10', 'curp' => 'PELJ900510HMSRPN01', 'rfc' => 'PELJ900510AB1', 'nss' => '12345678901',
        'telefono' => '7771234567', 'correo_personal' => 'juan@example.com', 'domicilio' => 'Calle Morelos 10, Col. Centro, Cuernavaca, Morelos',
        'domicilio_colonia' => 'Centro', 'domicilio_municipio' => 'Cuernavaca', 'domicilio_estado' => 'Morelos', 'domicilio_cp' => '62000',
        'nacionalidad' => 'Mexicana', 'estado_civil' => 'soltero', 'lugar_nacimiento' => 'Cuernavaca, Morelos', 'clave_elector' => 'PELJ900510HMS',
        'profesion' => 'Licenciado', 'fecha_ingreso' => '2026-10-05', 'sueldo_mensual' => 15000,
        ...$extra,
    ]));
}

function mfContrato(Colaborador $colaborador, ?ContratoLaboral $anterior = null): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => $anterior ? TipoContratacion::Indeterminado : TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => $anterior ? '2026-12-05' : '2026-10-05', 'fecha_fin' => $anterior ? null : '2026-12-04',
        'estado' => 'vigente', 'sueldo_mensual' => $colaborador->sueldo_mensual ?? 15000,
        'puesto_id' => $colaborador->puesto_id, 'sucursal_id' => $colaborador->sucursal_principal_id, 'contrato_anterior_id' => $anterior?->id,
    ]);
}

/**
 * @return array<string, string>
 */
function mfZip(string $docx): array
{
    $temporal = tempnam(sys_get_temp_dir(), 'mf');
    file_put_contents($temporal, $docx);
    $zip = new ZipArchive;
    $zip->open($temporal);
    $partes = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $partes[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($temporal);

    return $partes;
}

function mfOriginalDe(string $familia): string
{
    $catalogo = app(CatalogoMaestrosService::class);

    foreach (glob(mfFuente().'/*') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if ($fuente['familia'] === $familia) {
                return (string) file_get_contents($ruta);
            }
        }
    }

    throw new RuntimeException("Sin original para {$familia}");
}

beforeEach(function () {
    if (! is_dir(mfFuente()) || glob(mfFuente().'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->motorFiel = dmMotorFielDePrueba();
    $this->rh = clUsuario('rh_admin');
    Sanctum::actingAs($this->rh);
});

test('sin conversor fiel: 422 DOCUMENT_CONVERTER_UNAVAILABLE y no se guarda ningún documento aproximado', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    app()->instance(ConversorDocxPdf::class, new ConversorDocxPdf(app(FormatoPreviewService::class)));
    $contrato = mfContrato(mfColaborador('gestor'));

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DOCUMENT_CONVERTER_UNAVAILABLE')
        ->assertJsonPath('message', 'No hay un motor de conversión fiel disponible para generar este documento oficial.');

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and(Storage::disk('nas')->allFiles('expedientes'))->toBe([]);
});

test('versión sin diseño validado: 422 DOCUMENT_VISUAL_VALIDATION_FAILED; la tarjeta lo avisa antes de pulsar Generar', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    DocumentTemplate::query()->where('familia', 'contrato_capacitacion.gestor')->update(['visual_validation_status' => EstadoValidacionVisual::Fallida->value]);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);

    $item = collect($this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->json('data.0.documentos'))->firstWhere('clave', 'contrato_capacitacion');
    expect($item['estado'])->toBe('formato_sin_validar')
        ->and(array_column($item['acciones'], 'clave'))->not->toContain('generar');

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DOCUMENT_VISUAL_VALIDATION_FAILED')
        ->assertJsonPath('message', 'La versión del formato no está validada para generar documentos oficiales.');
});

test('documento definitivo: guarda el Word llenado y el PDF, con motor, fidelidad, versión del master y huellas', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    $contrato = mfContrato(mfColaborador('gestor'));

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $documento = GeneratedDocument::query()->firstOrFail();
    $docx = (string) Storage::disk('nas')->get((string) $documento->docx_path);
    $pdf = (string) Storage::disk('nas')->get($documento->path);

    expect($documento->conversion_engine)->toBe('word')
        ->and($documento->conversion_fidelity)->toBe('nativa')
        ->and($documento->master_version)->toBe(1)
        ->and($documento->master_hash)->not->toBeNull()
        ->and($documento->docx_hash)->toBe(hash('sha256', $docx))
        ->and($documento->checksum)->toBe(hash('sha256', $pdf))
        ->and($documento->paginas)->toBe(1)
        ->and(str_starts_with($pdf, '%PDF'))->toBeTrue();
});

test('Word generado: conserva encabezados, pies, imágenes, tablas, estilos y numeración del original; sin marcadores; datos largos', function (string $grupo, string $familia) {
    mfImportar([$familia]);
    $largo = mfColaborador($grupo, [
        'name' => 'José Francisco de Jesús', 'apellidos' => 'Hernández González',
        'domicilio' => 'Calle Prolongación Emiliano Zapata número 1250 interior 4-B, colonia Lomas de la Selva Norte, C.P. 62270, Cuernavaca, Morelos',
        'sueldo_mensual' => 123456.78,
    ]);
    $largo->puesto->update(['nombre' => 'Gerente de Sucursal de Crédito Grupal Regional']);
    $largo->sucursalPrincipal->update(['nombre' => 'Pachuca de Soto Centro Histórico']);
    $contrato = mfContrato($largo->refresh());

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $documento = GeneratedDocument::query()->where('master_familia', $familia)->firstOrFail();
    $generado = mfZip((string) Storage::disk('nas')->get((string) $documento->docx_path));
    $original = mfZip(mfOriginalDe($familia));
    $texto = strip_tags($generado['word/document.xml']);

    foreach (array_keys($original) as $parte) {
        expect($generado)->toHaveKey($parte);

        if (str_starts_with($parte, 'word/media/') || in_array($parte, ['word/styles.xml', 'word/numbering.xml', 'word/settings.xml', 'word/fontTable.xml'], true)) {
            expect(hash('sha256', $generado[$parte]))->toBe(hash('sha256', $original[$parte]));
        }
    }

    expect(substr_count($generado['word/document.xml'], '<w:tbl>'))->toBe(substr_count($original['word/document.xml'], '<w:tbl>'))
        ->and(substr_count($generado['word/document.xml'], '<w:sectPr'))->toBe(substr_count($original['word/document.xml'], '<w:sectPr'))
        ->and($texto)->not->toMatch('/\{\{[a-z0-9_]+@\d+\}\}/')
        ->and($texto)->toContain('JOSÉ FRANCISCO DE JESÚS HERNÁNDEZ GONZÁLEZ');
})->with([
    'gestor' => ['gestor', 'contrato_capacitacion.gestor'],
    'gerente' => ['gerente', 'contrato_capacitacion.gerente'],
    'subgerente' => ['subgerente', 'contrato_capacitacion.subgerente'],
    'regional' => ['regional', 'contrato_capacitacion.regional'],
    'coordinadora' => ['coordinadora', 'contrato_capacitacion.coordinadora'],
    'administrativo' => ['administrativo_confianza', 'contrato_capacitacion.administrativo'],
]);

test('página extra inesperada: si con los datos de la persona el Word crece, 422 DOCUMENT_FIELD_OVERFLOW (nada se guarda)', function () {
    $this->motorFiel['validacion']->paginasOriginal = 9;
    mfImportar(['contrato_capacitacion.gestor']);
    $this->motorFiel['conversor']->paginas = 10;
    $contrato = mfContrato(mfColaborador('gestor', ['name' => 'José Francisco de Jesús', 'apellidos' => 'Hernández González']));

    $respuesta = $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_FIELD_OVERFLOW');

    expect($respuesta->json('detalle.razon'))->toContain('9 página(s)')
        ->and(array_column($respuesta->json('detalle.campos'), 'campo'))->not->toBeEmpty()
        ->and(GeneratedDocument::query()->count())->toBe(0);
});

test('permiso: PDF original como fondo (mismo tamaño y número de páginas), datos encima, firmas vacías y check del tipo correcto', function () {
    mfImportar(['formato_permiso.general']);
    $colaborador = mfColaborador('gestor');
    $solicitud = SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => 'llegada_tarde', 'estado' => 'aprobada',
        'fecha_inicio' => '2026-10-06', 'fecha_fin' => '2026-10-06', 'dias_solicitados' => 1, 'motivo' => 'Trámite personal',
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/solicitud/{$solicitud->id}/generar", ['clave' => 'formato_permiso'])->assertCreated();
    $documento = GeneratedDocument::query()->firstOrFail();
    $tamanos = function (string $pdf): array {
        $ruta = tempnam(sys_get_temp_dir(), 'mfp');
        file_put_contents($ruta, $pdf);
        $fpdi = new Fpdi;
        $total = $fpdi->setSourceFile($ruta);
        $paginas = [];

        for ($i = 1; $i <= $total; $i++) {
            $t = $fpdi->getTemplateSize($fpdi->importPage($i));
            $paginas[] = [round((float) $t['width'], 1), round((float) $t['height'], 1)];
        }

        @unlink($ruta);

        return $paginas;
    };

    expect($tamanos((string) Storage::disk('nas')->get($documento->path)))->toBe($tamanos(mfOriginalDe('formato_permiso.general')))
        ->and($documento->conversion_engine)->toBe('overlay')
        ->and($documento->payload['marca_llegar_tarde'])->toBe('X')
        ->and($documento->payload['marca_salir'] ?? '')->toBe('')
        ->and($documento->payload['marca_faltar'] ?? '')->toBe('')
        ->and(array_keys($documento->payload))->not->toContain('firma_colaborador');
});

test('permiso: el formato no se genera antes de que la solicitud esté aprobada; un motivo que no cabe bloquea con DOCUMENT_FIELD_OVERFLOW', function () {
    mfImportar(['formato_permiso.general']);
    $colaborador = mfColaborador('gestor');
    $solicitud = SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => 'permiso_sin_goce', 'estado' => 'en_revision',
        'fecha_inicio' => '2026-10-06', 'fecha_fin' => '2026-10-06', 'dias_solicitados' => 1, 'motivo' => str_repeat('Motivo extenso que no cabe en dos renglones del formato oficial. ', 12),
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/solicitud/{$solicitud->id}/generar", ['clave' => 'formato_permiso'])->assertUnprocessable();

    $solicitud->update(['estado' => 'aprobada']);
    $this->postJson("/api/v1/rh/documentos-proceso/solicitud/{$solicitud->id}/generar", ['clave' => 'formato_permiso'])
        ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_FIELD_OVERFLOW');
});

test('activación segura: no con QA pendiente o fallido, no bloqueado, no sin conversor; excepción solo super_admin con motivo (auditada)', function () {
    config(['documentos_maestros.validacion_visual.al_importar' => false]);
    mfImportar(['contrato_no_competencia.gestor', 'contrato_confidencialidad.gerente']);
    $importador = app(ImportadorFormatosJuridicosService::class);
    $master = DocumentTemplate::query()->where('familia', 'contrato_no_competencia.gestor')->firstOrFail();

    // Sin QA: no se activa al importar ni a mano.
    expect($master->activo)->toBeFalse()
        ->and(array_column($importador->bloqueosActivacion($master), 'clave'))->toBe(['qa'])
        ->and(fn () => $importador->activar($master, $this->rh))->toThrow(ValidationException::class);

    // Diseño fallido: tampoco.
    $master->forceFill(['visual_validation_status' => EstadoValidacionVisual::Fallida, 'visual_checked_at' => now(), 'visual_report' => ['problemas' => ['Página 2 no coincide']]])->save();
    expect(fn () => $importador->activar($master->refresh(), $this->rh, 'Motivo suficientemente largo para la excepción'))->toThrow(ValidationException::class);

    // Bloqueado por el registro jurídico: nunca, ni por excepción.
    $borrador = DocumentTemplate::query()->where('familia', 'contrato_confidencialidad.gerente')->where('version', 2)->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    expect(collect($importador->bloqueosActivacion($borrador))->firstWhere('clave', 'bloqueado'))->not->toBeNull()
        ->and(fn () => $importador->activar($borrador, $superAdmin, 'Motivo suficientemente largo para la excepción'))->toThrow(ValidationException::class);

    // Sin conversor fiel en el servidor: bloqueo no excepcionable.
    app()->instance(ConversorDocxPdf::class, new ConversorDocxPdf(app(FormatoPreviewService::class)));
    expect(collect(app(ImportadorFormatosJuridicosService::class)->bloqueosActivacion($master->refresh()))->firstWhere('clave', 'conversor'))->not->toBeNull();
    app()->instance(ConversorDocxPdf::class, $this->motorFiel['conversor']);
    $importador = app(ImportadorFormatosJuridicosService::class);

    // Excepción: rh_admin no puede; super_admin con motivo sí, auditado.
    expect(fn () => $importador->activar($master->refresh(), $this->rh, 'Motivo suficientemente largo para la excepción'))->toThrow(ValidationException::class);
    $activado = $importador->activar($master->refresh(), $superAdmin, 'Jurídico autorizó usar esta versión mientras se instala la fuente.');
    expect($activado->activo)->toBeTrue()
        ->and($activado->activacion_excepcional_motivo)->toContain('Jurídico autorizó')
        ->and($activado->activado_por)->toBe($superAdmin->id)
        ->and(collect(app(AuditoriaService::class)->historial($activado))->pluck('accion'))->toContain('documento_maestro_activado_excepcion');
});

test('histórico y reingreso: un documento de la v1 sigue idéntico al activar la v2; lo nuevo usa la versión vigente', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $v1 = GeneratedDocument::query()->firstOrFail();
    $bytesV1 = (string) Storage::disk('nas')->get($v1->path);
    $payloadV1 = $v1->payload;

    // RH carga la versión 2 (mismo original de Jurídico como v2) y la activa.
    $importador = app(ImportadorFormatosJuridicosService::class);
    $v2 = $importador->registrarVersion('contrato_capacitacion.gestor', 2, mfOriginalDe('contrato_capacitacion.gestor'), ['v2.docx'], ['familia' => 'contrato_capacitacion.gestor', 'version' => 2, 'activa' => false, 'bloqueada' => false, 'nota' => null], $this->rh);
    $importador->activar($v2, $this->rh);

    // Cambian sueldo y empresa; el documento emitido no cambia.
    $colaborador->update(['sueldo_mensual' => 21000]);
    $colaborador->sucursalPrincipal->empresa->update(['representante_legal_nombre' => 'OTRA PERSONA']);

    // Reingreso: nuevo contrato → master vigente (v2).
    $reingreso = mfContrato($colaborador->refresh());
    $contrato->update(['estado' => 'terminado']);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$reingreso->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();

    expect($v1->refresh()->master_version)->toBe(1)
        ->and($v1->payload)->toBe($payloadV1)
        ->and((string) Storage::disk('nas')->get($v1->path))->toBe($bytesV1)
        ->and(GeneratedDocument::query()->where('documentable_id', $reingreso->id)->value('master_version'))->toBe(2);

    $this->get("/api/v1/rh/documentos-laborales/{$v1->id}/descargar")->assertOk();
});

test('firmado: no se regenera encima; la nueva revisión exige permiso y motivo, y el firmado se conserva', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $firmado = GeneratedDocument::query()->firstOrFail();
    $firmado->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);

    $item = collect($this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->json('data.0.documentos'))->firstWhere('clave', 'contrato_capacitacion');
    expect(array_column($item['acciones'], 'clave'))->toContain('nueva_revision')->not->toContain('regenerar');

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true])->assertUnprocessable();
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'revision' => true])->assertUnprocessable();

    $auxiliar = clUsuario('rh_auxiliar');
    Sanctum::actingAs($auxiliar);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'revision' => true, 'motivo' => 'Corrección del domicilio de la sucursal'])->assertForbidden();

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'revision' => true, 'motivo' => 'Corrección del domicilio de la sucursal'])->assertCreated();
    $revision = GeneratedDocument::query()->latest('id')->firstOrFail();

    expect($revision->revision_de_id)->toBe($firmado->id)
        ->and($revision->motivo_revision)->toBe('Corrección del domicilio de la sucursal')
        ->and($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente)
        ->and(Storage::disk('nas')->exists($firmado->path))->toBeTrue();
});

test('Word del documento: RH lo descarga; el colaborador titular y alguien fuera de alcance no', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $documento = GeneratedDocument::query()->firstOrFail();

    $this->get("/api/v1/rh/documentos-proceso/documento/{$documento->id}/word")->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $titular = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $titular->assignRole('colaborador');
    Sanctum::actingAs($titular);
    $this->get("/api/v1/rh/documentos-proceso/documento/{$documento->id}/word")->assertForbidden();

    $ajeno = clUsuario('gerente_sucursal');
    Sanctum::actingAs($ajeno);
    $this->get("/api/v1/rh/documentos-proceso/documento/{$documento->id}/word")->assertForbidden();
});

test('línea de tiempo y siguiente acción las decide el backend: Imprimir → Registrar firma', function () {
    mfImportar(['contrato_capacitacion.gestor']);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $item = fn () => collect($this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->json('data.0.documentos'))->firstWhere('clave', 'contrato_capacitacion');

    expect($item()['siguiente_accion']['clave'])->toBe('marcar_impreso')
        ->and($item()['master']['etiqueta'])->toBe('Gestor v1')
        ->and(collect($item()['linea_tiempo'])->pluck('estado', 'clave')->all())->toMatchArray(['generado' => 'hecho', 'impreso' => 'actual', 'firmado' => 'pendiente'])
        ->and(collect($item()['acciones'])->where('tipo', 'primaria')->count())->toBe(1);

    $documento = GeneratedDocument::query()->firstOrFail();
    $this->postJson("/api/v1/rh/documentos-proceso/documento/{$documento->id}/imprimir")->assertOk();

    expect($item()['siguiente_accion']['clave'])->toBe('registrar_firma')
        ->and($item()['estado_etiqueta'])->toBe('Firma pendiente');
});

test('puesto que no requiere documentos laborales: la contratación no pide ninguno (decisión explícita con motivo)', function () {
    mfImportar(['contrato_confidencialidad.general']);
    $colaborador = mfColaborador('gestor');
    $colaborador->puesto->update(['grupo_documental' => null, 'no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'Asesor externo por honorarios']);
    mfContrato($colaborador);

    $seccion = $this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->json('data.0');
    expect($seccion['documentos'])->toBe([])
        ->and($seccion['descripcion'])->toContain('Asesor externo por honorarios');
});

test('préstamo: se usa el monto y plazo AUTORIZADOS, nunca los solicitados', function () {
    mfImportar(['prestamo_contrato.general', 'prestamo_pagare.general', 'prestamo_consentimiento_retencion.general']);
    $colaborador = mfColaborador('gestor');
    $prestamo = Prestamo::factory()->create([
        'colaborador_id' => $colaborador->id, 'monto_solicitado' => 9000, 'plazo_solicitado' => 30, 'monto_original' => 5000, 'saldo' => 5000,
        'plazo' => 20, 'periodicidad' => 'semanal', 'pago_programado' => 250, 'fecha_primer_descuento' => '2026-10-11', 'autorizado_en' => now(), 'autorizado_por' => $this->rh->id, 'fecha_solicitud' => '2026-10-01',
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/prestamo/{$prestamo->id}/paquete", ['proceso' => 'prestamo'])->assertCreated();
    $pagare = GeneratedDocument::query()->where('clave_plantilla', 'prestamo_pagare')->firstOrFail();

    expect(GeneratedDocument::query()->pluck('clave_plantilla')->sort()->values()->all())->toBe(['prestamo_consentimiento_retencion', 'prestamo_contrato', 'prestamo_pagare'])
        ->and($pagare->payload['monto_prestamo_numero'])->toContain('5,000.00')
        ->and($pagare->payload['plazo_prestamo'])->toBe('20');
});

test('evaluación ACREDITA: el formato de NO acreditación no se genera; NO ACREDITA lo marca (sin resultado fijo en el documento)', function () {
    mfImportar(['evaluacion_capacitacion.general']);
    $colaborador = mfColaborador('gestor');
    $contrato = mfContrato($colaborador);
    $evaluacion = EvaluacionPeriodoPrueba::query()->create([
        'colaborador_id' => $colaborador->id, 'contrato_laboral_id' => $contrato->id, 'estado' => EstadoEvaluacionPrueba::Autorizada,
        'resultado' => ResultadoEvaluacion::Aprobado, 'capturada_por' => $this->rh->id, 'capturada_en' => now(), 'autorizada_por' => $this->rh->id, 'autorizada_en' => now(),
        'criterios' => collect(config('contratos.criterios_evaluacion'))->map(fn (string $c) => ['criterio' => $c, 'calificacion' => 9])->all(), 'decision_renovar' => false,
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/evaluacion/{$evaluacion->id}/generar", ['clave' => 'evaluacion_capacitacion', 'proceso' => 'evaluacion'])->assertUnprocessable();

    // NO acredita: sin cierre aún, se explica (no un 422 de dato faltante críptico).
    $evaluacion->update(['resultado' => ResultadoEvaluacion::NoAprobado]);
    $this->postJson("/api/v1/rh/documentos-proceso/evaluacion/{$evaluacion->id}/generar", ['clave' => 'evaluacion_capacitacion', 'proceso' => 'evaluacion'])
        ->assertUnprocessable()->assertJsonPath('errors.documento.0', 'Se genera con la no renovación: primero se abre el cierre laboral.');

    CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'evaluacion_id' => $evaluacion->id, 'tipo_baja' => TipoBaja::NoRenovacion, 'motivo' => 'No acredita',
        'fecha_efectiva' => '2026-12-04', 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);
    $this->postJson("/api/v1/rh/documentos-proceso/evaluacion/{$evaluacion->id}/generar", ['clave' => 'evaluacion_capacitacion', 'proceso' => 'evaluacion'])->assertCreated();

    expect(GeneratedDocument::query()->value('payload')['resultado_no_acredita'])->toBe('☒')
        ->and(GeneratedDocument::query()->value('payload')['resultado_acredita'])->toBe('☐');
});

test('acta de negativa: solo después de registrar la negativa y con dos testigos (nombre y cargo); firmas en blanco', function () {
    mfImportar(['acta_negativa_firma.general']);
    $colaborador = mfColaborador('gestor');
    mfContrato($colaborador);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::NoRenovacion, 'motivo' => 'No acredita', 'fecha_efectiva' => '2026-12-04',
        'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'acta_negativa_firma', 'proceso' => 'negativa_firma'])->assertUnprocessable();

    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/procedimiento/negativa", [
        'documentos' => ['aviso_terminacion'], 'finiquito_a_disposicion' => true, 'testigos' => [['nombre' => 'Ana Ruiz', 'cargo' => 'Cajera']],
    ])->assertOk();
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'acta_negativa_firma', 'proceso' => 'negativa_firma'])
        ->assertUnprocessable()->assertJsonPath('errors.documento.0', 'Captura los dos testigos antes de generar el acta.');

    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/procedimiento/testigos", [
        'testigos' => [['nombre' => 'Ana Ruiz', 'cargo' => 'Cajera'], ['nombre' => 'Luis Mora', 'cargo' => 'Gestor']],
        'participantes' => ['rh_cargo' => 'Analista de RH', 'jefe_nombre' => 'Pedro Soto', 'jefe_cargo' => 'Gerente de Sucursal', 'hora_acta' => '10:30'],
    ])->assertOk();
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'acta_negativa_firma', 'proceso' => 'negativa_firma'])->assertCreated();

    expect(GeneratedDocument::query()->value('payload'))->toHaveKeys(['testigo_1_nombre', 'testigo_2_cargo'])
        ->and(GeneratedDocument::query()->value('requiere_firma_fisica'))->toBeTrue();
});

test('colaborador dado de baja (soft delete): su historial documental sigue abriendo sin error', function () {
    mfImportar(['carta_renuncia.general']);
    $colaborador = mfColaborador('gestor');
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria', 'fecha_efectiva' => '2026-10-10',
        'estado' => EstadoCierreLaboral::Solicitado, 'iniciado_por' => $this->rh->id,
    ]);
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia'])->assertCreated();
    $colaborador->delete();

    $this->getJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}")->assertOk()->assertJsonPath('data.colaborador.dado_de_baja', true);
    $this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->assertOk();
    $this->get('/api/v1/rh/documentos-laborales/'.GeneratedDocument::query()->value('id').'/descargar')->assertOk();
});
