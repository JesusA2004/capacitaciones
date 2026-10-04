<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
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
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\DocumentosMaestros\ResolvedorMaestroService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Motor de documentos maestros de punta a punta con los ORIGINALES reales de
| Jurídico (docs/formatos_fuente/). Esos binarios no viven en el repositorio:
| si la carpeta no está, la prueba se omite. La conversión usa el motor
| PhpWord (rápido; fidelidad "aproximada") — la fiel (LibreOffice/Word) se
| verifica en el smoke manual.
*/

function dmFuente(): string
{
    return base_path('docs/formatos_fuente');
}

function dmColaborador(string $grupo, array $estructura, array $extra = []): Colaborador
{
    $puesto = Puesto::factory()->create(['nombre' => 'Puesto '.$grupo, 'grupo_documental' => $grupo, 'meses_periodo_prueba' => $grupo === 'gestor' ? 2 : 3]);

    return Colaborador::factory()->create([
        'name' => 'Juan',
        'apellidos' => 'Pérez López',
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
        'fecha_ingreso' => '2026-10-05',
        'sueldo_mensual' => 15000,
        ...$extra,
    ]);
}

function dmContrato(Colaborador $colaborador, ?ContratoLaboral $anterior = null): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => $anterior ? TipoContratacion::Indeterminado : TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => $anterior ? '2026-12-05' : '2026-10-05',
        'fecha_fin' => $anterior ? null : '2026-12-04',
        'estado' => 'vigente',
        'sueldo_mensual' => 15000,
        'puesto_id' => $colaborador->puesto_id,
        'sucursal_id' => $colaborador->sucursal_principal_id,
        'contrato_anterior_id' => $anterior?->id,
    ]);
}

beforeEach(function () {
    if (! is_dir(dmFuente()) || glob(dmFuente().'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    config(['formatos_oficiales.conversor' => 'phpword']);

    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(dmFuente());

    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);
    Sanctum::actingAs($this->rh);
});

test('importador idempotente: originales por hash, masters listos y el borrador de Gerente bloqueado', function () {
    $antes = DocumentTemplate::query()->whereNotNull('estado_master')->count();
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(dmFuente());

    expect(DocumentTemplate::query()->whereNotNull('estado_master')->count())->toBe($antes)
        ->and(DocumentTemplate::query()->where('familia', 'contrato_confidencialidad.gerente')->where('version', 2)->value('estado_master'))->toBe('bloqueado')
        ->and(DocumentTemplate::query()->where('familia', 'contrato_capacitacion.gerente')->where('activo', true)->value('version'))->toBe(2)
        ->and(DocumentTemplate::query()->where('familia', 'procedimiento_baja.referencia')->value('activo'))->toBeFalse();
});

test('alta: cada puesto recibe su propia variante de contrato (gestor, gerente, subgerente, regional)', function (string $grupo, array $familias) {
    $colaborador = dmColaborador($grupo, $this->estructura);
    $contrato = dmContrato($colaborador);

    $seccion = $this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->assertOk()->json('data.0');
    expect($seccion['proceso'])->toBe('alta')
        ->and(array_column(array_column($seccion['documentos'], 'master'), 'familia'))->toBe($familias);

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/paquete", ['proceso' => 'alta'])->assertCreated();

    $generados = GeneratedDocument::query()->where('documentable_id', $contrato->id)->where('documentable_type', $contrato->getMorphClass())->orderBy('id')->get();
    expect($generados->pluck('master_familia')->all())->toBe($familias)
        ->and($generados->first()->payload['nombre_completo_mayusculas'])->toBe('JUAN PÉREZ LÓPEZ')
        ->and($generados->first()->master_hash)->not->toBeNull()
        ->and($generados->first()->original_name)->toStartWith('EMP_');
})->with([
    'gestor' => ['gestor', ['contrato_capacitacion.gestor', 'contrato_confidencialidad.gestor', 'contrato_no_competencia.gestor']],
    'gerente' => ['gerente', ['contrato_capacitacion.gerente', 'contrato_confidencialidad.gerente', 'contrato_no_competencia.gerente']],
    'subgerente' => ['subgerente', ['contrato_capacitacion.subgerente', 'contrato_confidencialidad.subgerente']],
    'regional' => ['regional', ['contrato_capacitacion.regional', 'contrato_confidencialidad.general']],
]);

test('confidencialidad: un puesto sin convenio propio (Sistemas, Dirección Comercial…) firma el general', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $colaborador->puesto->update(['nombre' => 'Sistemas', 'grupo_documental' => null]);

    $master = app(ResolvedorMaestroService::class)->resolver('contrato_confidencialidad', $colaborador->refresh(), 'alta');

    expect($master->familia)->toBe('contrato_confidencialidad.general');
});

