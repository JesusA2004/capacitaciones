<?php

use App\Enums\EstadoAgendaEvento;
use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\TipoAgendaEvento;
use App\Enums\TipoCambioSalario;
use App\Enums\TipoSolicitudInterna;
use App\Models\AgendaEvento;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\NominaRectificacion;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\Bajas\BajaDocumentoSalidaService;
use App\Services\Bajas\BajaDocumentosService;
use App\Services\Bajas\BajaService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Flujos\Dto\SiguientePaso;
use App\Services\Flujos\SiguientePasoService;
use App\Services\Reclutamiento\CadenaContratacionService;
use App\Services\Salarios\TabuladorSalarialService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| «Siguiente paso» (docs/SIGUIENTE_PASO.md): por flujo, el mapa estado → acción;
| por rol, acción solo con permiso y alcance; cada href se pide de verdad y nunca
| da 403; y nada ofrece avanzar con requisitos faltantes.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->sinRelacion = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
    ]);
    $this->servicio = app(SiguientePasoService::class);
});

function spCandidato(EstadoCandidato $estado, array $extra = []): Candidato
{
    return Candidato::factory()->create([
        'estado' => $estado->value,
        'sucursal_id' => test()->sucursal->id,
        'puesto_objetivo_id' => test()->estructura['puesto']->id,
        ...$extra,
    ]);
}

/** Sigue cada enlace como la propia persona: ninguno puede terminar en 403. */
function spSinProhibidos(SiguientePaso $paso, User $usuario): void
{
    $hrefs = array_filter([$paso->accion?->href, ...array_map(fn ($e) => $e->href, $paso->relacionadas)]);

    foreach ($hrefs as $href) {
        $respuesta = test()->actingAs($usuario)->get(explode('#', $href)[0]);

        expect($respuesta->getStatusCode())->not->toBe(403, sprintf('%s dio 403 a %s', $href, $usuario->getRoleNames()->first()))
            ->and($respuesta->getStatusCode())->toBeLessThan(500, sprintf('%s falló', $href));
    }
}

function spBaja(array $extra = []): Baja
{
    $causal = CausalRotacion::query()->where('clave', 'RV-01')->first() ?? CausalRotacion::query()->firstOrFail();

    return app(BajaService::class)->registrar([
        'colaborador_id' => test()->persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'La persona presentó su renuncia por escrito con su jefe.',
        ...$extra,
    ], test()->rh);
}

function spRectificacion(): void
{
    $recibo = ReciboNomina::factory()->create(['colaborador_id' => test()->persona->id]);
    NominaRectificacion::query()->forceCreate([
        'colaborador_id' => test()->persona->id, 'recibo_nomina_id' => $recibo->id, 'estado' => 'pendiente', 'motivo' => 'prueba',
        'dias_pagados_antes' => 15, 'dias_pagados_despues' => 14, 'importe_sueldo_despues' => 5600,
        'neto_antes' => 6000,
    ]);
}

/** Baja con el panel y los documentos en un estado conocido, sin plantillas. */
function spServicioConPanel(array $generado, array $faltantes = [], ?array $documento = ['clave' => 'carta_renuncia', 'etiqueta' => 'Carta de renuncia']): SiguientePasoService
{
    test()->instance(BajaDocumentoSalidaService::class, Mockery::mock(BajaDocumentoSalidaService::class, fn ($m) => $m->shouldReceive('panel')->andReturn([
        'documento' => $documento, 'generado' => $generado === [] ? null : $generado, 'faltantes' => [],
    ])));
    test()->instance(BajaDocumentosService::class, Mockery::mock(BajaDocumentosService::class, fn ($m) => $m->shouldReceive('faltantes')->andReturn($faltantes)));

    return app(SiguientePasoService::class);
}

// ───────────── Flujo A ─────────────

