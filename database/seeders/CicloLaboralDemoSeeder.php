<?php

namespace Database\Seeders;

use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Enums\TipoModuloOnboarding;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Departamento;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\HeadcountTarget;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Puesto;
use App\Models\TipoActivo;
use App\Models\User;
use App\Models\Vacante;
use App\Services\CicloLaboral\ReingresoService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\VencimientoContratosService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use App\Services\Reclutamiento\PreguntasFiltroCandidatoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Demo del ciclo laboral completo (solo local/testing, vía DemoSeeder):
 * deja personas en cada etapa para recorrer la plataforma — candidatos en
 * perfil, entrevista, psicométricas, socioeconómico, referencias,
 * preautorizado y esperando RH; personas en documentos, onboarding,
 * onboarding < 8, periodo de prueba por vencer y esperando a RH; una baja,
 * un finiquito por entregar y un reingreso.
 *
 * TODO se crea llamando a los services públicos (los mismos de web/API):
 * si un estado no se pudiera alcanzar así, el sistema no estaría completo.
 * Las plantillas, módulos y activos que crea están marcados como DEMO; los
 * reales los carga RH/Jurídico. Idempotente: si ya existe la persona demo
 * de un escenario, ese escenario se salta.
 */
class CicloLaboralDemoSeeder extends Seeder
{
    private const PREFIJO = 'demo.ciclo.';

    private User $rh;

    private User $reclutador;

    private User $gerente;

    private Puesto $puesto;

    private ?Vacante $vacanteDemo = null;

    public function run(): void
    {
        $rh = User::query()->where('email', 'rh.admin@mrlana.test')->first();
        $reclutador = User::query()->where('email', 'rh.auxiliar@mrlana.test')->first();
        $gerente = User::query()->where('email', 'gerente.sucursal@mrlana.test')->first();
        $puesto = Puesto::query()->where('nombre', 'Gestor')->first();

        if ($rh === null || $reclutador === null || $gerente?->colaborador?->sucursal_principal_id === null || $puesto === null) {
            $this->aviso('CicloLaboralDemoSeeder: faltan los usuarios demo (UsuarioDemoSeeder) o el puesto Gestor; se omite.');

            return;
        }

        [$this->rh, $this->reclutador, $this->gerente, $this->puesto] = [$rh, $reclutador, $gerente, $puesto];

        // DatabaseSeeder usa WithoutModelEvents (apaga observers). El ciclo
        // depende de ellos (p. ej. registrar la última firma recalcula el
        // alta e inicia el onboarding): aquí se encienden para recorrer el
        // flujo real y se restaura el estado al terminar.
        $anterior = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        try {
            $this->sembrar();
        } finally {
            if ($anterior !== null) {
                Model::setEventDispatcher($anterior);
            } else {
                Model::unsetEventDispatcher();
            }

            Auth::logout();
        }
    }

    private function sembrar(): void
    {
        $this->catalogosDemo();

        // --- Etapa 1: reclutamiento ---
        $this->escenario('candidato en revisión de perfil', fn () => $this->candidato('perfil', EstadoCandidato::Recibidos));
        $this->escenario('candidato en entrevista', fn () => $this->candidato('entrevista', EstadoCandidato::EntrevistaPendiente));
        $this->escenario('candidato en psicométricas', fn () => $this->candidato('psicometricas', EstadoCandidato::PsicometricasPendientes));
        $this->escenario('candidato en socioeconómico', fn () => $this->candidato('socioeconomico', EstadoCandidato::SocioeconomicoPendiente));
        $this->escenario('candidato en referencias', fn () => $this->candidato('referencias', EstadoCandidato::ReferenciasPendientes));
        $this->escenario('candidato esperando la autorización de RH', fn () => $this->candidato('preseleccion', EstadoCandidato::AutorizacionRhPendiente));
        $this->escenario('candidato preautorizado esperando RH', fn () => $this->candidato('esperando-rh', EstadoCandidato::AutorizacionRhPendiente));

        // --- Etapas 2 a 6: personas ---
        $this->escenario('persona cargando documentos', fn () => $this->persona(1, 'documentos'));
        $this->escenario('persona en onboarding', fn () => $this->persona(2, 'onboarding'));
        $this->escenario('persona con onboarding menor a 8', fn () => $this->persona(3, 'onboarding_reprobado'));
        $this->escenario('periodo de prueba por vencer', fn () => $this->persona(4, 'periodo_por_vencer'));
        $this->escenario('periodo de prueba esperando RH', fn () => $this->persona(5, 'periodo_esperando_rh'));
        $this->escenario('baja aplicada con documentos pendientes', fn () => $this->persona(6, 'baja'));
        $this->escenario('baja con finiquito por entregar', fn () => $this->persona(7, 'finiquito_por_entregar'));
        $this->escenario('reingreso por decidir', fn () => $this->persona(8, 'reingreso'));
    }

