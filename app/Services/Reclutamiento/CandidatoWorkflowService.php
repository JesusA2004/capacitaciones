<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
use App\Enums\ResultadoEtapaCandidato;
use App\Enums\ResultadoReferencia;
use App\Enums\TipoEvidenciaCandidato;
use App\Enums\TipoSeguimientoCandidato;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoPsicometrica;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Autoridad única de las transiciones del reclutamiento (Etapa 1). Web
 * (Rh\CandidatoController) y API (Api\V1\Rh\CandidatoController) llaman a
 * este service; ningún controlador mueve el estado de un candidato.
 *
 *   recibidos ─perfil→ entrevista_pendiente ─entrevista→ psicometricas_pendientes
 *   ─resultados→ revision_psicometricas ─gerente→ socioeconomico_pendiente
 *   ─visita→ referencias_pendientes ─validación→ preseleccion_gerente
 *   ─PREAUTORIZACIÓN gerente→ autorizacion_rh_pendiente ─AUTORIZACIÓN RH→ autorizado_rh
 *   ─QR (ContratacionCandidatoService)→ en_contratacion → contratado
 *
 * Cada filtro negativo cierra el proceso con motivo obligatorio. Cada paso
 * deja registro estructurado + seguimiento (timeline) + auditoría, resuelve
 * el pendiente anterior y abre el del siguiente responsable. Las
 * transiciones bloquean la fila del candidato (lockForUpdate): un doble
 * clic nunca avanza dos veces.
 */
class CandidatoWorkflowService
{
    /** Reclutamiento: perfil, psicométricas, referencias. */
    public const PERMISO_RECLUTAMIENTO = 'candidatos.editar';

    /** Gerente de sucursal: entrevista, revisión de psicométricas, socioeconómico. */
    public const PERMISO_GERENTE = 'candidatos.evaluar';

    public const PERMISO_DESCARTAR = 'candidatos.rechazar';