it('A: mapa de estado a botón para Recursos Humanos', function (EstadoCandidato $estado, string $clave, string $etiqueta) {
    $candidato = spCandidato($estado);
    $paso = $this->servicio->paraCandidato($candidato, $this->rh);

    expect($paso->accion?->clave)->toBe($clave)
        ->and($paso->accion?->etiqueta)->toBe($etiqueta)
        ->and($paso->modo->value)->toBe('lineal');
    spSinProhibidos($paso, $this->rh);
})->with([
    'recibido' => [EstadoCandidato::Recibidos, 'evaluar_perfil', 'Revisar perfil'],
    'sin cita' => [EstadoCandidato::EntrevistaPendiente, 'agendar_entrevista', 'Agendar entrevista'],
    'psicométricas' => [EstadoCandidato::PsicometricasPendientes, 'registrar_resultados_psicometricas', 'Continuar evaluación'],
    'referencias' => [EstadoCandidato::ReferenciasPendientes, 'concluir_referencias', 'Continuar evaluación'],
    'autorizado' => [EstadoCandidato::AutorizadoRh, 'iniciar_contratacion', 'Iniciar contratación'],
]);

it('A: con cita programada, quien aprueba registra el resultado y la agenda abre', function () {
    $candidato = spCandidato(EstadoCandidato::EntrevistaPendiente);
    AgendaEvento::query()->create([
        'candidato_id' => $candidato->id, 'sucursal_id' => $this->sucursal->id, 'tipo' => TipoAgendaEvento::Entrevista->value,
        'fecha_hora' => now()->addDay(), 'estado' => EstadoAgendaEvento::Programado->value, 'origen' => 'reclutamiento', 'agendado_por' => $this->rh->id,
    ]);

    $paso = $this->servicio->paraCandidato($candidato, $this->gerente);

    expect($paso->accion?->etiqueta)->toBe('Registrar resultado')->and($paso->accion?->href)->toContain('evento=');
    spSinProhibidos($paso, $this->gerente);
});

it('A: ?candidato= precarga la agenda solo si es agendable y visible', function () {
    $candidato = spCandidato(EstadoCandidato::EntrevistaPendiente);

    $this->actingAs($this->rh)->get(route('agenda.index', ['candidato' => $candidato->id]))
        ->assertInertia(fn ($p) => $p->where('candidatoInicial.id', $candidato->id));

    $ajeno = spCandidato(EstadoCandidato::Contratado);
    $this->actingAs($this->rh)->get(route('agenda.index', ['candidato' => $ajeno->id]))
        ->assertInertia(fn ($p) => $p->where('candidatoInicial', null));
});

it('A: sin permiso no hay acción ni href, y no se revela un paso ajeno como propio', function () {
    $candidato = spCandidato(EstadoCandidato::PsicometricasPendientes);
    $paso = $this->servicio->paraCandidato($candidato, $this->sinRelacion);

    expect($paso->accion)->toBeNull()->and($paso->tipo->value)->toBe('espera');
    spSinProhibidos($paso, $this->sinRelacion);
});

