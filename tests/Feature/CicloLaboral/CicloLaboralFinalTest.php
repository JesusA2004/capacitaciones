<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAprobacion;
use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoReingreso;
use App\Enums\EstadoUsuario;
use App\Enums\EtapaAprobacion;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoContratacion;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\Aprobacion;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\IncorporacionInvitacion;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Puesto;
use App\Models\Reingreso;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\TipoActivo;
use App\Models\User;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\ReingresoService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Colaboradores\IdentidadColaboradorService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\VencimientoContratosService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use App\Services\Tareas\TareaService;
use Database\Seeders\PuestoJerarquiaSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Escenarios de negocio del cierre definitivo del ciclo laboral
| (docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md). Todo avanza por los services
| públicos: si un paso necesitara tocar la BD a mano, el sistema no estaría
| terminado.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->puesto = $this->estructura['puesto'];
    $this->puesto->update(['meses_periodo_prueba' => 2]);
    $this->sucursalA = $this->estructura['sucursal'];
    $this->sucursalB = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'nombre' => 'Sucursal B']);

    $this->rh = clUsuario('rh_admin');
    $this->rh2 = clUsuario('rh_admin');
    $this->reclutador = clUsuario('rh_auxiliar');
    $this->regional = clUsuario('gerente_regional', ['sucursal_principal_id' => $this->sucursalA->id]);
    $this->gerenteA = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursalA->id, 'jefe_id' => $this->regional->colaborador_id]);
    $this->gerenteB = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursalB->id]);
    $this->coordinadora = clUsuario('coordinadora_regional', ['sucursal_principal_id' => $this->sucursalA->id]);

    // Catálogo documental (configurable): dos obligatorios.
    $this->tipos = collect([
        DocumentType::factory()->create(['clave' => 'ine', 'nombre' => 'Identificación oficial', 'requerido' => true, 'activo' => true]),
        DocumentType::factory()->create(['clave' => 'comprobante_domicilio', 'nombre' => 'Comprobante de domicilio', 'requerido' => true, 'activo' => true, 'vigencia_meses' => 3]),
    ]);

    // Plantillas de Jurídico/RH (el sistema nunca inventa el texto).
    $flujoFisico = ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true];

    foreach (['contrato_periodo_prueba', 'contrato_confidencialidad', 'contrato_no_competencia', 'contrato_indeterminado'] as $clave) {
        clPlantilla($clave, $flujoFisico);
    }

    clPlantilla('carta_responsiva');
    clPlantilla('aviso_no_renovacion');
    clPlantilla('evaluacion_periodo_prueba');
    clPlantilla('carta_renuncia');

    // Onboarding: institucional + puesto, mínimo 8.
    $preguntas = [
        ['pregunta' => '¿Horario de cobranza?', 'opciones' => ['9 a 18', '6 a 10'], 'correcta' => 0],
        ['pregunta' => '¿Uso de casco?', 'opciones' => ['Opcional', 'Obligatorio'], 'correcta' => 1],
    ];
    OnboardingModulo::query()->create(['titulo' => 'Bienvenida MR. LANA', 'tipo' => TipoModuloOnboarding::Institucional, 'orden' => 1, 'contenido_url' => 'https://videos.example.test/bienvenida', 'preguntas' => $preguntas, 'calificacion_minima' => 8]);
    OnboardingModulo::query()->create(['titulo' => 'Ruta y cobranza', 'tipo' => TipoModuloOnboarding::Puesto, 'puesto_id' => $this->puesto->id, 'orden' => 1, 'preguntas' => $preguntas, 'calificacion_minima' => 8]);

    TipoActivo::query()->create(['clave' => 'uniforme', 'nombre' => 'Uniforme', 'obligatorio' => true]);
    TipoActivo::query()->create(['clave' => 'casco', 'nombre' => 'Casco', 'requiere_identificador' => true, 'obligatorio' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function cfRegistrarCandidato(object $t, array $extra = []): Candidato
{
    return app(CandidatoWorkflowService::class)->registrar([
        'empresa_id' => $t->estructura['empresa']->id,
        'sucursal_id' => $t->sucursalA->id,
        'departamento_id' => $t->estructura['departamento']->id,
        'puesto_objetivo_id' => $t->puesto->id,
        'nombre' => 'Ana',
        'apellidos' => 'López Prueba',
        'telefono' => '5512345678',
        'correo' => 'ana.lopez@example.test',
        'fuente' => 'facebook_grupos',
        'gerente_involucrado_id' => $t->gerenteA->id,
        ...$extra,
    ], $t->reclutador);
}

/**
 * Lleva al candidato por TODO el reclutamiento hasta la preautorización del
 * gerente (queda pendiente la autorización final de RH).
 */
function cfHastaPreautorizado(object $t, Candidato $c): Candidato
{
    $wf = app(CandidatoWorkflowService::class);
    $wf->evaluarPerfil($c, $t->reclutador, true, 'Cubre perfil.');
    $wf->registrarEntrevista($c, $t->gerenteA, ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Buena entrevista.']);
    $wf->enviarPsicometricas($c, $t->reclutador, 'https://pruebas.example.test/1');
    $wf->registrarResultadosPsicometricas($c, $t->reclutador, 'Resultados en perfil.');
    $wf->revisarPsicometricas($c, $t->gerenteA, true, null);
    $wf->registrarSocioeconomico($c, $t->gerenteA, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable', 'checklist' => ['vivienda_en_orden' => true]]);
    $wf->registrarReferencia($c, $t->reclutador, ['empresa' => 'Empresa X', 'contacto' => 'Jefe X', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $t->reclutador, true, null);

    return $wf->preautorizar($c, $t->gerenteA, 'Lo recomiendo.');
}

/**
 * @return array{colaborador: Colaborador, token: string, usuario: User}
 */
function cfContratarYRegistrar(object $t, Candidato $c): array
{
    app(CandidatoWorkflowService::class)->autorizarRh($c, $t->rh, 'Autorizado.');
    $resultado = app(ContratacionCandidatoService::class)->iniciarContratacion($c->refresh(), [
        'sueldo_mensual' => 9500,
        'fecha_ingreso' => now()->toDateString(),
        'jefe_id' => $t->gerenteA->colaborador_id,
    ], $t->rh);

    $respuesta = test()->postJson("/api/v1/incorporacion/invitaciones/{$resultado['token']}/registrar", [
        'name' => 'Ana',
        'apellidos' => 'López Prueba',
        'email' => 'ana.lopez@example.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
        'curp' => 'LOPA900101MDFPRN09',
    ])->assertCreated();

    expect($respuesta->json('siguiente_paso'))->toBe('incorporacion');

    return [
        'colaborador' => $resultado['colaborador']->refresh(),
        'token' => $resultado['token'],
        'usuario' => User::query()->where('email', 'ana.lopez@example.test')->firstOrFail(),
    ];
}

function cfExpedienteAprobado(object $t, Colaborador $colaborador, User $usuario): void
{
    $incorporacion = app(IncorporacionService::class);

    foreach ($t->tipos as $tipo) {
        $incorporacion->subirDocumento($colaborador, $tipo, clArchivoPdf("{$tipo->clave}.pdf"), $usuario->id);
    }

    test()->actingAs($t->rh);

    foreach (EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->get() as $documento) {
        $incorporacion->aprobarDocumento($documento, $t->rh, null);
    }

    $incorporacion->aprobarIncorporacion($colaborador->refresh(), $t->rh);
}

function cfFirmarContratos(object $t, Colaborador $colaborador): void
{
    test()->actingAs($t->gerenteA);
    $flujo = app(FlujoDocumentalService::class);

    foreach (GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->get() as $documento) {
        $flujo->marcarImpreso($documento, $t->gerenteA);
        $flujo->registrarFirmaFisica($documento->refresh(), $t->gerenteA, ['huella_registrada' => true]);
    }
}

function cfCompletarOnboarding(object $t, Colaborador $colaborador, User $usuario): OnboardingProceso
{
    $onboarding = app(OnboardingService::class);
    $proceso = $onboarding->procesoActual($colaborador);

    foreach ($proceso->avances()->get() as $avance) {
        $onboarding->presentarEvaluacion($avance, $usuario, [0 => 0, 1 => 1]);
    }

    test()->actingAs($t->gerenteA);
    $onboarding->entregarActivo($proceso->refresh(), $t->gerenteA, ['tipo_activo_id' => TipoActivo::query()->where('clave', 'uniforme')->value('id')]);
    $onboarding->entregarActivo($proceso->refresh(), $t->gerenteA, ['tipo_activo_id' => TipoActivo::query()->where('clave', 'casco')->value('id'), 'identificador' => 'CASCO-001']);

    return $onboarding->completar($proceso->refresh(), $t->gerenteA);
}

test('E2E: candidato → contratación → onboarding → periodo de prueba → RH renueva a indeterminado', function () {
    // --- Etapa 1 ---
    $candidato = cfRegistrarCandidato($this);
    expect(TareaRh::query()->where('candidato_id', $candidato->id)->whereNull('resuelta_en')->value('tipo'))->toBe(TipoTarea::CandidatoRevisionPerfil);

    $candidato = cfHastaPreautorizado($this, $candidato);
    expect($candidato->estado)->toBe(EstadoCandidato::AutorizacionRhPendiente);

    // Preautorizar NO es autorizar: aún no se puede contratar.
    expect(fn () => app(ContratacionCandidatoService::class)->iniciarContratacion($candidato, ['sueldo_mensual' => 1, 'fecha_ingreso' => now()->toDateString()], $this->rh))
        ->toThrow(ValidationException::class);

    // --- Etapa 2 ---
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);

    expect(Colaborador::query()->where('candidato_id', $candidato->id)->count())->toBe(1)
        ->and($colaborador->candidato_id)->toBe($candidato->id)
        ->and($usuario->colaborador_id)->toBe($colaborador->id)
        ->and($candidato->refresh()->estado)->toBe(EstadoCandidato::EnContratacion)
        ->and($colaborador->estado_alta)->toBe(EstadoAltaColaborador::PendienteDocumentos)
        ->and($colaborador->periodo_prueba_fin?->toDateString())->toBe(now()->addMonthsNoOverflow(2)->subDay()->toDateString())
        // Los contratos NO existen hasta que el expediente esté completo.
        ->and(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);

    cfExpedienteAprobado($this, $colaborador, $usuario);
    $colaborador->refresh();

    expect($colaborador->estado_alta)->toBe(EstadoAltaColaborador::PendienteFirma)
        ->and($colaborador->estatus)->toBe(EstadoUsuario::EnIncorporacion)
        ->and(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->pluck('clave_plantilla')->sort()->values()->all())
        ->toBe(['contrato_confidencialidad', 'contrato_no_competencia', 'contrato_periodo_prueba']);

    cfFirmarContratos($this, $colaborador);
    $colaborador->refresh();

    // --- Etapa 3 ---
    expect($colaborador->estado_alta)->toBe(EstadoAltaColaborador::EnOnboarding)
        ->and($candidato->refresh()->estado)->toBe(EstadoCandidato::Contratado)
        ->and(OnboardingProceso::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);

    $onboarding = app(OnboardingService::class);
    $proceso = $onboarding->procesoActual($colaborador);
    [$institucional, $puesto] = $proceso->avances()->get()->all();

    // Calificación 5 (< 8): no avanza, tarea para RH.
    $intento = $onboarding->presentarEvaluacion($institucional, $usuario, [0 => 1, 1 => 1]);
    expect((float) $intento->calificacion)->toBe(5.0)
        ->and($institucional->refresh()->estado)->toBe(EstadoAvanceOnboarding::RequiereRefuerzo)
        ->and($puesto->refresh()->estado)->toBe(EstadoAvanceOnboarding::Bloqueado)
        ->and(TareaRh::query()->where('tipo', TipoTarea::OnboardingRefuerzo->value)->whereNull('resuelta_en')->count())->toBe(1);

    expect(fn () => $onboarding->presentarEvaluacion($institucional->refresh(), $usuario, [0 => 0, 1 => 1]))->toThrow(ValidationException::class);

    $onboarding->retroalimentar($institucional, $this->rh, 'Repasa el reglamento de seguridad.');
    $onboarding->presentarEvaluacion($institucional->refresh(), $usuario, [0 => 0, 1 => 1]);

    expect($institucional->refresh()->estado)->toBe(EstadoAvanceOnboarding::Aprobado)
        ->and($institucional->intentos()->count())->toBe(2)
        ->and($puesto->refresh()->estado)->toBe(EstadoAvanceOnboarding::Disponible);

    $onboarding->presentarEvaluacion($puesto, $usuario, [0 => 0, 1 => 1]);
    expect($proceso->refresh()->estado)->toBe(EstadoOnboarding::EntregaActivos);

    $this->actingAs($this->gerenteA);
    $onboarding->entregarActivo($proceso, $this->gerenteA, ['tipo_activo_id' => TipoActivo::query()->where('clave', 'uniforme')->value('id')]);
    expect(fn () => $onboarding->completar($proceso->refresh(), $this->gerenteA))->toThrow(ValidationException::class);
    $onboarding->entregarActivo($proceso->refresh(), $this->gerenteA, ['tipo_activo_id' => TipoActivo::query()->where('clave', 'casco')->value('id'), 'identificador' => 'CASCO-001']);
    $onboarding->completar($proceso->refresh(), $this->gerenteA);

    $colaborador->refresh();
    expect($colaborador->estatus)->toBe(EstadoUsuario::Activo)
        ->and($colaborador->estado_alta)->toBe(EstadoAltaColaborador::Activo)
        ->and(GeneratedDocument::query()->where('clave_plantilla', 'carta_responsiva')->where('colaborador_id', $colaborador->id)->count())->toBe(2);

    // --- Etapa 4: 15 días antes del vencimiento, una sola vez ---
    $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoContratoLaboral::Vigente->value)->firstOrFail();
    Carbon::setTestNow($contrato->fecha_fin->copy()->subDays(15)->setTime(9, 0));

    app(VencimientoContratosService::class)->revisar();
    app(VencimientoContratosService::class)->revisar();

    expect(EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->count())->toBe(1)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->whereNull('resuelta_en')->count())->toBe(1);

    $evaluacion = EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->firstOrFail();
    $evaluaciones = app(EvaluacionPeriodoPruebaService::class);
    $evaluaciones->capturar($evaluacion, $this->gerenteA, [
        'criterios' => [['criterio' => 'Cobranza', 'calificacion' => 9]],
        'recomienda_renovar' => true,
    ]);

    // La recomendación es solo preautorización.
    expect(ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(Aprobacion::query()->where('aprobable_id', $evaluacion->id)->where('etapa', EtapaAprobacion::Preautorizacion->value)->value('estado'))->toBe(EstadoAprobacion::Aprobado);

    // Quien preautorizó no da la autorización final.
    $this->gerenteA->givePermissionTo(['evaluaciones.autorizar', 'ciclo.autorizar_rh']);
    expect(fn () => $evaluaciones->autorizar($evaluacion->refresh(), $this->gerenteA, ['renovar' => true]))->toThrow(ValidationException::class);

    $this->actingAs($this->rh);
    $evaluaciones->autorizar($evaluacion->refresh(), $this->rh, ['renovar' => true]);

    $indeterminado = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoContratoLaboral::Vigente->value)->firstOrFail();
    expect($indeterminado->tipo)->toBe(TipoContratacion::Indeterminado)
        ->and($contrato->refresh()->estado)->toBe(EstadoContratoLaboral::Renovado)
        ->and(GeneratedDocument::query()->where('documentable_id', $indeterminado->id)->where('clave_plantilla', 'contrato_indeterminado')->count())->toBe(1);

    // La ficha responde dónde está y qué pasó.
    $estado = app(CicloLaboralService::class)->obtenerEstado($colaborador->refresh(), $this->rh);
    $eventos = collect($estado['timeline'])->pluck('evento');

    expect($estado['etapa']['clave'])->toBe('activo')
        ->and($eventos)->toContain('aprobacion_autorizada_rh')
        ->and($eventos)->toContain('onboarding_completado')
        ->and($eventos)->toContain('evaluacion_autorizada')
        ->and($eventos)->toContain('contrato_renovado');
});

test('A: contratación exitosa genera un solo QR y no duplica colaborador', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    app(CandidatoWorkflowService::class)->autorizarRh($candidato, $this->rh, null);

    $antes = Colaborador::query()->count();
    $resultado = app(ContratacionCandidatoService::class)->iniciarContratacion($candidato->refresh(), ['sueldo_mensual' => 9000, 'fecha_ingreso' => now()->toDateString()], $this->rh);

    expect(Colaborador::query()->count())->toBe($antes + 1)
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->count())->toBe(1)
        ->and($resultado['invitacion']->colaborador_id)->toBe($resultado['colaborador']->id)
        ->and(Aprobacion::query()->where('candidato_id', $candidato->id)->where('etapa', EtapaAprobacion::AutorizacionRh->value)->value('decidido_por_user_id'))->toBe($this->rh->id);
});

