<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoBaja;
use App\Enums\TipoContratacion;
use App\Enums\TipoSolicitudInterna;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Regla: todo formato que se genera para un colaborador se puede VER antes
| de generarlo. La vista previa responde el PDF real (inline, no-store) y NO
| escribe nada: ni GeneratedDocument, ni archivo en el expediente, ni datos
| de la ficha, ni el registro del flujo (cierre, contrato, préstamo) cuando
| se previsualiza antes de confirmar. Usa los originales reales de Jurídico
| (docs/formatos_fuente): sin ellos, se omite.
*/

function vpColaborador(array $estructura, array $extra = []): Colaborador
{
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 2]);

    return clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Adrián',
        'apellidos' => 'Díaz Mena',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'masculino',
        'fecha_nacimiento' => '1990-05-10',
        'curp' => 'DIMA900510HHGZNR01',
        'rfc' => 'DIMA900510AB1',
        'nss' => '12345678901',
        'telefono' => '7731234567',
        'correo_personal' => 'adrian@example.com',
        'domicilio' => 'Calle Hidalgo 5, Col. Centro, Tula, Hidalgo',
        'domicilio_colonia' => 'Centro',
        'domicilio_municipio' => 'Tula de Allende',
        'domicilio_estado' => 'Hidalgo',
        'domicilio_cp' => '42800',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Tula, Hidalgo',
        'clave_elector' => 'DIMA900510HHG',
        'profesion' => 'Licenciado',
        'fecha_ingreso' => '2026-10-05',
        'sueldo_mensual' => 9000,
        'beneficiario_nombre' => 'María Mena Ruiz',
        'beneficiario_parentesco' => 'Madre',
        ...$extra,
    ]));
}

function vpContratoCapacitacion(Colaborador $colaborador): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-12-04',
        'estado' => 'vigente',
        'sueldo_mensual' => 9000,
        'puesto_id' => $colaborador->puesto_id,
        'sucursal_id' => $colaborador->sucursal_principal_id,
    ]);
}

function vpEvaluacionCapturada(Colaborador $colaborador, ContratoLaboral $contrato, ResultadoEvaluacion $resultado, int $rhId): EvaluacionPeriodoPrueba
{
    return EvaluacionPeriodoPrueba::query()->create([
        'colaborador_id' => $colaborador->id, 'contrato_laboral_id' => $contrato->id, 'estado' => EstadoEvaluacionPrueba::Capturada,
        'resultado' => $resultado, 'capturada_por' => $rhId, 'capturada_en' => now(),
        'criterios' => collect(config('contratos.criterios_evaluacion'))->map(fn (string $c) => ['criterio' => $c, 'calificacion' => 5])->all(),
        'recomienda_renovar' => $resultado === ResultadoEvaluacion::Aprobado,
    ]);
}

/** PDF inline, sin caché, y la base/almacenamiento intactos. */
function vpEsPdfInline(TestResponse $respuesta): void
{
    $respuesta->assertOk();

    expect($respuesta->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
}

function vpSinEscrituras(): void
{
    // Solo el expediente de la persona de la prueba: el disco falso vive en
    // storage/framework/testing y otras corridas en paralelo escriben ahí.
    $propios = array_filter(
        Storage::disk((string) config('expedientes.disk'))->allFiles('expedientes'),
        fn (string $ruta): bool => str_contains($ruta, 'Díaz Mena'),
    );

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and(array_values($propios))->toBe([]);
}

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    // Disco falso PROPIO de esta corrida: Storage::fake('nas') vive en
    // storage/framework/testing/disks/nas y otra corrida en paralelo lo vacía
    // (se llevaba los masters importados a media prueba).
    $this->disco = sprintf('nas_vista_previa_%d', getmypid());
    config(['expedientes.disk' => $this->disco, 'documentos_maestros.disk' => $this->disco]);
    Storage::fake($this->disco);
    Notification::fake();
    $this->motorFiel = dmMotorFielDePrueba();
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));

    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Juárez 10', 'ciudad' => 'Tula de Allende', 'estado' => 'Hidalgo', 'codigo_postal' => '42800']);
    Sanctum::actingAs($this->rh);
});

test('documento del proceso: «Vista previa» junto a «Generar» responde el PDF inline y no guarda nada', function () {
    $colaborador = vpColaborador($this->estructura);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);

    $item = collect($this->getJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}")->assertOk()->json('data.documentos'))->firstWhere('clave', 'carta_renuncia');
    expect(array_column($item['acciones'], 'clave'))->toBe(['vista_previa', 'generar']);

    // API móvil y web: mismo controlador y mismo service.
    vpEsPdfInline($this->get("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/vista-previa?clave=carta_renuncia"));
    vpEsPdfInline($this->actingAs($this->rh)->get("/rh/documentos-proceso/cierre/{$cierre->id}/vista-previa?clave=carta_renuncia"));
    vpSinEscrituras();
});