it('A4: cada estado de contratación tiene botón con destino real y no se adelanta', function (EstadoAltaColaborador $alta, string $clave, string $etiqueta) {
    $colaborador = clColaboradorEnContratacion(['estado_alta' => $alta->value, 'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->estructura['puesto']->id]);
    $paso = $this->servicio->paraColaborador($colaborador, $this->rh);

    expect($paso->accion?->clave)->toBe($clave)->and($paso->accion?->etiqueta)->toBe($etiqueta)->and($paso->accion?->href)->not->toBeNull();
    spSinProhibidos($paso, $this->rh);

    // Con documentos pendientes nadie ve «Generar contrato».
    if ($alta === EstadoAltaColaborador::PendienteDocumentos) {
        expect(collect($paso->relacionadas)->pluck('etiqueta'))->not->toContain('Generar contrato');
    }
})->with([
    'documentos' => [EstadoAltaColaborador::PendienteDocumentos, 'completar_expediente', 'Completar expediente'],
    'revisión' => [EstadoAltaColaborador::DocumentacionEnRevision, 'revisar_documentos', 'Revisar documentos'],
    'contrato' => [EstadoAltaColaborador::PendienteContrato, 'generar_contratos', 'Generar contrato'],
    'firma' => [EstadoAltaColaborador::PendienteFirma, 'firmar_contratos', 'Registrar firma de contratos'],
]);

it('A5: candidato contratado, cadena y siguiente paso dicen lo mismo', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estado_alta' => EstadoAltaColaborador::EnOnboarding->value, 'estatus' => EstadoUsuario::Activo]);
    $candidato = spCandidato(EstadoCandidato::Contratado, ['colaborador_id' => $colaborador->id]);
    $colaborador->update(['candidato_id' => $candidato->id]);

    $cadena = app(CadenaContratacionService::class)->paraCandidato($candidato, $this->rh);
    $paso = $this->servicio->paraCandidato($candidato, $this->rh);

    expect($cadena['detalle'])->toBe('Falta presentarle la inducción')
        ->and($paso->accion?->etiqueta)->toBe('Iniciar inducción')
        ->and($paso->mensaje)->toBe('Falta presentarle la inducción.');
    spSinProhibidos($paso, $this->rh);
});

it('A3: la cadena usa ?accion= y ya no ?paso=', function () {
    $candidato = spCandidato(EstadoCandidato::AutorizadoRh);

    expect(app(CadenaContratacionService::class)->paraCandidato($candidato, $this->rh)['proceso_url'])
        ->toContain('accion=iniciar_contratacion')->not->toContain('paso=');
});

it('A6: activar por web exige permiso y que todo esté completo', function () {
    $colaborador = clColaboradorEnContratacion(['estado_alta' => EstadoAltaColaborador::PendienteActivacion->value, 'sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($this->sinRelacion)->post(route('rh.colaboradores.activar', $colaborador))->assertForbidden();
    // Sin expediente ni contratos, el servicio lo rechaza: no se salta requisitos.
    $this->actingAs($this->rh)->post(route('rh.colaboradores.activar', $colaborador))->assertSessionHasErrors('colaborador');

    expect($colaborador->refresh()->estado_alta)->not->toBe(EstadoAltaColaborador::Activo);
});

// ───────────── Flujo B ─────────────

it('B: expediente sin pendientes es hub terminado con enlaces que abren', function () {
    $paso = $this->servicio->paraExpediente($this->persona, $this->rh);

    expect($paso->modo->value)->toBe('hub')->and($paso->tipo->value)->toBe('terminado')->and($paso->pasos)->toBe([])
        ->and(collect($paso->relacionadas)->pluck('etiqueta')->all())->toBe(['Registrar incidencia', 'Ver impacto en nómina'])
        ->and(count($paso->relacionadas))->toBeLessThanOrEqual(SiguientePaso::MAX_RELACIONADAS);
    spSinProhibidos($paso, $this->rh);
});

it('B: una rectificación pendiente pasa a ser la acción', function () {
    spRectificacion();

    $paso = $this->servicio->paraExpediente($this->persona, $this->rh);

    expect($paso->accion?->clave)->toBe('resolver_rectificacion')->and($paso->accion?->etiqueta)->toBe('Ver impacto en nómina');
    spSinProhibidos($paso, $this->rh);
});

it('B: nómina de una persona: revisar conceptos en borrador y descargar PDF ya emitido', function () {
    $borrador = ReciboNomina::factory()->make(['colaborador_id' => $this->persona->id, 'estado' => 'borrador']);
    $paso = $this->servicio->paraNomina($this->persona, $this->rh, $borrador);
    expect($paso->accion?->etiqueta)->toBe('Revisar conceptos')->and($paso->accion?->modo->value)->toBe('dialogo');

    $sinPermiso = $this->servicio->paraNomina($this->persona, $this->gerente, $borrador);
    expect($sinPermiso->accion)->toBeNull();

    $emitido = ReciboNomina::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => 'emitido', 'pdf_path' => 'recibos/uno.pdf']);
    expect($this->servicio->paraNomina($this->persona, $this->rh, $emitido)->accion?->etiqueta)->toBe('Descargar PDF');
});

it('B1: el directorio solo manda expediente_url a quien lo alcanza', function () {
    $this->actingAs($this->rh)->get(route('directorio.index'))
        ->assertInertia(fn ($p) => $p->where('colaboradores', fn ($filas) => collect($filas)->firstWhere('id', $this->persona->id)['expediente_url'] !== null));

    $this->actingAs($this->sinRelacion)->get(route('directorio.index'))
        ->assertInertia(fn ($p) => $p->where('colaboradores', fn ($filas) => collect($filas)->firstWhere('id', $this->persona->id)['expediente_url'] === null));
});

it('B: ?colaborador= no amplía el alcance en nómina ni incapacidades', function () {
    $otra = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => null]);

    expect(app(ConstructorEnlaces::class)->nomina($otra, $ajeno))->toBeNull()
        ->and(app(ConstructorEnlaces::class)->historialIncidencias($otra, $ajeno))->toBeNull();
});