test('B: RH rechaza la contratación — sin QR, sin colaborador y con motivo en la timeline', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    app(CandidatoWorkflowService::class)->rechazarRh($candidato, $this->rh, 'Antecedentes no favorables.');

    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::RechazadoRh)
        ->and($candidato->colaborador_id)->toBeNull()
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->count())->toBe(0);

    expect(fn () => app(IncorporacionInvitacionService::class)->crear(['candidato_id' => $candidato->id], $this->rh))->toThrow(ValidationException::class)
        ->and(fn () => app(ContratacionCandidatoService::class)->iniciarContratacion($candidato, ['sueldo_mensual' => 1, 'fecha_ingreso' => now()->toDateString()], $this->rh))->toThrow(ValidationException::class);

    $timeline = app(CicloLaboralService::class)->obtenerEstado($candidato, $this->rh)['timeline'];
    expect(collect($timeline)->pluck('descripcion')->filter()->implode(' '))->toContain('Antecedentes no favorables.');
});

test('C: documento rechazado deja el expediente incompleto, se recarga como nueva versión y conserva la anterior', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);
    $incorporacion = app(IncorporacionService::class);
    $tipo = $this->tipos->first();

    $primero = $incorporacion->subirDocumento($colaborador, $tipo, clArchivoPdf('ine.pdf'), $usuario->id);
    $this->actingAs($this->rh);
    $incorporacion->rechazarDocumento($primero, $this->rh, 'Ilegible.');

    expect($colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::PendienteDocumentos)
        ->and(TareaRh::query()->where('tipo', TipoTarea::DocumentoRechazado->value)->whereNull('resuelta_en')->count())->toBe(1);

    $segundo = $incorporacion->subirDocumento($colaborador, $tipo, clArchivoPdf('ine-legible.pdf'), $usuario->id);

    expect($segundo->version)->toBe(2)
        ->and($segundo->previous_version_id)->toBe($primero->id)
        ->and(EmployeeDocument::query()->findOrFail($primero->id)->status)->toBe(EstadoDocumento::Archivado)
        ->and(TareaRh::query()->where('tipo', TipoTarea::DocumentoRechazado->value)->whereNull('resuelta_en')->count())->toBe(0)
        ->and(TareaRh::query()->where('tipo', TipoTarea::DocumentoPorRevisar->value)->whereNull('resuelta_en')->count())->toBe(1);
});