test('confidencialidad de Gerente: la jurisdicción que viene en blanco en el original se imprime en blanco', function () {
    $colaborador = dmColaborador('gerente', $this->estructura);
    $contrato = dmContrato($colaborador);

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_confidencialidad', 'proceso' => 'alta'])->assertCreated();

    $documento = GeneratedDocument::query()->where('master_familia', 'contrato_confidencialidad.gerente')->latest('id')->firstOrFail();
    expect($documento->docx_path)->not->toBeNull();

    $temporal = tempnam(sys_get_temp_dir(), 'dm');
    file_put_contents($temporal, Storage::disk($documento->disk)->get((string) $documento->docx_path));
    $zip = new ZipArchive;
    $zip->open($temporal);
    $texto = strip_tags((string) $zip->getFromName('word/document.xml'));
    $zip->close();
    @unlink($temporal);

    expect($texto)->toMatch('/leyes del estado de _{5,}/')
        ->and($texto)->toMatch('/tribunales de la ciudad de _{5,}/')
        ->and($texto)->toContain('JUAN PÉREZ LÓPEZ');
});

test('datos faltantes: 422 DATOS_FALTANTES y se completan en la ficha sin volver a pedirlos', function () {
    $colaborador = dmColaborador('gestor', $this->estructura, ['nacionalidad' => null, 'estado_civil' => null]);
    $contrato = dmContrato($colaborador);

    $respuesta = $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()->assertJsonPath('code', 'DATOS_FALTANTES');
    expect(array_column($respuesta->json('faltantes'), 'columna'))->toContain('nacionalidad', 'estado_civil');

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", [
        'clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'completar' => ['nacionalidad' => 'Mexicana', 'estado_civil' => 'casado'],
    ])->assertCreated();

    expect($colaborador->refresh()->nacionalidad)->toBe('Mexicana');
});

test('formato faltante: 422 DOCUMENT_TEMPLATE_MISSING, nunca otro formato en silencio', function () {
    DocumentTemplate::query()->where('familia', 'contrato_capacitacion.subgerente')->update(['activo' => false]);
    $colaborador = dmColaborador('subgerente', $this->estructura);
    $contrato = dmContrato($colaborador);

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_TEMPLATE_MISSING');
});

test('renovación: contrato indeterminado del puesto; el snapshot no cambia si cambia el sueldo', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $nuevo = dmContrato($colaborador, dmContrato($colaborador));

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$nuevo->id}/generar", ['clave' => 'contrato_indeterminado', 'proceso' => 'renovacion'])->assertCreated();
    $documento = GeneratedDocument::query()->where('clave_plantilla', 'contrato_indeterminado')->firstOrFail();
    $colaborador->update(['sueldo_mensual' => 18000]);

    expect($documento->master_familia)->toBe('contrato_indeterminado.gestor')
        ->and($documento->refresh()->payload['sueldo_mensual_numero'])->toBe('15,000.00');
});

test('permiso aprobado: formato PDF original MR. LANA', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $solicitud = SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => 'permiso_sin_goce', 'estado' => 'aprobada',
        'fecha_inicio' => '2026-10-06', 'fecha_fin' => '2026-10-06', 'dias_solicitados' => 1, 'motivo' => 'Trámite personal',
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/solicitud/{$solicitud->id}/generar", ['clave' => 'formato_permiso'])->assertCreated();
    $documento = GeneratedDocument::query()->where('clave_plantilla', 'formato_permiso')->firstOrFail();

    expect($documento->master_familia)->toBe('formato_permiso.general')
        ->and($documento->solicitud_id)->toBe($solicitud->id)
        ->and($documento->payload['marca_descuento_nomina'])->toBe('X');
});