test('modal de datos faltantes: «Previsualizar» usa lo capturado sin guardarlo en la ficha', function () {
    $this->estructura['sucursal']->update(['codigo_postal' => null]);
    $colaborador = vpColaborador($this->estructura);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);
    $url = "/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/vista-previa?clave=carta_renuncia";

    $sinDato = $this->get($url);
    vpEsPdfInline($sinDato);
    expect((int) $sinDato->headers->get('X-Datos-Faltantes'))->toBeGreaterThan(0);

    $conDato = $this->get($url.'&'.http_build_query(['completar' => ['sucursal.codigo_postal' => '42800']]));
    vpEsPdfInline($conDato);
    expect((int) $conDato->headers->get('X-Datos-Faltantes'))->toBe(0)
        ->and($this->estructura['sucursal']->refresh()->codigo_postal)->toBeNull();
    vpSinEscrituras();
});

test('sin alcance ni permiso: la vista previa responde 403 (documento y flujo)', function () {
    $colaborador = vpColaborador($this->estructura);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $this->rh->id, 'autorizado_rh_en' => now(),
    ]);
    Sanctum::actingAs(clUsuario('colaborador'));

    $this->getJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/vista-previa?clave=carta_renuncia")->assertForbidden();
    $this->getJson("/api/v1/rh/documentos-proceso/previa/baja/{$colaborador->id}/pdf?clave=carta_renuncia&tipo_baja=renuncia")->assertForbidden();
    vpSinEscrituras();
});

test('baja antes de confirmar: ve la renuncia sin crear cierre, trámite ni documento', function () {
    $colaborador = vpColaborador($this->estructura);
    $consulta = http_build_query(['tipo_baja' => 'renuncia', 'fecha_efectiva' => now()->toDateString(), 'motivo' => 'Renuncia voluntaria']);

    $documentos = $this->getJson("/api/v1/rh/documentos-proceso/previa/baja/{$colaborador->id}?{$consulta}")->assertOk()->json('data');
    expect(array_column($documentos, 'clave'))->toBe(['carta_renuncia']);

    vpEsPdfInline($this->get($documentos[0]['url']));
    expect(CierreLaboral::query()->count())->toBe(0)
        ->and(SolicitudInterna::query()->count())->toBe(0);
    vpSinEscrituras();
});

test('renovación antes de autorizar: contrato indeterminado en memoria, el vigente no cambia', function () {
    $colaborador = vpColaborador($this->estructura);
    $contrato = vpContratoCapacitacion($colaborador);
    $evaluacion = vpEvaluacionCapturada($colaborador, $contrato, ResultadoEvaluacion::Aprobado, $this->rh->id);

    $documentos = $this->getJson("/api/v1/rh/documentos-proceso/previa/renovacion/{$evaluacion->id}")->assertOk()->json('data');
    expect(array_column($documentos, 'clave'))->toContain('contrato_indeterminado');

    vpEsPdfInline($this->get(collect($documentos)->firstWhere('clave', 'contrato_indeterminado')['url']));
    expect(ContratoLaboral::query()->count())->toBe(1)
        ->and($contrato->refresh()->estado->value)->toBe('vigente')
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionPrueba::Capturada);
    vpSinEscrituras();
});

test('no renovación antes de autorizar: aviso de terminación y formato NO acredita sin abrir el cierre', function () {
    $colaborador = vpColaborador($this->estructura);
    $contrato = vpContratoCapacitacion($colaborador);
    $evaluacion = vpEvaluacionCapturada($colaborador, $contrato, ResultadoEvaluacion::NoAprobado, $this->rh->id);

    $documentos = $this->getJson("/api/v1/rh/documentos-proceso/previa/no_renovacion/{$evaluacion->id}?fecha_efectiva=2026-12-04")->assertOk()->json('data');
    expect(array_column($documentos, 'clave'))->toBe(['evaluacion_capacitacion', 'aviso_terminacion']);

    foreach ($documentos as $documento) {
        vpEsPdfInline($this->get($documento['url']));
    }

    expect(CierreLaboral::query()->count())->toBe(0)
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionPrueba::Capturada);
    vpSinEscrituras();
});

test('préstamo antes de autorizar: contrato de crédito, pagaré y retención sin crear el préstamo', function () {
    $colaborador = vpColaborador($this->estructura, ['jefe_id' => Colaborador::factory()->create(['name' => 'Laura', 'apellidos' => 'Méndez Ruiz'])->id]);
    $solicitud = SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => TipoSolicitudInterna::PrestamoInterno->value, 'estado' => 'enviada',
        'monto_solicitado' => 5000, 'plazo_meses' => 5, 'motivo' => 'Gastos médicos',
    ]);
    $consulta = http_build_query(['monto_autorizado' => 5000, 'plazo_autorizado' => 20, 'periodicidad' => 'semanal']);

    $documentos = $this->getJson("/api/v1/rh/documentos-proceso/previa/prestamo/{$solicitud->id}?{$consulta}")->assertOk()->json('data');
    expect(array_column($documentos, 'clave'))->toBe(['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion']);

    vpEsPdfInline($this->get($documentos[1]['url']));
    expect(Prestamo::query()->count())->toBe(0)
        ->and($solicitud->refresh()->estado->value)->toBe('enviada');
    vpSinEscrituras();

    // Un documento que el flujo no genera no se previsualiza por esta vía.
    $this->getJson("/api/v1/rh/documentos-proceso/previa/prestamo/{$solicitud->id}/pdf?clave=carta_renuncia&{$consulta}")->assertUnprocessable();
});

afterEach(function () {
    if (isset($this->disco)) {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/'.$this->disco));
    }
});