test('F: el jefe recomienda NO renovar — no hay baja hasta que RH autoriza, y entonces se abre el cierre sin baja inmediata', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);
    cfExpedienteAprobado($this, $colaborador, $usuario);
    cfFirmarContratos($this, $colaborador);
    cfCompletarOnboarding($this, $colaborador->refresh(), $usuario);

    $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    Carbon::setTestNow($contrato->fecha_fin->copy()->subDays(10));
    app(VencimientoContratosService::class)->revisar();
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    app(EvaluacionPeriodoPruebaService::class)->capturar($evaluacion, $this->gerenteA, ['criterios' => [['criterio' => 'Cobranza', 'calificacion' => 5]], 'recomienda_renovar' => false]);

    expect(CierreLaboral::query()->count())->toBe(0)
        ->and($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo);

    $this->actingAs($this->rh);
    app(EvaluacionPeriodoPruebaService::class)->autorizar($evaluacion->refresh(), $this->rh, ['renovar' => false]);

    $cierre = CierreLaboral::query()->firstOrFail();
    expect($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($cierre->evaluacion_id)->toBe($evaluacion->id)
        ->and($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo)
        ->and(GeneratedDocument::query()->where('documentable_type', $cierre->getMorphClass())->where('documentable_id', $cierre->id)->pluck('clave_plantilla')->sort()->values()->all())->toBe(['aviso_no_renovacion', 'evaluacion_periodo_prueba']);

    // No se ejecuta la baja antes de la fecha efectiva.
    expect(fn () => app(CierreLaboralService::class)->ejecutarBaja($cierre, $this->rh))->toThrow(ValidationException::class);
});