it('desde: lista blanca y sin redirección abierta', function () {
    $enlaces = app(ConstructorEnlaces::class);
    $pide = fn (array $q) => Request::create('/x', 'GET', $q);

    expect($enlaces->regreso($pide(['desde' => 'expediente', 'desde_id' => $this->persona->id]), $this->rh)['href'])->toBe(route('rh.expedientes.show', $this->persona->id, false))
        ->and($enlaces->regreso($pide(['desde' => 'https://malo.example', 'desde_id' => 1]), $this->rh))->toBeNull()
        ->and($enlaces->regreso($pide(['desde' => '//malo.example', 'desde_id' => 1]), $this->rh))->toBeNull()
        ->and($enlaces->regreso($pide(['desde' => 'expediente', 'desde_id' => $this->persona->id]), $this->sinRelacion))->toBeNull();
});

// ───────────── Flujo C ─────────────

it('C: el orden no se salta: primero el documento, después el finiquito, y nunca cierra con faltantes', function () {
    $baja = spBaja();
    $paso = $this->servicio->paraBaja($baja, $this->rh);

    expect($paso->modo->value)->toBe('lineal')
        ->and($paso->accion?->clave)->not->toBe('cerrar_baja')
        ->and(collect($paso->pasos)->pluck('clave')->all())->not->toContain('rotacion')
        ->and(collect($paso->relacionadas)->pluck('etiqueta'))->toContain('Ver en rotación')
        ->and(collect($paso->relacionadas)->firstWhere('etiqueta', 'Ver en rotación')?->href)->toContain('vista=por_sucursal');
    spSinProhibidos($paso, $this->rh);
});

it('C: mapa del documento de salida', function (array $generado, string $clave, string $etiqueta) {
    $baja = spBaja();
    $paso = spServicioConPanel($generado)->paraBaja($baja, $this->rh);

    expect($paso->accion?->clave)->toBe($clave)->and($paso->accion?->etiqueta)->toBe($etiqueta);
})->with([
    'sin generar' => [[], 'generar_documento', 'Generar documento de salida'],
    'cambió' => [['desactualizado' => true, 'firmado' => false], 'corregir_documento', 'Corregir documento'],
    'sin firma' => [['desactualizado' => false, 'firmado' => false], 'registrar_firmado', 'Registrar documento firmado'],
]);

it('C: firmado y sin faltantes sigue el finiquito y al final cerrar; con faltantes no cierra', function () {
    $baja = spBaja();
    $cierres = app(CierreLaboralService::class);
    $finiquito = $cierres->finiquito($baja->cierre) ?? $cierres->calcularFiniquito($baja->cierre, $this->rh, 12000.0);
    $firmado = ['desactualizado' => false, 'firmado' => true];

    $finiquito->forceFill(['estado' => EstadoFiniquito::Borrador])->save();
    expect(spServicioConPanel($firmado)->paraBaja($baja->refresh(), $this->rh)->accion?->etiqueta)->toBe('Revisar finiquito');

    $finiquito->forceFill(['estado' => EstadoFiniquito::Pagado])->save();
    expect(spServicioConPanel($firmado)->paraBaja($baja->refresh(), $this->rh)->accion?->etiqueta)->toBe('Cerrar baja');

    $conFaltantes = spServicioConPanel($firmado, ['Carta firmada'])->paraBaja($baja->refresh(), $this->rh);
    expect($conFaltantes->accion?->clave)->toBe('adjuntar_documentos');

    $sinPermiso = spServicioConPanel($firmado, ['Carta firmada'])->paraBaja($baja->refresh(), $this->sinRelacion);
    expect($sinPermiso->accion)->toBeNull();
});