test('renuncia: el gerente la genera desde el cierre con el formato oficial', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => '2026-10-10', 'estado' => EstadoCierreLaboral::Solicitado, 'iniciado_por' => $this->rh->id,
    ]);

    $seccion = $this->getJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}")->assertOk()->json('data');
    expect(array_column($seccion['documentos'], 'clave'))->toBe(['carta_renuncia', 'finiquito']);

    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia'])->assertCreated();
    expect(GeneratedDocument::query()->where('clave_plantilla', 'carta_renuncia')->value('master_familia'))->toBe('carta_renuncia.general');
});

test('no renovación: evaluación + aviso; negativa de firma habilita el acta con dos testigos', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $contrato = dmContrato($colaborador);
    $evaluacion = EvaluacionPeriodoPrueba::query()->create([
        'colaborador_id' => $colaborador->id, 'contrato_laboral_id' => $contrato->id, 'estado' => EstadoEvaluacionPrueba::Autorizada,
        'resultado' => ResultadoEvaluacion::NoAprobado, 'capturada_por' => $this->rh->id, 'capturada_en' => now(), 'autorizada_por' => $this->rh->id, 'autorizada_en' => now(),
        'criterios' => collect(config('contratos.criterios_evaluacion'))->map(fn (string $c) => ['criterio' => $c, 'calificacion' => 5])->all(),
        'decision_renovar' => false,
    ]);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'evaluacion_id' => $evaluacion->id, 'tipo_baja' => TipoBaja::NoRenovacion, 'motivo' => 'No acredita',
        'fecha_efectiva' => '2026-12-04', 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/paquete", ['proceso' => 'baja'])->assertCreated();
    $aviso = GeneratedDocument::query()->where('clave_plantilla', 'aviso_terminacion')->firstOrFail();
    $eval = GeneratedDocument::query()->where('clave_plantilla', 'evaluacion_capacitacion')->firstOrFail();
    expect($aviso->payload['fecha_documento_larga'])->toBe('4 de diciembre de 2026')
        ->and($aviso->payload['duracion_letra'])->toBe('dos meses')
        ->and($eval->payload['resultado_no_acredita'])->toBe('☒');

    // El acta NO existe hasta registrar la negativa.
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'acta_negativa_firma'])->assertUnprocessable();

    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/procedimiento/negativa", [
        'documentos' => ['evaluacion_capacitacion', 'aviso_terminacion'], 'finiquito_a_disposicion' => true,
        'testigos' => [['nombre' => 'Ana Ruiz', 'cargo' => 'Cajera'], ['nombre' => 'Luis Mora', 'cargo' => 'Gestor']],
        'participantes' => ['rh_cargo' => 'Analista de RH', 'jefe_nombre' => 'Pedro Soto', 'jefe_cargo' => 'Gerente de Sucursal', 'hora_acta' => '10:30'],
    ])->assertOk();

    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'acta_negativa_firma', 'proceso' => 'negativa_firma'])->assertCreated();
    $acta = GeneratedDocument::query()->where('clave_plantilla', 'acta_negativa_firma')->firstOrFail();
    expect($acta->payload['testigo_1_nombre'])->toBe('ANA RUIZ')
        ->and($acta->requiere_testigos)->toBeTrue();
});

test('préstamo autorizado: contrato, pagaré y carta de retención del formato para trabajadores', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $prestamo = Prestamo::factory()->create([
        'colaborador_id' => $colaborador->id, 'monto_original' => 5000, 'saldo' => 5000, 'plazo' => 20, 'periodicidad' => 'semanal',
        'pago_programado' => 250, 'fecha_primer_descuento' => '2026-10-11', 'autorizado_en' => now(), 'autorizado_por' => $this->rh->id, 'fecha_solicitud' => '2026-10-01',
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/prestamo/{$prestamo->id}/paquete", ['proceso' => 'prestamo'])->assertCreated();

    expect($prestamo->refresh()->contrato_documento_id)->not->toBeNull()
        ->and($prestamo->pagare_documento_id)->not->toBeNull()
        ->and(GeneratedDocument::query()->where('clave_plantilla', 'prestamo_pagare')->value('payload')['ultimo_pago_mes'])->toBe('febrero');
});

test('fuera de alcance: un colaborador no ve ni genera documentos de otra persona', function () {
    $colaborador = dmColaborador('gestor', $this->estructura);
    $contrato = dmContrato($colaborador);
    $otro = clUsuario('colaborador');
    Sanctum::actingAs($otro);

    $this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$colaborador->id}")->assertForbidden();
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/paquete", ['proceso' => 'alta'])->assertForbidden();
});