test('G: cierre — gerente solicita, RH autoriza, finiquito, regional programa, firma y pago, cierre conservando el expediente', function () {
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursalA->id,
        'puesto_id' => $this->puesto->id,
        'jefe_id' => $this->gerenteA->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'estado_alta' => EstadoAltaColaborador::Activo,
        'fecha_ingreso' => now()->subYear()->toDateString(),
        'sueldo_mensual' => 12000,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $cierres = app(CierreLaboralService::class);

    $cierre = $cierres->solicitar($colaborador, ['tipo_baja' => 'renuncia', 'motivo' => 'Cambio de ciudad.', 'fecha_efectiva' => now()->addDays(3)->toDateString()], $this->gerenteA, [clArchivoPdf('renuncia.pdf')]);

    // El gerente es superior: su solicitud ES la preautorización; falta RH.
    expect($cierre->estado)->toBe(EstadoCierreLaboral::PendienteRh)
        ->and($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo);

    expect(fn () => $cierres->autorizarRh($cierre, $this->gerenteA))->toThrow(ValidationException::class);

    $this->actingAs($this->rh);
    $cierre = $cierres->autorizarRh($cierre, $this->rh, 'Procede.');
    expect($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and(GeneratedDocument::query()->where('documentable_type', $cierre->getMorphClass())->where('documentable_id', $cierre->id)->where('clave_plantilla', 'carta_renuncia')->count())->toBe(1);

    $cierres->calcularFiniquito($cierre, $this->rh, 12000);
    $cierre = $cierres->autorizarFiniquito($cierre->refresh(), $this->rh);
    expect($cierre->estado)->toBe(EstadoCierreLaboral::FiniquitoAutorizado);

    expect(fn () => $cierres->programarPago($cierre, $this->gerenteA, ['fecha' => now()->addDays(3)->toDateString(), 'metodo' => 'transferencia']))->toThrow(AuthorizationException::class);
    $cierre = $cierres->programarPago($cierre, $this->coordinadora, ['fecha' => now()->addDays(3)->toDateString(), 'metodo' => 'transferencia', 'observaciones' => 'Flujo semana 41']);
    expect($cierre->estado)->toBe(EstadoCierreLaboral::PagoProgramado)
        ->and(TareaRh::query()->where('tipo', TipoTarea::CitaFiniquito->value)->whereNull('resuelta_en')->value('asignado_user_id'))->toBe($this->gerenteA->id);

    $this->actingAs($this->gerenteA);
    $cierres->registrarCita($cierre, $this->gerenteA, now()->addDays(3)->setTime(10, 0)->toDateTimeString());
    $cierres->registrarFiniquitoFirmado($cierre->refresh(), clArchivoPdf('finiquito-firmado.pdf'), $this->gerenteA);
    $cierre = $cierres->confirmarPago($cierre->refresh(), $this->gerenteA, 'SPEI-123');
    expect($cierre->estado)->toBe(EstadoCierreLaboral::Pagado);

    // Antes de la fecha efectiva no hay baja.
    expect(fn () => $cierres->cerrar($cierre, $this->rh))->toThrow(ValidationException::class);

    Carbon::setTestNow(now()->addDays(3));

    // El scheduler abre UN pendiente "concluir cierre" aunque corra varias veces.
    $this->artisan('cierres:revisar-fechas')->assertSuccessful();
    $this->artisan('cierres:revisar-fechas')->assertSuccessful();
    expect(TareaRh::query()->where('tipo', TipoTarea::CierrePorConcluir->value)->where('relacionado_id', $cierre->id)->whereNull('resuelta_en')->count())->toBe(1);

    $this->actingAs($this->rh);
    $cierre = $cierres->cerrar($cierre->refresh(), $this->rh);

    expect($cierre->estado)->toBe(EstadoCierreLaboral::ExpedienteCerrado)
        ->and(TareaRh::query()->where('relacionado_id', $cierre->id)->where('tipo', TipoTarea::CierrePorConcluir->value)->whereNull('resuelta_en')->count())->toBe(0)
        ->and(Colaborador::withTrashed()->find($colaborador->id))->not->toBeNull()
        ->and(Colaborador::withTrashed()->find($colaborador->id)->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and(User::query()->find($usuario->id))->not->toBeNull();
});

test('H: reingreso reutiliza al mismo colaborador y pide solo lo vencido o faltante', function () {
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursalA->id,
        'puesto_id' => $this->puesto->id,
        'curp' => 'REIN900101HDFPRN01',
        'estatus' => EstadoUsuario::Inactivo,
        'estado_alta' => EstadoAltaColaborador::Baja,
        'fecha_baja' => now()->subMonths(6)->toDateString(),
    ]);

    [$ine, $domicilio] = $this->tipos->all();
    EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id, 'document_type_id' => $ine->id, 'status' => EstadoDocumento::Aprobado, 'reviewed_at' => now()->subYear()]);
    EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id, 'document_type_id' => $domicilio->id, 'status' => EstadoDocumento::Aprobado, 'reviewed_at' => now()->subYear()]);
    $colaborador->delete();

    // Un alta nueva con la misma CURP se bloquea: es un reingreso.
    expect(fn () => app(IdentidadColaboradorService::class)->validarNoDuplicado(['curp' => 'REIN900101HDFPRN01']))->toThrow(ValidationException::class);
    $this->actingAs($this->rh);
    $encontrados = app(ReingresoService::class)->buscar('REIN900101HDFPRN01', $this->rh);
    expect($encontrados)->toHaveCount(1)
        ->and($encontrados[0]['dado_de_baja'])->toBeTrue();

    $reingresos = app(ReingresoService::class);
    $reingreso = $reingresos->solicitar(Colaborador::withTrashed()->findOrFail($colaborador->id), ['motivo' => 'Buen desempeño previo.'], $this->rh);
    expect($reingreso->estado)->toBe(EstadoReingreso::RevisionRh);

    $reingreso = $reingresos->decidir($reingreso, $this->rh2, true, 'Viable.');
    $colaborador = Colaborador::query()->findOrFail($colaborador->id);

    expect(Colaborador::withTrashed()->where('curp', 'REIN900101HDFPRN01')->count())->toBe(1)
        ->and($reingreso->estado)->toBe(EstadoReingreso::EnContratacion)
        ->and($colaborador->estatus)->toBe(EstadoUsuario::EnIncorporacion)
        // INE (sin vigencia) se conserva; el comprobante de domicilio (3 meses) venció.
        ->and($reingreso->documentos_requeridos)->toBe([$domicilio->id])
        ->and(EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->where('document_type_id', $ine->id)->firstOrFail()->status)->toBe(EstadoDocumento::Aprobado)
        ->and(ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoContratoLaboral::Vigente->value)->count())->toBe(1);
});