it('C: PDF de finiquito que ya no corresponde pide recalcular', function () {
    $baja = spBaja();
    $cierres = app(CierreLaboralService::class);
    $finiquito = $cierres->finiquito($baja->cierre) ?? $cierres->calcularFiniquito($baja->cierre, $this->rh, 12000.0);
    $documento = GeneratedDocument::factory()->create(['colaborador_id' => $this->persona->id, 'huella_datos' => null]);
    $finiquito->forceFill(['generated_document_id' => $documento->id, 'documento_generado_path' => 'x.pdf', 'snapshot' => ['neto' => 1], 'estado' => EstadoFiniquito::Revisado])->save();

    expect(spServicioConPanel(['desactualizado' => false, 'firmado' => true])->paraBaja($baja->refresh(), $this->rh)->accion?->etiqueta)->toBe('Recalcular finiquito');
});

it('C: fecha futura espera aunque todo lo demás esté listo', function () {
    $baja = spBaja();
    $cierres = app(CierreLaboralService::class);
    $finiquito = $cierres->finiquito($baja->cierre) ?? $cierres->calcularFiniquito($baja->cierre, $this->rh, 12000.0);
    $finiquito->forceFill(['estado' => EstadoFiniquito::Pagado])->save();
    $baja->forceFill(['fecha_baja' => now()->addDays(5)->toDateString()])->save();

    $paso = spServicioConPanel(['desactualizado' => false, 'firmado' => true])->paraBaja($baja->refresh(), $this->rh);

    expect($paso->tipo->value)->toBe('espera')->and($paso->accion)->toBeNull();
});

it('C: cerrada con el cierre ejecutado ofrece concluir el cierre laboral sin fusionarlos', function () {
    $baja = spBaja();
    $baja->update(['estatus' => 'cerrada']);
    $baja->cierre?->update(['estado' => 'baja_ejecutada']);

    $paso = $this->servicio->paraBaja($baja->refresh(), $this->rh);

    expect($paso->accion?->etiqueta)->toBe('Concluir cierre laboral')->and($paso->accion?->href)->toContain('accion=cerrar');
    spSinProhibidos($paso, $this->rh);
});

it('C: quien solo consulta espera, sin href ajeno', function () {
    $paso = $this->servicio->paraBaja(spBaja(), $this->gerente);

    expect($paso->accion?->clave)->not->toBe('generar_documento');
    spSinProhibidos($paso, $this->gerente);
});

it('C: Bajas/Show lleva el panel y la API conserva el contrato', function () {
    $baja = spBaja();

    $this->actingAs($this->rh)->get(route('rh.bajas.show', $baja))
        ->assertInertia(fn ($p) => $p->has('siguientePaso.tipo')->has('siguientePaso.pasos'));

    Sanctum::actingAs($this->rh);
    $this->getJson(route('api.v1.rh.bajas.show', $baja))->assertOk()->assertJsonPath('data.siguiente_paso.flujo', 'baja');
});

it('API: candidato y ciclo tienen el mismo tipo y clave que la web, y conservan siguiente_accion', function () {
    $candidato = spCandidato(EstadoCandidato::AutorizadoRh);
    Sanctum::actingAs($this->rh);

    $this->getJson(route('api.v1.rh.candidatos.show', $candidato))->assertOk()
        ->assertJsonPath('data.siguiente_paso.accion.clave', 'iniciar_contratacion')
        ->assertJsonPath('data.siguiente_paso.tipo', 'accion')
        ->assertJsonPath('data.ciclo.siguiente_accion.clave', 'iniciar_contratacion');

    $this->getJson(route('api.v1.rh.colaboradores.ciclo', $this->persona))->assertOk()
        ->assertJsonPath('data.siguiente_paso.flujo', 'contratacion')
        ->assertJsonStructure(['data' => ['ciclo' => ['siguiente_accion']]]);
});

// ───────────── Flujo D ─────────────

it('D: sin permiso de sueldo no hay panel ni pista', function () {
    expect($this->servicio->paraSueldo($this->persona, $this->sinRelacion))->toBeNull();
});

it('D: sin efectos ofrece el historial y enlaces; sin sueldo pide capturarlo', function () {
    $paso = $this->servicio->paraSueldo($this->persona, $this->rh);

    expect($paso?->modo->value)->toBe('hub')
        ->and($paso?->accion?->etiqueta)->toBe('Ver historial salarial')
        ->and(collect($paso?->relacionadas)->pluck('etiqueta')->all())->toBe(['Corregir sueldo', 'Exportar reporte']);
    spSinProhibidos($paso, $this->rh);

    $this->persona->update(['sueldo_mensual' => null, 'salario_diario' => null]);
    expect($this->servicio->paraSueldo($this->persona->refresh(), $this->rh)?->accion?->etiqueta)->toBe('Capturar sueldo');
});