    /**
     * Plantillas, módulos de inducción y activos DEMO (solo si faltan): sin
     * ellos el flujo se detiene en "plantilla faltante" — que es lo correcto
     * en producción hasta que Jurídico/RH carguen los reales.
     */
    private function catalogosDemo(): void
    {
        $fisico = ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true];
        // Solo si NO se importaron los formatos de Jurídico
        // (people:importar-formatos-juridicos): con masters cargados, la
        // demo usa los originales reales y estas no se tocan.
        $plantillas = [
            'contrato_capacitacion' => $fisico,
            'contrato_periodo_prueba' => $fisico,
            'contrato_confidencialidad' => $fisico,
            'contrato_no_competencia' => $fisico,
            'contrato_indeterminado' => $fisico,
            'carta_responsiva' => [],
            'aviso_no_renovacion' => [],
            'evaluacion_periodo_prueba' => [],
            'carta_renuncia' => [],
            'aviso_termino' => [],
            'aviso_rescision' => [],
        ];

        foreach ($plantillas as $clave => $flags) {
            if (DocumentTemplate::query()->where('clave', $clave)->exists()) {
                continue;
            }

            $nombre = (string) (config("contratos.plantillas.{$clave}.nombre") ?? config("ciclo_laboral.plantillas.{$clave}") ?? $clave);

            DocumentTemplate::query()->create([
                'clave' => $clave,
                'nombre' => "DEMO — {$nombre}",
                'tipo' => 'otro',
                'motor' => 'html',
                'contenido_html' => '<h1>'.e($nombre).'</h1><p><strong>TEXTO DE DEMOSTRACIÓN — sustituir por la plantilla de Jurídico.</strong></p><p>Colaborador: {{nombre_completo}}. Inicio: {{fecha_inicio_contrato}}. Fin: {{fecha_fin_contrato}}. Sueldo: {{sueldo_mensual}}.</p>',
                'version' => 1,
                'activo' => true,
                ...$flags,
            ]);
        }

        $preguntas = [
            ['pregunta' => '¿Quién da la autorización final de una contratación?', 'opciones' => ['El gerente', 'Recursos Humanos'], 'correcta' => 1],
            ['pregunta' => '¿Dónde ves lo que te toca hacer?', 'opciones' => ['En "Mis pendientes" de tu portal', 'En ningún lado'], 'correcta' => 0],
        ];

        OnboardingModulo::query()->firstOrCreate(['clave' => 'demo_bienvenida'], [
            'titulo' => 'DEMO — Bienvenida a MR. LANA',
            'tipo' => TipoModuloOnboarding::Institucional,
            'orden' => 1,
            'contenido' => 'Módulo de demostración: RH carga el material real en Configuración → Onboarding.',
            'preguntas' => $preguntas,
            'calificacion_minima' => 8,
            'obligatorio' => true,
            'activo' => true,
        ]);

        OnboardingModulo::query()->firstOrCreate(['clave' => 'demo_gestor_ruta'], [
            'titulo' => 'DEMO — Ruta y cobranza',
            'tipo' => TipoModuloOnboarding::Puesto,
            'puesto_id' => $this->puesto->id,
            'orden' => 1,
            'contenido' => 'Módulo de demostración del puesto Gestor.',
            'preguntas' => $preguntas,
            'calificacion_minima' => 8,
            'obligatorio' => true,
            'activo' => true,
        ]);