test('I: un gerente de la sucursal A no ve ni opera candidatos ni bajas de la sucursal B', function () {
    $candidatoB = cfRegistrarCandidato($this, ['sucursal_id' => $this->sucursalB->id, 'gerente_involucrado_id' => $this->gerenteB->id]);
    app(CandidatoWorkflowService::class)->evaluarPerfil($candidatoB, $this->reclutador, true, null);

    expect(fn () => app(CandidatoWorkflowService::class)->registrarEntrevista($candidatoB, $this->gerenteA, ['resultado' => 'viable']))->toThrow(AuthorizationException::class);
    expect(app(CandidatoWorkflowService::class)->accionesPermitidas($candidatoB->refresh(), $this->gerenteA))->toBe([]);

    $this->actingAs($this->gerenteA)->get("/rh/candidatos/{$candidatoB->id}")->assertForbidden();

    $colaboradorB = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursalB->id, 'estatus' => EstadoUsuario::Activo, 'estado_alta' => EstadoAltaColaborador::Activo]);
    expect(fn () => app(CierreLaboralService::class)->solicitar($colaboradorB, ['tipo_baja' => 'renuncia', 'motivo' => 'x', 'fecha_efectiva' => now()->toDateString()], $this->gerenteA))->toThrow(AuthorizationException::class);

    $bandejaA = collect(app(TareaService::class)->bandeja($this->gerenteA)->items());
    expect($bandejaA->pluck('candidato_id')->filter()->all())->not->toContain($candidatoB->id);
});