it('D: una rectificación pendiente lleva a revisar la nómina', function () {
    spRectificacion();

    $paso = $this->servicio->paraSueldo($this->persona, $this->rh);

    expect($paso?->accion?->etiqueta)->toBe('Revisar nómina');
    spSinProhibidos($paso, $this->rh);
});

it('D: tabulador y sueldo individual no se actualizan mutuamente', function () {
    $puesto = $this->estructura['puesto'];

    app(TabuladorSalarialService::class)->guardar($puesto, ['minimo' => 9000, 'medio' => 10000, 'maximo' => 11000, 'fuente' => 'prueba', 'fecha_actualizacion' => now()->toDateString()], $this->rh);
    expect((float) $this->persona->refresh()->sueldo_mensual)->toBe(12000.0);

    $vigente = TabuladorSalarial::query()->where('puesto_id', $puesto->id)->whereNull('vigente_hasta')->firstOrFail();
    app(CompensacionService::class)->cambiarSueldo($this->persona, 15000.0, TipoCambioSalario::Aumento, 'Aumento de prueba', $this->rh);

    expect((float) $vigente->refresh()->medio)->toBe(10000.0)
        ->and(TabuladorSalarial::query()->where('puesto_id', $puesto->id)->count())->toBe(1);
});

// ───────────── Bloque 2B ─────────────

it('registrarEntrega rechaza un PDF de finiquito que ya no corresponde', function () {
    $baja = spBaja();
    $cierres = app(CierreLaboralService::class);
    $finiquito = $cierres->finiquito($baja->cierre) ?? $cierres->calcularFiniquito($baja->cierre, $this->rh, 12000.0);
    $documento = GeneratedDocument::factory()->create(['colaborador_id' => $this->persona->id, 'huella_datos' => null]);
    $finiquito->forceFill(['generated_document_id' => $documento->id, 'documento_generado_path' => 'x.pdf', 'snapshot' => ['neto' => 1]])->save();

    expect(fn () => app(FiniquitoService::class)->registrarEntrega($finiquito->refresh(), $this->rh, Carbon::now()))
        ->toThrow(ValidationException::class);
    expect($finiquito->refresh()->estado)->not->toBe(EstadoFiniquito::Pagado);
});

it('registrarEntrega sin PDF generado sigue permitida', function () {
    $baja = spBaja();
    $cierres = app(CierreLaboralService::class);
    $finiquito = $cierres->finiquito($baja->cierre) ?? $cierres->calcularFiniquito($baja->cierre, $this->rh, 12000.0);
    $finiquito->forceFill(['generated_document_id' => null, 'documento_generado_path' => null])->save();

    expect(app(FiniquitoService::class)->registrarEntrega($finiquito->refresh(), $this->rh, Carbon::now())->estado)->toBe(EstadoFiniquito::Pagado);
});

it('solicitud: el botón de generar permiso solo aparece si falta el formato', function () {
    $solicitud = SolicitudInterna::factory()->create(['colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Permiso->value, 'estado' => EstadoSolicitudInterna::Aprobada->value, 'revisado_por' => $this->rh->id]);

    $this->actingAs($this->rh)->get(route('rh.solicitudes.show', $solicitud))
        ->assertInertia(fn ($p) => $p->where('puedeGenerarPermiso', true)->where('siguientePaso.accion.etiqueta', 'Abrir expediente'));

    $this->actingAs($this->gerente)->get(route('rh.solicitudes.show', $solicitud))
        ->assertInertia(fn ($p) => $p->where('puedeGenerarPermiso', false));
});

it('C: el historial de versiones se consulta aunque falte un dato para emitir', function () {
    $baja = spBaja();

    $this->actingAs($this->rh)
        ->getJson(route('rh.documentos-edicion.versiones', ['tipo' => 'salida', 'id' => $baja->id]))
        ->assertOk()
        ->assertJsonStructure(['versiones']);
});