        TipoActivo::query()->firstOrCreate(['clave' => 'uniforme'], ['nombre' => 'Uniforme', 'obligatorio' => true, 'activo' => true]);
        TipoActivo::query()->firstOrCreate(['clave' => 'credencial'], ['nombre' => 'Credencial', 'obligatorio' => true, 'activo' => true]);
        TipoActivo::query()->firstOrCreate(['clave' => 'casco'], ['nombre' => 'Casco', 'requiere_identificador' => true, 'obligatorio' => false, 'activo' => true]);
    }

    /**
     * Candidato llevado por el workflow hasta $hasta.
     */
    private function candidato(string $clave, EstadoCandidato $hasta, ?string $correo = null): Candidato
    {
        $correo ??= self::PREFIJO.'candidato.'.$clave.'@mrlana.test';
        $wf = app(CandidatoWorkflowService::class);

        // Reanudable: si una corrida anterior lo dejó a medias, continúa
        // desde su estado actual en vez de crear otro.
        $existente = Candidato::query()->where('correo', $correo)->first();

        if ($existente !== null) {
            return $this->avanzarCandidato($wf, $existente, $hasta);
        }

        $sucursal = $this->gerente->colaborador?->sucursalPrincipal;
        $vacante = $this->vacanteDemo($sucursal?->empresa_id, $sucursal?->id);

        $this->como($this->reclutador);
        $c = $wf->registrar([
            'empresa_id' => $sucursal?->empresa_id,
            'sucursal_id' => $sucursal?->id,
            'departamento_id' => Departamento::query()->where('nombre', 'Ventas')->value('id'),
            'puesto_objetivo_id' => $this->puesto->id,
            'vacante_id' => $vacante?->id,
            'nombre' => 'Demo',
            'apellidos' => ucfirst(str_replace(['-', '_'], ' ', $clave)).' Ciclo',
            'telefono' => '5550000000',
            'correo' => $correo,
            'fuente' => 'facebook_grupos',
            'respuestas_filtro' => app(PreguntasFiltroCandidatoService::class)->respuestasQueCumplen(),
            'gerente_involucrado_id' => $this->gerente->id,
        ], $this->reclutador);

        return $this->avanzarCandidato($wf, $c, $hasta);
    }

    /**
     * Vacante real para que la demo pueda llegar hasta contratación
     * (CLAUDE.md §3: un candidato espontáneo, sin vacante, nunca se
     * contrata). Manual, con plazas de sobra para las 8 personas del
     * escenario; se crea una sola vez por corrida.
     */
    private function vacanteDemo(?int $empresaId, ?int $sucursalId): ?Vacante
    {
        if ($this->vacanteDemo !== null) {
            return $this->vacanteDemo;
        }

        if ($sucursalId === null) {
            return null;
        }

        // Una vacante solo existe si la plantilla autorizada tiene plaza libre
        // (autorizadas − ocupadas > 0): se autorizan 10 lugares más de los
        // que ya están ocupados en ese puesto/sucursal.
        $ocupados = Colaborador::query()->where('sucursal_principal_id', $sucursalId)->where('puesto_id', $this->puesto->id)->where('estatus', 'activo')->count();
        HeadcountTarget::query()->updateOrCreate(
            ['sucursal_id' => $sucursalId, 'puesto_id' => $this->puesto->id],
            ['empresa_id' => $empresaId, 'plantilla_autorizada' => $ocupados + 10, 'fuente' => 'manual', 'fecha_corte' => now()->toDateString(), 'editable' => true, 'created_by_id' => $this->rh->id],
        );

        return $this->vacanteDemo = Vacante::query()->create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'puesto_id' => $this->puesto->id,
            'motivo' => MotivoVacante::Crecimiento->value,
            'estado' => EstadoVacante::Abierta->value,
            'generada_automaticamente' => false,
            'plazas_requeridas' => 10,
            'plazas_disponibles' => 10,
            'fecha_apertura' => now()->subDays(10)->toDateString(),
            'creado_por' => $this->rh->id,
        ]);
    }

    private function avanzarCandidato(CandidatoWorkflowService $wf, Candidato $c, EstadoCandidato $hasta): Candidato
    {
        $pasos = [
            EstadoCandidato::EntrevistaPendiente->orden() => fn () => $wf->evaluarPerfil($c, $this->como($this->reclutador), true, 'Cubre el perfil.'),
            EstadoCandidato::PsicometricasPendientes->orden() => fn () => $wf->registrarEntrevista($c, $this->como($this->gerente), ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Entrevista demo.']),
            EstadoCandidato::RevisionPsicometricas->orden() => function () use ($wf, $c): void {
                $wf->enviarPsicometricas($c, $this->como($this->reclutador), 'https://pruebas.example.test/demo');
                $wf->registrarResultadosPsicometricas($c, $this->reclutador, 'Resultados demo dentro del perfil.');
            },
            EstadoCandidato::SocioeconomicoPendiente->orden() => fn () => $wf->revisarPsicometricas($c, $this->como($this->gerente), true, null),
            EstadoCandidato::ReferenciasPendientes->orden() => function () use ($wf, $c): void {
                $wf->registrarSocioeconomico($c, $this->como($this->gerente), ['fecha_visita' => now()->toDateString(), 'direccion' => 'Domicilio demo', 'checklist' => ['vivienda_en_orden' => true]]);
                $wf->revisarSocioeconomico($c, $this->gerente, true, null);
            },
            // Sin preautorización: referencias concluidas = autorización de RH pendiente.
            EstadoCandidato::AutorizacionRhPendiente->orden() => function () use ($wf, $c): void {
                $wf->registrarReferencia($c, $this->como($this->reclutador), ['empresa' => 'Empresa demo', 'contacto' => 'Jefe anterior', 'resultado' => 'positiva']);
                $wf->concluirReferencias($c, $this->reclutador, true, null);
            },
            EstadoCandidato::AutorizadoRh->orden() => fn () => $wf->autorizarRh($c, $this->como($this->rh), 'Autorizado (demo).'),
        ];

        foreach ($pasos as $orden => $paso) {
            if ($orden > $hasta->orden()) {
                break;
            }

            if ($orden <= $c->estado->orden()) {
                continue;
            }

            $paso();
            $c->refresh();
        }

        return $c;
    }

    /**
     * Persona que pasó reclutamiento y se contrató, llevada hasta $hasta.
     */
    private function persona(int $n, string $hasta): void
    {
        $correo = sprintf('%spersona%d@mrlana.test', self::PREFIJO, $n);

        if (User::query()->where('email', $correo)->exists()) {
            return;
        }

        // Los escenarios de periodo de prueba/baja necesitan un contrato que
        // ya corre: el ingreso fue hace 1 mes y 20 días (gestor = 2 meses).
        $ingreso = in_array($hasta, ['documentos', 'onboarding', 'onboarding_reprobado'], true) ? now() : now()->subMonth()->subDays(20);

        $candidato = $this->candidato("persona{$n}", EstadoCandidato::AutorizadoRh, $correo);

        $resultado = app(ContratacionCandidatoService::class)->iniciarContratacion($candidato, [
            'sueldo_mensual' => 9500,
            'fecha_ingreso' => $ingreso->toDateString(),
            'jefe_id' => $this->gerente->colaborador_id,
        ], $this->como($this->rh));

        $invitaciones = app(IncorporacionInvitacionService::class);
        $usuario = $invitaciones->registrarUsuario($invitaciones->validar($resultado['token']), [
            'name' => 'Demo',
            'apellidos' => "Persona {$n} Ciclo",
            'email' => $correo,
            'password' => 'Capacitacion2026!',
            'curp' => sprintf('DEMC%02d0101HDFRRN%02d', $n, $n),
        ]);
        $colaborador = $resultado['colaborador']->refresh();

        if ($hasta === 'documentos') {
            return;
        }

        // Expediente del alta completo y aprobado por RH → contratos.
        $incorporacion = app(IncorporacionService::class);

        foreach ($incorporacion->tiposDocumento($colaborador)->where('requerido', true) as $tipo) {
            $incorporacion->subirDocumento($colaborador, $tipo, UploadedFile::fake()->create("{$tipo->clave}.pdf", 20, 'application/pdf'), $usuario->id);
        }

        $this->como($this->rh);

        foreach (EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->get() as $documento) {
            $incorporacion->aprobarDocumento($documento, $this->rh, null);
        }

        $incorporacion->aprobarIncorporacion($colaborador->refresh(), $this->rh);

        // RH pulsa «Generar paquete de contratación» (modo por defecto; en
        // modo automático ya se generó y aquí no se duplica nada). Los
        // datos que piden los contratos de Jurídico se completan con valores
        // fijos de demostración, nunca con Faker.
        $colaborador->refresh();
        $colaborador->update(array_filter([
            'nacionalidad' => 'Mexicana',
            'estado_civil' => 'soltero',
            'lugar_nacimiento' => 'Cuernavaca, Morelos',
            'fecha_nacimiento' => '1995-01-01',
            'rfc' => sprintf('DEMC9501%02dAB1', $n),
            'nss' => sprintf('120000000%02d', $n),
            'clave_elector' => sprintf('DEMCPR9501%02dH000', $n),
            'profesion' => 'Bachillerato',
            'domicilio' => 'Calle Demostración 10, Col. Centro, Cuernavaca, Morelos',
            'domicilio_calle' => 'Calle Demostración',
            'domicilio_numero_exterior' => '10',
            'domicilio_colonia' => 'Centro',
            'domicilio_municipio' => 'Cuernavaca',
            'domicilio_estado' => 'Morelos',
            'domicilio_cp' => '62000',
        ], fn (string $valor, string $campo): bool => blank($colaborador->getAttribute($campo)), ARRAY_FILTER_USE_BOTH));
        $contrato = app(ContratoLaboralService::class)->vigente($colaborador);

        if ($contrato !== null) {
            app(DocumentoProcesoService::class)->generarPaquete('alta', $contrato, $this->rh);
        }

        // Firma física de los contratos (impresión + firma con huella).
        $flujo = app(FlujoDocumentalService::class);
        $this->como($this->gerente);

        foreach (GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->get() as $documento) {
            $flujo->marcarImpreso($documento, $this->gerente);
            $flujo->registrarFirmaFisica($documento->refresh(), $this->gerente, ['huella_registrada' => true]);
        }

        if ($hasta === 'onboarding') {
            return;
        }

        $onboarding = app(OnboardingService::class);
        $proceso = $onboarding->procesoActual($colaborador->refresh());

        if ($proceso === null) {
            $this->aviso("CicloLaboralDemoSeeder: {$correo} no inició onboarding (¿faltan contratos firmados?).");

            return;
        }

        $this->como($usuario);

        // El avance institucional "Inducción Mr. Lana" ya no se aprueba con
        // el quiz genérico, sino con la evaluación oficial de 30 puntos (ver
        // Fase 1/InduccionMrLanaModuloSeeder) — se resuelve aparte, con
        // respuestas 100% correctas, para dejar el resto del onboarding
        // (módulos demo) alcanzable igual que antes de esa fase.
        $this->completarInduccionFormal($proceso, $usuario);

        $avancesQuiz = $proceso->refresh()->avances()
            ->whereHas('modulo', fn ($q) => $q->whereNull('evaluacion_formal_clave'))
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        if ($hasta === 'onboarding_reprobado') {
            // 1 de 2 correctas = 5 < 8 → refuerzo de RH.
            $onboarding->presentarEvaluacion($avancesQuiz->firstOrFail(), $usuario, [0 => 0, 1 => 0]);

            return;
        }

        foreach ($avancesQuiz as $avance) {
            $onboarding->presentarEvaluacion($avance->refresh(), $usuario, [0 => 1, 1 => 0]);
        }

        $this->como($this->gerente);

        foreach (TipoActivo::query()->where('activo', true)->where('obligatorio', true)->get() as $tipo) {
            $onboarding->entregarActivo($proceso->refresh(), $this->gerente, ['tipo_activo_id' => $tipo->id, 'identificador' => $tipo->requiere_identificador ? "DEMO-{$n}" : null]);
        }

        $onboarding->completar($proceso->refresh(), $this->gerente);
        $colaborador->refresh();

        if (in_array($hasta, ['periodo_por_vencer', 'periodo_esperando_rh'], true)) {
            $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->latest('id')->firstOrFail();
            app(VencimientoContratosService::class)->procesar($contrato->id);

            if ($hasta === 'periodo_esperando_rh') {
                $evaluacion = EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->firstOrFail();
                // Formato oficial FO-RH-EPP-001 (9 criterios + 5 filtros,
                // ver config contratos.evaluacion_periodo_prueba_v2): todo
                // en 4 (Muy bueno) y ningún filtro en Sí, sin comentario
                // obligatorio.
                /** @var list<array{clave: string}> $criteriosOficiales */
                $criteriosOficiales = (array) config('contratos.evaluacion_periodo_prueba_v2.criterios', []);
                /** @var list<array{clave: string}> $filtrosOficiales */
                $filtrosOficiales = (array) config('contratos.evaluacion_periodo_prueba_v2.filtros', []);

                app(EvaluacionPeriodoPruebaService::class)->capturar($evaluacion, $this->como($this->gerente), [
                    'criterios' => array_map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => 4], $criteriosOficiales),
                    'filtros' => array_map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => false], $filtrosOficiales),
                ]);
            }

            return;
        }

        // --- Etapa 6: cierre ---
        $cierres = app(CierreLaboralService::class);
        $cierre = $cierres->solicitar($colaborador, [
            'tipo_baja' => 'renuncia',
            'motivo' => 'Cambio de ciudad (demo).',
            'fecha_efectiva' => now()->toDateString(),
        ], $this->como($this->gerente));

        if ($hasta === 'baja') {
            return;
        }

        // La baja quedó aplicada al iniciarla (sin autorización de RH). RH
        // calcula el finiquito (editable) y, al entregarlo, el cierre termina:
        // no hay autorización del finiquito ni pago programado.
        $this->como($this->rh);
        $cierres->calcularFiniquito($cierre, $this->rh, 9500);

        if ($hasta === 'finiquito_por_entregar') {
            return;
        }

        $cierres->registrarEntregaFiniquito($cierre->refresh(), $this->rh, [
            'fecha' => now()->toDateString(),
            'forma_pago' => 'transferencia',
            'comentario' => 'Entrega de finiquito (demo).',
        ]);

        // --- Reingreso: la MISMA persona ---
        app(ReingresoService::class)->solicitar(
            Colaborador::withTrashed()->findOrFail($colaborador->id),
            ['motivo' => 'Buen desempeño previo (demo).', 'documentos_adicionales' => array_values(array_map('intval', DocumentType::query()->where('clave', 'comprobante_domicilio')->pluck('id')->all()))],
            $this->como($this->gerente),
        );
    }

    /**
     * Resuelve (idempotente) la evaluación formal de inducción
     * (induccion_mr_lana_2026) con respuestas 100% correctas contra
     * config/induccion_evaluacion.php: sin esto, el avance institucional que
     * la usa (ver InduccionMrLanaModuloSeeder) se queda bloqueando el resto
     * del onboarding, porque ya no se aprueba con el quiz genérico.
     */
    private function completarInduccionFormal(OnboardingProceso $proceso, User $usuario): void
    {
        $avanceFormal = $proceso->avances()->whereHas('modulo', fn ($q) => $q->whereNotNull('evaluacion_formal_clave'))->first();

        if ($avanceFormal === null || $avanceFormal->estado === EstadoAvanceOnboarding::Aprobado) {
            return;
        }

        $evaluaciones = app(EvaluacionInduccionService::class);

        app(OnboardingService::class)->marcarContenidoVisto($avanceFormal, $usuario);
        $evaluacion = $evaluaciones->habilitarPara($proceso->refresh());

        if ($evaluacion === null) {
            return;
        }

        $a = [];

        /** @var array<int, array{correcta: int}> $seccionA */
        $seccionA = (array) config('induccion_evaluacion.seccion_a', []);

        foreach ($seccionA as $indice => $pregunta) {
            $a[$indice] = $pregunta['correcta'];
        }

        $b = [];

        /** @var array<int, array{correcto: bool}> $seccionB */
        $seccionB = (array) config('induccion_evaluacion.seccion_b', []);

        foreach ($seccionB as $indice => $afirmacion) {
            $b[$indice] = $afirmacion['correcto'];
        }

        $c = [];

        /** @var array<int, array{letra_correcta: string}> $seccionCConceptos */
        $seccionCConceptos = (array) config('induccion_evaluacion.seccion_c.conceptos', []);

        foreach ($seccionCConceptos as $indice => $concepto) {
            $c[$indice] = $concepto['letra_correcta'];
        }

        $d = [];
        $puntos = [];

        /** @var list<array{clave: string, rubrica: list<array{clave: string}>}> $seccionD */
        $seccionD = (array) config('induccion_evaluacion.seccion_d', []);

        foreach ($seccionD as $caso) {
            $d[$caso['clave']] = "Respuesta de demostración para {$caso['clave']}.";
            $puntos[$caso['clave']] = array_fill_keys(array_column($caso['rubrica'], 'clave'), true);
        }

        $intento = $evaluaciones->presentar($evaluacion, $usuario, ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d], null);
        $evaluaciones->calificarCasosPracticos($intento, $this->como($this->rh), $puntos);
        $this->como($usuario);
    }

    private function como(User $usuario): User
    {
        Auth::setUser($usuario);

        return $usuario;
    }

    private function escenario(string $nombre, callable $paso): void
    {
        try {
            $paso();
        } catch (Throwable $e) {
            $this->aviso(sprintf('CicloLaboralDemoSeeder: no se pudo preparar «%s»: %s', $nombre, $e->getMessage()));
        }
    }

    /**
     * Aviso en consola cuando corre por artisan; en log cuando lo invoca
     * otro código (pruebas, DatabaseSeeder sin comando).
     */
    private function aviso(string $mensaje): void
    {
        if ($this->command !== null) {
            $this->command->warn($mensaje);

            return;
        }

        Log::warning($mensaje);
    }
}