test('J: doble autorización y doble contratación simultáneas producen una sola transición', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    $workflow = app(CandidatoWorkflowService::class);

    $workflow->autorizarRh($candidato, $this->rh, null);
    expect(fn () => $workflow->autorizarRh($candidato, $this->rh2, null))->toThrow(ValidationException::class);

    $contratacion = app(ContratacionCandidatoService::class);
    $datos = ['sueldo_mensual' => 9000, 'fecha_ingreso' => now()->toDateString()];
    $contratacion->iniciarContratacion($candidato->refresh(), $datos, $this->rh);
    expect(fn () => $contratacion->iniciarContratacion($candidato->refresh(), $datos, $this->rh2))->toThrow(ValidationException::class);

    expect(Aprobacion::query()->where('candidato_id', $candidato->id)->where('etapa', EtapaAprobacion::AutorizacionRh->value)->count())->toBe(1)
        ->and(Colaborador::query()->where('candidato_id', $candidato->id)->count())->toBe(1)
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->count())->toBe(1);
});

test('el motor de aprobaciones no permite saltar RH ni que el mismo usuario preautorice y autorice', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    $this->gerenteA->givePermissionTo('ciclo.autorizar_rh');

    expect(fn () => app(CandidatoWorkflowService::class)->autorizarRh($candidato, $this->gerenteA, null))->toThrow(ValidationException::class)
        ->and(Aprobacion::query()->where('candidato_id', $candidato->id)->where('estado', EstadoAprobacion::Pendiente->value)->value('etapa'))->toBe(EtapaAprobacion::AutorizacionRh);

    $resumen = app(AprobacionService::class)->resumen($candidato, ProcesoAprobacion::SeleccionCandidato);
    expect($resumen['preautorizacion']['decidido_por'])->toBe($this->gerenteA->nombreCompleto())
        ->and($resumen['autorizado_rh'])->toBeFalse();
});

test('el tablero solo permite cerrar procesos; los avances son acciones del workflow', function () {
    $candidato = cfRegistrarCandidato($this);

    $this->actingAs($this->rh)
        ->put("/rh/candidatos/{$candidato->id}/estado", ['estado' => EstadoCandidato::AutorizadoRh->value])
        ->assertForbidden();

    $this->actingAs($this->rh)
        ->put("/rh/candidatos/{$candidato->id}/estado", ['estado' => EstadoCandidato::Desistio->value, 'nota' => 'Ya no contesta.'])
        ->assertRedirect();

    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::Desistio)
        ->and($candidato->motivo_salida)->toBe('Ya no contesta.');
});

test('el estado de la persona expone responsable, siguiente acción y pasos sin que la pantalla lo invente', function () {
    $candidato = cfRegistrarCandidato($this);
    app(CandidatoWorkflowService::class)->evaluarPerfil($candidato, $this->reclutador, true, null);

    $estado = app(CicloLaboralService::class)->obtenerEstado($candidato->refresh(), $this->gerenteA);

    expect($estado['etapa']['clave'])->toBe('reclutamiento')
        ->and($estado['estado']['clave'])->toBe('entrevista_pendiente')
        ->and($estado['responsable_actual']['rol'])->toBe('Gerente de sucursal')
        ->and($estado['siguiente_accion']['clave'])->toBe('registrar_entrevista')
        ->and(collect($estado['pasos'])->firstWhere('clave', 'perfil')['estado'])->toBe('completado')
        ->and(collect($estado['pasos'])->firstWhere('clave', 'entrevista')['estado'])->toBe('actual')
        ->and(collect($estado['acciones_permitidas'])->pluck('clave')->all())->toContain('registrar_entrevista');

    // El mismo estado para RH no ofrece la acción del gerente.
    $paraRh = app(CicloLaboralService::class)->obtenerEstado($candidato, $this->reclutador);
    expect(collect($paraRh['acciones_permitidas'])->pluck('clave')->all())->not->toContain('registrar_entrevista');
});