    public function __construct(
        private readonly AprobacionService $aprobaciones,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly CvStorageService $almacenamiento,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por StoreCandidatoRequest.
     */
    public function registrar(array $datos, User $actor): Candidato
    {
        $candidato = DB::transaction(function () use ($datos, $actor): Candidato {
            $candidato = Candidato::query()->create([
                ...$datos,
                'estado' => EstadoCandidato::Recibidos,
                'etapa_maxima' => EstadoCandidato::Recibidos->orden(),
                'creado_por' => $actor->id,
            ]);

            $candidato->seguimientos()->create([
                'tipo' => TipoSeguimientoCandidato::Nota,
                'nota' => sprintf('Candidato registrado (fuente: %s).', $candidato->fuente ?? 'sin especificar'),
                'estado_nuevo' => EstadoCandidato::Recibidos->value,
                'fecha' => now(),
                'registrado_por' => $actor->id,
            ]);

            return $candidato;
        });

        $this->auditoria->registrar('candidato_registrado', $candidato, $actor, [
            'candidato_id' => $candidato->id,
            'fuente' => $candidato->fuente,
            'vacante_id' => $candidato->vacante_id,
            'campana_reclutamiento_id' => $candidato->campana_reclutamiento_id,
        ]);

        $this->abrirPendiente($candidato);

        return $candidato;
    }

    public function evaluarPerfil(Candidato $candidato, User $actor, bool $viable, ?string $observaciones): Candidato
    {
        $this->autorizar($actor, self::PERMISO_RECLUTAMIENTO, $candidato);

        return $this->paso($candidato, $actor, EstadoCandidato::Recibidos, function (Candidato $c) use ($actor, $viable, $observaciones): void {
            if ($viable) {
                $this->transicionar($c, EstadoCandidato::EntrevistaPendiente, $actor, $this->nota('Perfil viable: pasa a entrevista con el gerente.', $observaciones));

                return;
            }

            $this->salir($c, EstadoCandidato::NoViable, $actor, $this->exigirMotivo($observaciones, 'No cubre el perfil'));
        });
    }

    /**
     * @param  array<string, mixed>  $datos  realizada_en?, observaciones?, resultado, entrevistador_user_id? (RegistrarEntrevistaRequest).
     */
    public function registrarEntrevista(Candidato $candidato, User $actor, array $datos): Candidato
    {
        $this->autorizar($actor, self::PERMISO_GERENTE, $candidato);
        $resultado = ResultadoEtapaCandidato::from((string) $datos['resultado']);
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;
        $realizada = isset($datos['realizada_en']) ? (string) $datos['realizada_en'] : now()->toDateTimeString();
        $entrevistador = isset($datos['entrevistador_user_id']) ? (int) $datos['entrevistador_user_id'] : $actor->id;

        return $this->paso($candidato, $actor, EstadoCandidato::EntrevistaPendiente, function (Candidato $c) use ($actor, $resultado, $observaciones, $realizada, $entrevistador): void {
            CandidatoEntrevista::query()->create([
                'candidato_id' => $c->id,
                'realizada_en' => $realizada,
                'entrevistador_user_id' => $entrevistador,
                'observaciones' => $observaciones,
                'resultado' => $resultado,
                'registrado_por' => $actor->id,
            ]);

            $c->update(['fecha_entrevista' => $realizada, 'resultado_entrevista' => $observaciones]);

            if ($resultado === ResultadoEtapaCandidato::Viable) {
                $this->transicionar($c, EstadoCandidato::PsicometricasPendientes, $actor, $this->nota('Entrevista realizada: sigue viable. Se solicitan pruebas psicométricas.', $observaciones));

                return;
            }

            $this->salir($c, EstadoCandidato::NoViable, $actor, $this->exigirMotivo($observaciones, 'No viable tras la entrevista'));
        });
    }

    public function enviarPsicometricas(Candidato $candidato, User $actor, string $link): Candidato
    {
        $this->autorizar($actor, self::PERMISO_RECLUTAMIENTO, $candidato);

        return $this->paso($candidato, $actor, EstadoCandidato::PsicometricasPendientes, function (Candidato $c) use ($actor, $link): void {
            $registro = $this->psicometricaAbierta($c);
            $registro->update(['link' => $link, 'enviada_en' => now(), 'enviada_por' => $actor->id]);

            $this->seguimiento($c, $actor, TipoSeguimientoCandidato::Nota, 'Link de pruebas psicométricas enviado al candidato.');
            $this->auditar('candidato_psicometricas_enviadas', $c, $actor);
        }, avanza: false);
    }

    /**
     * @param  list<UploadedFile>  $archivos
     */
    public function registrarResultadosPsicometricas(Candidato $candidato, User $actor, string $resumen, array $archivos = []): Candidato
    {
        $this->autorizar($actor, self::PERMISO_RECLUTAMIENTO, $candidato);

        return $this->paso($candidato, $actor, EstadoCandidato::PsicometricasPendientes, function (Candidato $c) use ($actor, $resumen, $archivos): void {
            $registro = $this->psicometricaAbierta($c);
            $registro->update(['resultados_en' => now(), 'resultados_por' => $actor->id, 'resumen_resultados' => $resumen]);
            $this->guardarEvidencias($c, $registro, $archivos, $actor);

            $this->transicionar($c, EstadoCandidato::RevisionPsicometricas, $actor, 'Resultados psicométricos registrados y entregados al gerente.');
        });
    }

    public function revisarPsicometricas(Candidato $candidato, User $actor, bool $viable, ?string $observaciones): Candidato
    {
        $this->autorizar($actor, self::PERMISO_GERENTE, $candidato);

        return $this->paso($candidato, $actor, EstadoCandidato::RevisionPsicometricas, function (Candidato $c) use ($actor, $viable, $observaciones): void {
            $this->psicometricaAbierta($c)->update([
                'revision_resultado' => $viable ? ResultadoEtapaCandidato::Viable : ResultadoEtapaCandidato::NoViable,
                'revision_observaciones' => $observaciones,
                'revisada_por' => $actor->id,
                'revisada_en' => now(),
            ]);

            if ($viable) {
                $this->transicionar($c, EstadoCandidato::SocioeconomicoPendiente, $actor, $this->nota('Psicométricas en perfil: pasa a estudio socioeconómico.', $observaciones));

                return;
            }

            $this->salir($c, EstadoCandidato::NoViable, $actor, $this->exigirMotivo($observaciones, 'Resultados psicométricos fuera de perfil'));
        });
    }

    /**
     * @param  array<string, mixed>  $datos  fecha_visita, direccion, checklist?, riesgos?, observaciones?, resultado, visitador_user_id? (RegistrarSocioeconomicoRequest).
     * @param  list<UploadedFile>  $evidencias
     */
    public function registrarSocioeconomico(Candidato $candidato, User $actor, array $datos, array $evidencias = []): Candidato
    {
        $this->autorizar($actor, self::PERMISO_GERENTE, $candidato);
        $resultado = ResultadoEtapaCandidato::from((string) $datos['resultado']);
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;

        return $this->paso($candidato, $actor, EstadoCandidato::SocioeconomicoPendiente, function (Candidato $c) use ($actor, $datos, $resultado, $evidencias, $observaciones): void {
            $estudio = CandidatoSocioeconomico::query()->create([
                'candidato_id' => $c->id,
                'fecha_visita' => (string) $datos['fecha_visita'],
                'visitador_user_id' => isset($datos['visitador_user_id']) ? (int) $datos['visitador_user_id'] : $actor->id,
                'direccion' => (string) $datos['direccion'],
                'checklist' => is_array($datos['checklist'] ?? null) ? $datos['checklist'] : null,
                'riesgos' => isset($datos['riesgos']) ? (string) $datos['riesgos'] : null,
                'observaciones' => $observaciones,
                'resultado' => $resultado,
                'registrado_por' => $actor->id,
            ]);

            $this->guardarEvidencias($c, $estudio, $evidencias, $actor);

            if ($resultado === ResultadoEtapaCandidato::Viable) {
                $this->transicionar($c, EstadoCandidato::ReferenciasPendientes, $actor, $this->nota('Estudio socioeconómico viable: pasa a validación de referencias.', $observaciones));

                return;
            }

            $this->salir($c, EstadoCandidato::NoViable, $actor, $this->exigirMotivo($observaciones, 'Estudio socioeconómico no viable'));
        });
    }

    /**
     * @param  array<string, mixed>  $datos  empresa, contacto, telefono?, relacion_puesto?, resultado, observaciones?, fecha_validacion? (RegistrarReferenciaRequest).
     */
    public function registrarReferencia(Candidato $candidato, User $actor, array $datos): CandidatoReferencia
    {
        $this->autorizarAlguno($actor, [self::PERMISO_RECLUTAMIENTO, self::PERMISO_GERENTE], $candidato);

        return DB::transaction(function () use ($candidato, $actor, $datos): CandidatoReferencia {
            $c = $this->bloquear($candidato);
            $this->exigirEstado($c, EstadoCandidato::ReferenciasPendientes);

            $referencia = CandidatoReferencia::query()->create([
                'candidato_id' => $c->id,
                'empresa' => (string) $datos['empresa'],
                'contacto' => (string) $datos['contacto'],
                'telefono' => isset($datos['telefono']) ? (string) $datos['telefono'] : null,
                'relacion_puesto' => isset($datos['relacion_puesto']) ? (string) $datos['relacion_puesto'] : null,
                'resultado' => ResultadoReferencia::from((string) $datos['resultado']),
                'observaciones' => isset($datos['observaciones']) ? (string) $datos['observaciones'] : null,
                'fecha_validacion' => isset($datos['fecha_validacion']) ? (string) $datos['fecha_validacion'] : now()->toDateString(),
                'validada_por' => $actor->id,
            ]);

            $this->seguimiento($c, $actor, TipoSeguimientoCandidato::Nota, sprintf('Referencia laboral validada: %s (%s) — %s.', $referencia->empresa, $referencia->contacto, $referencia->resultado->etiqueta()));
            $this->auditar('candidato_referencia_validada', $c, $actor, ['referencia_id' => $referencia->id, 'resultado' => $referencia->resultado->value]);

            return $referencia;
        });
    }

    public function concluirReferencias(Candidato $candidato, User $actor, bool $viables, ?string $observaciones): Candidato
    {
        $this->autorizarAlguno($actor, [self::PERMISO_RECLUTAMIENTO, self::PERMISO_GERENTE], $candidato);

        return $this->paso($candidato, $actor, EstadoCandidato::ReferenciasPendientes, function (Candidato $c) use ($actor, $viables, $observaciones): void {
            if (! $viables) {
                $this->salir($c, EstadoCandidato::NoViable, $actor, $this->exigirMotivo($observaciones, 'Referencias laborales no favorables'));

                return;
            }

            if (! CandidatoReferencia::query()->where('candidato_id', $c->id)->exists()) {
                throw ValidationException::withMessages(['referencias' => 'Registra al menos una referencia laboral validada antes de continuar.']);
            }

            $this->transicionar($c, EstadoCandidato::PreseleccionGerente, $actor, $this->nota('Referencias validadas: pendiente la preselección/preautorización del gerente.', $observaciones));

            $gerente = $this->jerarquia->preautorizadorDeCandidato($c);
            $this->aprobaciones->abrir($c, ProcesoAprobacion::SeleccionCandidato, [
                'candidato' => $c,
                'solicitante' => $actor,
                'aprobador_colaborador' => $gerente['colaborador'],
                'aprobador_user' => $gerente['usuario'],
            ]);
        });
    }

    /**
     * PREAUTORIZACIÓN del gerente. No contrata ni genera QR: deja el
     * candidato esperando la autorización final de RH.
     */
    public function preautorizar(Candidato $candidato, User $actor, ?string $comentario): Candidato
    {
        $resultado = $this->paso($candidato, $actor, EstadoCandidato::PreseleccionGerente, function (Candidato $c) use ($actor, $comentario): void {
            $this->aprobaciones->preautorizar($c, ProcesoAprobacion::SeleccionCandidato, $actor, $comentario);
            $this->transicionar($c, EstadoCandidato::AutorizacionRhPendiente, $actor, $this->nota(sprintf('Preautorizado por %s. Pendiente la autorización final de RH.', $actor->nombreCompleto()), $comentario));
        });

        $this->notificador->notificar(
            $this->responsablesRh($resultado),
            'candidato_preautorizado',
            'Candidato preautorizado',
            sprintf('%s preautorizó a %s. Pendiente tu autorización final.', $actor->nombreCompleto(), $resultado->nombreCompleto()),
            $resultado,
            'autorizar_candidato',
            'alta',
        );

        return $resultado;
    }

    /**
     * AUTORIZACIÓN FINAL de RH. Solo después de esto se puede generar el QR
     * de contratación (ContratacionCandidatoService::iniciarContratacion).
     */
    public function autorizarRh(Candidato $candidato, User $actor, ?string $comentario): Candidato
    {
        return $this->paso($candidato, $actor, EstadoCandidato::AutorizacionRhPendiente, function (Candidato $c) use ($actor, $comentario): void {
            $this->aprobaciones->autorizarRh($c, ProcesoAprobacion::SeleccionCandidato, $actor, $comentario);
            $c->update(['autorizado_rh_en' => now()]);
            $this->transicionar($c, EstadoCandidato::AutorizadoRh, $actor, $this->nota(sprintf('RH (%s) autorizó la contratación.', $actor->nombreCompleto()), $comentario));
        });
    }

    public function rechazarRh(Candidato $candidato, User $actor, string $motivo): Candidato
    {
        return $this->paso($candidato, $actor, EstadoCandidato::AutorizacionRhPendiente, function (Candidato $c) use ($actor, $motivo): void {
            $this->aprobaciones->rechazar($c, ProcesoAprobacion::SeleccionCandidato, $actor, $motivo);
            $this->salir($c, EstadoCandidato::RechazadoRh, $actor, $motivo);
        });
    }

    public function devolverRh(Candidato $candidato, User $actor, string $motivo): Candidato
    {
        return $this->paso($candidato, $actor, EstadoCandidato::AutorizacionRhPendiente, function (Candidato $c) use ($actor, $motivo): void {
            $this->aprobaciones->devolver($c, ProcesoAprobacion::SeleccionCandidato, $actor, $motivo);
            $this->transicionar($c, EstadoCandidato::PreseleccionGerente, $actor, sprintf('RH devolvió la selección al gerente: %s', $motivo), retroceso: true);
        });
    }

    /**
     * Salida del proceso (desistió, no respondió, no viable, no
     * seleccionado) desde cualquier fase abierta anterior a la contratación.
     */
    public function descartar(Candidato $candidato, User $actor, EstadoCandidato $salida, string $motivo): Candidato
    {
        if (! $salida->esSalida() || $salida === EstadoCandidato::RechazadoRh) {
            throw ValidationException::withMessages(['estado' => 'Elige un motivo de salida válido.']);
        }

        $this->autorizarAlguno($actor, [self::PERMISO_DESCARTAR, self::PERMISO_RECLUTAMIENTO, self::PERMISO_GERENTE], $candidato);

        $resultado = DB::transaction(function () use ($candidato, $actor, $salida, $motivo): Candidato {
            $c = $this->bloquear($candidato);

            if (! $c->estado->permiteDescartar()) {
                throw ValidationException::withMessages(['estado' => "El candidato está en «{$c->estado->etiqueta()}»: ya no puede descartarse desde reclutamiento."]);
            }

            $this->aprobaciones->cancelarPendientes($c, ProcesoAprobacion::SeleccionCandidato, $actor, $motivo);
            $this->salir($c, $salida, $actor, $this->exigirMotivo($motivo, $salida->etiqueta()));

            return $c;
        });

        $this->abrirPendiente($resultado, $actor);

        return $resultado->refresh();
    }

    /**
     * Acciones que el usuario puede ejecutar AHORA sobre el candidato
     * (contexto del estado + permisos + organigrama). Web y app solo
     * muestran estas: nunca botones que no aplican.
     *
     * @return list<array{clave: string, etiqueta: string, tipo: string}>
     */
    public function accionesPermitidas(Candidato $candidato, User $usuario): array
    {
        if (! $this->jerarquia->alcanzaCandidato($usuario, $candidato)) {
            return [];
        }

        $acciones = [];
        $puede = fn (string $permiso): bool => $usuario->can($permiso);

        switch ($candidato->estado) {
            case EstadoCandidato::Recibidos:
                if ($puede(self::PERMISO_RECLUTAMIENTO)) {
                    $acciones[] = ['clave' => 'evaluar_perfil', 'etiqueta' => 'Revisar perfil', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::EntrevistaPendiente:
                if ($puede(self::PERMISO_GERENTE)) {
                    $acciones[] = ['clave' => 'registrar_entrevista', 'etiqueta' => 'Registrar entrevista', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::PsicometricasPendientes:
                if ($puede(self::PERMISO_RECLUTAMIENTO)) {
                    $acciones[] = ['clave' => 'enviar_psicometricas', 'etiqueta' => 'Registrar link de psicométricas', 'tipo' => 'secundaria'];
                    $acciones[] = ['clave' => 'registrar_resultados_psicometricas', 'etiqueta' => 'Registrar resultados', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::RevisionPsicometricas:
                if ($puede(self::PERMISO_GERENTE)) {
                    $acciones[] = ['clave' => 'revisar_psicometricas', 'etiqueta' => 'Revisar psicométricas', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::SocioeconomicoPendiente:
                if ($puede(self::PERMISO_GERENTE)) {
                    $acciones[] = ['clave' => 'registrar_socioeconomico', 'etiqueta' => 'Registrar estudio socioeconómico', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::ReferenciasPendientes:
                if ($puede(self::PERMISO_RECLUTAMIENTO) || $puede(self::PERMISO_GERENTE)) {
                    $acciones[] = ['clave' => 'registrar_referencia', 'etiqueta' => 'Registrar referencia', 'tipo' => 'secundaria'];
                    $acciones[] = ['clave' => 'concluir_referencias', 'etiqueta' => 'Concluir validación de referencias', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::PreseleccionGerente:
                $pendiente = $this->aprobaciones->pendiente($candidato, ProcesoAprobacion::SeleccionCandidato);

                if ($pendiente !== null && $this->jerarquia->puedePreautorizar($usuario, $pendiente)) {
                    $acciones[] = ['clave' => 'preautorizar', 'etiqueta' => 'Preautorizar contratación', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCandidato::AutorizacionRhPendiente:
                $pendiente = $this->aprobaciones->pendiente($candidato, ProcesoAprobacion::SeleccionCandidato);

                if ($pendiente !== null && $this->jerarquia->puedeAutorizarRh($usuario, $pendiente)) {
                    $acciones[] = ['clave' => 'autorizar_rh', 'etiqueta' => 'Autorizar contratación', 'tipo' => 'primaria'];
                    $acciones[] = ['clave' => 'devolver_rh', 'etiqueta' => 'Devolver al gerente', 'tipo' => 'secundaria'];
                    $acciones[] = ['clave' => 'rechazar_rh', 'etiqueta' => 'Rechazar', 'tipo' => 'peligro'];
                }
                break;
            case EstadoCandidato::AutorizadoRh:
                if ($puede(ContratacionCandidatoService::PERMISO_CONTRATAR)) {
                    $acciones[] = ['clave' => 'iniciar_contratacion', 'etiqueta' => 'Generar QR de contratación', 'tipo' => 'primaria'];
                }
                break;
            default:
                break;
        }

        if ($candidato->estado->permiteDescartar() && ($puede(self::PERMISO_DESCARTAR) || $puede(self::PERMISO_RECLUTAMIENTO) || $puede(self::PERMISO_GERENTE))) {
            $acciones[] = ['clave' => 'descartar', 'etiqueta' => 'Cerrar proceso del candidato', 'tipo' => 'peligro'];
        }

        return $acciones;
    }

    /**
     * Quién tiene la siguiente acción (para la ficha, el tablero y la app).
     *
     * @return array{rol: string, nombre: string|null}
     */
    public function responsableActual(Candidato $candidato): array
    {
        $gerente = fn (): ?string => $this->jerarquia->preautorizadorDeCandidato($candidato)['usuario']?->nombreCompleto();

        return match ($candidato->estado) {
            EstadoCandidato::Recibidos, EstadoCandidato::PsicometricasPendientes, EstadoCandidato::ReferenciasPendientes => ['rol' => 'Reclutamiento', 'nombre' => $candidato->responsableRh?->nombreCompleto()],
            EstadoCandidato::EntrevistaPendiente, EstadoCandidato::RevisionPsicometricas, EstadoCandidato::SocioeconomicoPendiente, EstadoCandidato::PreseleccionGerente => ['rol' => 'Gerente de sucursal', 'nombre' => $gerente()],
            EstadoCandidato::AutorizacionRhPendiente, EstadoCandidato::AutorizadoRh => ['rol' => 'Recursos Humanos', 'nombre' => null],
            EstadoCandidato::EnContratacion => ['rol' => 'Candidato / RH', 'nombre' => $candidato->nombreCompleto()],
            default => ['rol' => '—', 'nombre' => null],
        };
    }

    /**
     * Ejecuta un paso del workflow con bloqueo de fila y validación del
     * estado esperado; después abre el pendiente del siguiente responsable.
     *
     * @param  callable(Candidato): void  $accion
     */
    private function paso(Candidato $candidato, User $actor, EstadoCandidato $esperado, callable $accion, bool $avanza = true): Candidato
    {
        $resultado = DB::transaction(function () use ($candidato, $esperado, $accion): Candidato {
            $c = $this->bloquear($candidato);
            $this->exigirEstado($c, $esperado);
            $accion($c);

            return $c;
        });

        if ($avanza) {
            $this->abrirPendiente($resultado, $actor);
        }

        return $resultado->refresh();
    }

    private function bloquear(Candidato $candidato): Candidato
    {
        return Candidato::query()->lockForUpdate()->findOrFail($candidato->id);
    }

    private function exigirEstado(Candidato $candidato, EstadoCandidato $esperado): void
    {
        if ($candidato->estado !== $esperado) {
            throw ValidationException::withMessages([
                'estado' => "El candidato ya está en «{$candidato->estado->etiqueta()}» (se esperaba «{$esperado->etiqueta()}»). Recarga la ficha.",
            ]);
        }
    }

    private function transicionar(Candidato $candidato, EstadoCandidato $destino, User $actor, string $nota, bool $retroceso = false): void
    {
        $anterior = $candidato->estado;

        if (! $retroceso && ! $anterior->puedeAvanzarA($destino)) {
            throw ValidationException::withMessages(['estado' => "No se puede pasar de «{$anterior->etiqueta()}» a «{$destino->etiqueta()}»."]);
        }

        $candidato->update([
            'estado' => $destino,
            'etapa_maxima' => max($candidato->etapa_maxima, $destino->orden()),
        ]);

        $this->seguimiento($candidato, $actor, TipoSeguimientoCandidato::CambioEstado, $nota, $anterior, $destino);
        $this->auditar('candidato_estado', $candidato, $actor, ['estado_anterior' => $anterior->value, 'estado_nuevo' => $destino->value]);
    }

    private function salir(Candidato $candidato, EstadoCandidato $salida, User $actor, string $motivo): void
    {
        $anterior = $candidato->estado;

        $candidato->update([
            'estado' => $salida,
            'motivo_salida' => $motivo,
            'salida_en' => now(),
            'salida_por' => $actor->id,
        ]);

        $this->seguimiento($candidato, $actor, TipoSeguimientoCandidato::CambioEstado, sprintf('%s: %s', $salida->etiqueta(), $motivo), $anterior, $salida);
        $this->auditar('candidato_salida', $candidato, $actor, ['estado_anterior' => $anterior->value, 'estado_nuevo' => $salida->value, 'motivo' => $motivo]);
    }

    private function seguimiento(Candidato $candidato, User $actor, TipoSeguimientoCandidato $tipo, string $nota, ?EstadoCandidato $anterior = null, ?EstadoCandidato $nuevo = null): void
    {
        $candidato->seguimientos()->create([
            'tipo' => $tipo,
            'nota' => $nota,
            'estado_anterior' => $anterior?->value,
            'estado_nuevo' => $nuevo?->value,
            'fecha' => now(),
            'registrado_por' => $actor->id,
        ]);
    }

    private function psicometricaAbierta(Candidato $candidato): CandidatoPsicometrica
    {
        return CandidatoPsicometrica::query()
            ->where('candidato_id', $candidato->id)
            ->whereNull('revisada_en')
            ->latest('id')
            ->first() ?? CandidatoPsicometrica::query()->create(['candidato_id' => $candidato->id]);
    }

    /**
     * Evidencias privadas en el NAS (nunca URL pública; la descarga pasa por
     * un controlador con Policy + alcance).
     *
     * @param  list<UploadedFile>  $archivos
     */
    private function guardarEvidencias(Candidato $candidato, Model $evidenciable, array $archivos, User $actor): void
    {
        foreach ($archivos as $archivo) {
            $extension = strtolower($archivo->guessExtension() ?? $archivo->getClientOriginalExtension());
            $ruta = sprintf('candidatos/%d/evidencias/%s.%s', $candidato->id, Str::uuid(), $extension);
            $this->almacenamiento->guardar($archivo, $ruta);
            $mime = $archivo->getMimeType();

            CandidatoEvidencia::query()->create([
                'candidato_id' => $candidato->id,
                'evidenciable_type' => $evidenciable->getMorphClass(),
                'evidenciable_id' => $evidenciable->getKey(),
                'tipo' => TipoEvidenciaCandidato::desdeMime($mime),
                'disk' => (string) config('reclutamiento.disk'),
                'path' => $ruta,
                'original_name' => $archivo->getClientOriginalName(),
                'mime' => $mime,
                'size' => $archivo->getSize(),
                'checksum' => hash_file('sha256', (string) $archivo->getRealPath()) ?: null,
                'subida_por' => $actor->id,
            ]);
        }
    }

    /**
     * Resuelve los pendientes de reclutamiento del candidato y abre el del
     * responsable del estado actual (deduplicado por TareaService).
     */
    private function abrirPendiente(Candidato $candidato, ?User $actor = null): void
    {
        try {
            $this->tareas->resolver(TipoTarea::deReclutamiento(), $candidato, $actor);
            $definicion = $this->tareaPara($candidato);

            if ($definicion === null) {
                return;
            }

            [$tipo, $opciones] = $definicion;
            $tarea = $this->tareas->abrir($tipo, $candidato, [
                'titulo' => sprintf('%s: %s', $tipo->etiqueta(), $candidato->nombreCompleto()),
                'candidato' => $candidato,
                'sucursal_id' => $candidato->sucursal_id,
                ...$opciones,
            ]);

            $usuario = $opciones['usuario'] ?? null;

            if ($tarea !== null && $usuario instanceof User && $tarea->wasRecentlyCreated) {
                $this->notificador->notificar([$usuario], $tipo->value, $tipo->etiqueta(), sprintf('%s · %s', $candidato->nombreCompleto(), $candidato->puestoObjetivo->nombre ?? 'candidato'), $candidato, $opciones['accion'] ?? null);
            }
        } catch (Throwable $e) {
            Log::warning('CandidatoWorkflowService: no se pudo actualizar la bandeja.', ['candidato_id' => $candidato->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{0: TipoTarea, 1: array<string, mixed>}|null
     */
    private function tareaPara(Candidato $candidato): ?array
    {
        $gerente = fn (): ?User => $this->jerarquia->preautorizadorDeCandidato($candidato)['usuario'];
        $paraGerente = function (string $accion) use ($gerente): array {
            $usuario = $gerente();

            return ['usuario' => $usuario, 'permiso' => $usuario === null ? self::PERMISO_GERENTE : null, 'accion' => $accion, 'prioridad' => PrioridadTarea::Alta];
        };

        return match ($candidato->estado) {
            EstadoCandidato::Recibidos => [TipoTarea::CandidatoRevisionPerfil, ['permiso' => self::PERMISO_RECLUTAMIENTO, 'accion' => 'evaluar_perfil']],
            EstadoCandidato::EntrevistaPendiente => [TipoTarea::CandidatoEntrevista, $paraGerente('registrar_entrevista')],
            EstadoCandidato::PsicometricasPendientes => [TipoTarea::CandidatoPsicometricas, ['permiso' => self::PERMISO_RECLUTAMIENTO, 'accion' => 'registrar_resultados_psicometricas']],
            EstadoCandidato::RevisionPsicometricas => [TipoTarea::CandidatoRevisionPsicometricas, $paraGerente('revisar_psicometricas')],
            EstadoCandidato::SocioeconomicoPendiente => [TipoTarea::CandidatoSocioeconomico, $paraGerente('registrar_socioeconomico')],
            EstadoCandidato::ReferenciasPendientes => [TipoTarea::CandidatoReferencias, ['permiso' => self::PERMISO_RECLUTAMIENTO, 'accion' => 'concluir_referencias']],
            EstadoCandidato::PreseleccionGerente => [TipoTarea::CandidatoPreautorizacion, $paraGerente('preautorizar')],
            EstadoCandidato::AutorizacionRhPendiente => [TipoTarea::CandidatoAutorizacionRh, ['permiso' => OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH, 'accion' => 'autorizar_rh', 'prioridad' => PrioridadTarea::Alta]],
            EstadoCandidato::AutorizadoRh => [TipoTarea::CandidatoInvitacion, ['permiso' => ContratacionCandidatoService::PERMISO_CONTRATAR, 'accion' => 'iniciar_contratacion', 'prioridad' => PrioridadTarea::Alta]],
            default => null,
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function responsablesRh(Candidato $candidato): Collection
    {
        return User::query()
            ->permission(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH)
            ->whereNull('acceso_bloqueado_en')
            ->get()
            ->filter(fn (User $u) => $this->jerarquia->alcanzaCandidato($u, $candidato))
            ->values();
    }

    private function autorizar(User $actor, string $permiso, Candidato $candidato): void
    {
        $this->autorizarAlguno($actor, [$permiso], $candidato);
    }

    /**
     * @param  list<string>  $permisos
     */
    private function autorizarAlguno(User $actor, array $permisos, Candidato $candidato): void
    {
        $tienePermiso = collect($permisos)->contains(fn (string $p) => $actor->can($p));

        if (! $tienePermiso || ! $this->jerarquia->alcanzaCandidato($actor, $candidato)) {
            throw new AuthorizationException('No tienes permiso para realizar esta acción sobre este candidato.');
        }
    }

    private function exigirMotivo(?string $motivo, string $contexto): string
    {
        $motivo = trim((string) $motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['observaciones' => "Indica el motivo ({$contexto})."]);
        }

        return $motivo;
    }

    private function nota(string $base, ?string $observaciones): string
    {
        $observaciones = trim((string) $observaciones);

        return $observaciones === '' ? $base : "{$base} {$observaciones}";
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditar(string $accion, Candidato $candidato, User $actor, array $extra = []): void
    {
        $this->auditoria->registrar($accion, $candidato, $actor, ['candidato_id' => $candidato->id, ...$extra]);
    }
}