test('los puestos determinan la duración del periodo de prueba', function () {
    $gerencia = Puesto::factory()->create(['meses_periodo_prueba' => 3]);
    $fin = app(ContratoLaboralService::class)->fechaFinPeriodoPrueba($gerencia->id, Carbon::parse('2026-10-01'));

    expect($fin->toDateString())->toBe('2026-12-31');
});

/*
|--------------------------------------------------------------------------
| PDF punta a punta — pasos que el E2E principal recorre de forma abreviada
|--------------------------------------------------------------------------
*/

test('PDF etapa 2: contratos impresos → firma física → envío → recepción → escaneo → archivo, solo con todos los obligatorios aprobados', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);

    // Con UN obligatorio pendiente no hay contratos.
    $incorporacion = app(IncorporacionService::class);
    [$ine, $domicilio] = $this->tipos->all();
    $incorporacion->subirDocumento($colaborador, $ine, clArchivoPdf('ine.pdf'), $usuario->id);
    $this->actingAs($this->rh);
    $incorporacion->aprobarDocumento(EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->firstOrFail(), $this->rh, null);
    expect(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);

    // Rechazado → se corrige con una versión nueva → aprobado.
    $incorporacion->subirDocumento($colaborador, $domicilio, clArchivoPdf('domicilio.pdf'), $usuario->id);
    $doc = EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->where('document_type_id', $domicilio->id)->latest('id')->firstOrFail();
    $incorporacion->rechazarDocumento($doc, $this->rh, 'Ilegible');
    expect(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);
    $incorporacion->subirDocumento($colaborador->refresh(), $domicilio, clArchivoPdf('domicilio-v2.pdf'), $usuario->id);
    $incorporacion->aprobarDocumento(EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->where('document_type_id', $domicilio->id)->latest('id')->firstOrFail(), $this->rh, null);
    $incorporacion->aprobarIncorporacion($colaborador->refresh(), $this->rh);

    $contratos = GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->get();
    expect($contratos)->toHaveCount(3);

    $flujo = app(FlujoDocumentalService::class);
    $this->actingAs($this->gerenteA);
    foreach ($contratos as $documento) {
        $flujo->marcarImpreso($documento, $this->gerenteA);
        $flujo->registrarFirmaFisica($documento->refresh(), $this->gerenteA, ['huella_registrada' => true]);
        $flujo->registrarEnvio($documento->refresh(), $this->gerenteA, ['paqueteria' => 'Estafeta', 'numero_guia' => 'G-'.$documento->id]);
    }

    $this->actingAs($this->rh);
    foreach ($contratos as $documento) {
        $flujo->registrarRecepcion($documento->refresh(), $this->rh);
        $flujo->registrarEscaneo($documento->refresh(), $this->rh, clArchivoPdf('escaneo.pdf'));
        $flujo->archivar($documento->refresh(), $this->rh);
        expect($documento->refresh()->estado_flujo->value)->toBe('archivado');
    }

    expect($colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::EnOnboarding);
});

test('PDF etapa 3: 7.9 no pasa (refuerzo RH + reintento), 8.0 sí pasa', function () {
    // Módulo institucional de 100 preguntas: 79 aciertos = 7.9, 80 = 8.0.
    $preguntas = array_map(fn (int $i) => ['pregunta' => "P{$i}", 'opciones' => ['bien', 'mal'], 'correcta' => 0], range(1, 100));
    OnboardingModulo::query()->update(['activo' => false]);
    OnboardingModulo::query()->create(['titulo' => 'Inducción 100', 'tipo' => TipoModuloOnboarding::Institucional, 'orden' => 1, 'preguntas' => $preguntas, 'calificacion_minima' => 8]);

    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);
    cfExpedienteAprobado($this, $colaborador, $usuario);
    cfFirmarContratos($this, $colaborador->refresh());

    $onboarding = app(OnboardingService::class);
    $avance = $onboarding->procesoActual($colaborador->refresh())->avances()->firstOrFail();
    $respuestas = fn (int $aciertos) => array_map(fn (int $i) => $i < $aciertos ? 0 : 1, range(0, 99));

    $intento = $onboarding->presentarEvaluacion($avance, $usuario, $respuestas(79));
    expect((float) $intento->calificacion)->toBe(7.9)
        ->and($intento->aprobado)->toBeFalse()
        ->and($avance->refresh()->estado)->toBe(EstadoAvanceOnboarding::RequiereRefuerzo);

    // Sin retroalimentación de RH no hay nuevo intento.
    expect(fn () => $onboarding->presentarEvaluacion($avance->refresh(), $usuario, $respuestas(80)))->toThrow(ValidationException::class);
    $onboarding->retroalimentar($avance, $this->rh, 'Repasa el reglamento.');

    $intento = $onboarding->presentarEvaluacion($avance->refresh(), $usuario, $respuestas(80));
    expect((float) $intento->calificacion)->toBe(8.0)
        ->and($intento->aprobado)->toBeTrue()
        ->and($avance->refresh()->estado)->toBe(EstadoAvanceOnboarding::Aprobado);
});

test('PDF etapa 4: el comando del scheduler corrido 10 veces crea UNA evaluación y UNA tarea', function () {
    $candidato = cfHastaPreautorizado($this, cfRegistrarCandidato($this));
    ['colaborador' => $colaborador, 'usuario' => $usuario] = cfContratarYRegistrar($this, $candidato);
    cfExpedienteAprobado($this, $colaborador, $usuario);
    cfFirmarContratos($this, $colaborador->refresh());
    cfCompletarOnboarding($this, $colaborador->refresh(), $usuario);

    $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoContratoLaboral::Vigente->value)->firstOrFail();
    Carbon::setTestNow($contrato->fecha_fin->copy()->subDays(15)->setTime(9, 0));

    for ($i = 0; $i < 10; $i++) {
        $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    }

    expect(EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->count())->toBe(1)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->whereNull('resuelta_en')->count())->toBe(1);
});

test('PDF etapa 4: la duración sale de la configuración del puesto (Gestor 2 meses, Gerente y Regional 3)', function () {
    $this->seed(PuestoJerarquiaSeeder::class);
    $meses = fn (string $nombre) => Puesto::query()->where('nombre', $nombre)->value('meses_periodo_prueba');

    expect($meses('Gestor'))->toBe(2)
        ->and($meses('Gerente de Sucursal'))->toBe(3)
        ->and($meses('Gerente Regional Q1'))->toBe(3);

    // La regla usa el valor del puesto, no su nombre: si RH lo cambia, cambia.
    $gestor = Puesto::query()->where('nombre', 'Gestor')->firstOrFail();
    $inicio = Carbon::parse('2026-10-01');
    $contratos = app(ContratoLaboralService::class);
    expect($contratos->fechaFinPeriodoPrueba($gestor->id, $inicio)->toDateString())->toBe('2026-11-30');
    $gestor->update(['meses_periodo_prueba' => 1]);
    expect($contratos->fechaFinPeriodoPrueba($gestor->id, $inicio)->toDateString())->toBe('2026-10-31');
});

test('PDF etapas 6-7: el gerente solicita la baja → acceso fuera al instante; RH autoriza; fecha efectiva → baja definitiva; reingreso con la MISMA persona y cuenta', function () {
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursalA->id,
        'puesto_id' => $this->puesto->id,
        'estatus' => EstadoUsuario::Activo,
        'estado_alta' => EstadoAltaColaborador::Activo,
        'fecha_ingreso' => now()->subYear()->toDateString(),
        'sueldo_mensual' => 12000,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $usuario->createToken('app');
    $cierres = app(CierreLaboralService::class);

    $cierre = $cierres->solicitar($colaborador, ['tipo_baja' => 'renuncia', 'motivo' => 'Cambio de ciudad.', 'fecha_efectiva' => now()->addDays(3)->toDateString()], $this->gerenteA, [clArchivoPdf('renuncia.pdf')]);

    // Acceso fuera YA; la relación laboral sigue hasta la fecha efectiva.
    expect($usuario->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($usuario->tokens()->count())->toBe(0)
        ->and($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo)
        ->and($cierre->acceso_suspendido_en)->not->toBeNull();

    $this->actingAs($this->rh);
    $cierre = $cierres->autorizarRh($cierre, $this->rh, 'Procede.');
    $cierres->calcularFiniquito($cierre, $this->rh, 12000);
    $cierre = $cierres->autorizarFiniquito($cierre->refresh(), $this->rh);
    $cierre = $cierres->programarPago($cierre, $this->coordinadora, ['fecha' => now()->addDays(3)->toDateString(), 'metodo' => 'transferencia']);
    $this->actingAs($this->gerenteA);
    $cierres->registrarCita($cierre, $this->gerenteA, now()->addDays(3)->setTime(10, 0)->toDateTimeString());
    $cierres->registrarFiniquitoFirmado($cierre->refresh(), clArchivoPdf('finiquito-firmado.pdf'), $this->gerenteA);
    $cierre = $cierres->confirmarPago($cierre->refresh(), $this->gerenteA, 'SPEI-123');

    // Pagado pero antes de la fecha efectiva: todavía no es baja laboral.
    expect($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo);

    Carbon::setTestNow(now()->addDays(3));
    $this->actingAs($this->rh);
    $cierres->cerrar($cierre->refresh(), $this->rh);

    $persona = Colaborador::withTrashed()->findOrFail($colaborador->id);
    expect($persona->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and(User::query()->find($usuario->id))->not->toBeNull()
        ->and(CierreLaboral::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);

    // Reingreso: misma persona, mismo usuario, historial intacto.
    $reingresos = app(ReingresoService::class);
    $reingreso = $reingresos->solicitar($persona, ['motivo' => 'Buen desempeño.'], $this->rh);
    $reingresos->decidir($reingreso, $this->rh2, true, 'Viable.');

    expect(Colaborador::withTrashed()->where('id', $colaborador->id)->count())->toBe(1)
        ->and(User::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and($usuario->refresh()->acceso_bloqueado_en)->toBeNull()
        ->and(Colaborador::query()->findOrFail($colaborador->id)->estatus)->toBe(EstadoUsuario::EnIncorporacion)
        ->and(CierreLaboral::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoContratoLaboral::Vigente->value)->count())->toBe(1);
});
