<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\EntregaAvisoRescision;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\Recontratable;
use App\Models\Baja;
use App\Models\BajaBitacora;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CierreLaboral\CausalRotacionService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Finiquitos\FiniquitoService;
use App\Support\FechaMexico;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Flujo de la baja (especificación de RH, docs/BAJAS_Y_CAUSALES.md). Lo usan
 * la web (Rh\BajaController) y la API v1 (Api\V1\BajaController): una sola
 * fuente de reglas.
 *
 * La baja es DIRECTA: el gerente (su personal, por alcance) o RH la
 * REGISTRA y queda aplicada en ese mismo momento —sin borrador, sin «enviar
 * a RH» y sin aprobación—: expediente de la baja con folio, cierre laboral
 * «Baja aplicada», acceso suspendido, documentos de la forma/causal
 * generados y finiquito propuesto. Lo que sigue: completar/adjuntar
 * documentos, revisión de Jurídico si la causal lo requiere (RN-06), el
 * finiquito y el CIERRE por RH (documentos obligatorios completos, RN-05):
 * colaborador Inactivo + bloqueo definitivo. RH puede marcarla «No
 * procede» (devuelve el acceso) o reabrirla con motivo.
 *
 * Nunca borra usuarios, colaboradores ni expedientes. Todo cambio queda en
 * la bitácora (RN-13).
 */
class BajaService
{
    public const PERMISO_VER = 'bajas.ver';

    public const PERMISO_SOLICITAR = 'bajas.solicitar';

    public const PERMISO_GESTIONAR = 'bajas.gestionar';

    // Acciones de RH sobre una baja, cada una con su permiso (Roles y
    // permisos → Bajas). Heredan de bajas.gestionar al crearse.
    public const PERMISO_CAMBIAR_CAUSAL = 'bajas.cambiar_causal';

    public const PERMISO_EDITAR = 'bajas.editar';

    public const PERMISO_ENVIAR_JURIDICO = 'bajas.enviar_juridico';

    public const PERMISO_CERRAR = 'bajas.cerrar';

    public const PERMISO_REABRIR = 'bajas.reabrir';

    public const PERMISO_CANCELAR = 'bajas.cancelar';

    public const PERMISO_ADJUNTAR = 'bajas.documentos.adjuntar';

    public const PERMISO_PREVIA_FINIQUITO = 'bajas.finiquito.vista_previa';

    public const PERMISO_JURIDICO = 'bajas.revisar_juridico';

    public const PERMISO_CONTRALORIA = 'bajas.consulta_contraloria';

    public const PERMISO_TABLERO = 'bajas.tablero.ver';

    public function __construct(
        private readonly BajaRegistroService $registro,
        private readonly BajaReglasService $reglas,
        private readonly BajaDocumentosService $documentos,
        private readonly BajaAlertasService $alertas,
        private readonly BajaConsultaService $consulta,
        private readonly CierreLaboralService $cierres,
        private readonly CausalRotacionService $causales,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FiniquitoService $finiquitos,
    ) {}

    /**
     * REGISTRA la baja y la aplica de inmediato (RN-01…RN-11 completas antes
     * de tocar nada). En una transacción: expediente con folio, cierre
     * laboral aplicado, acceso suspendido y documentos de la causa
     * generados. Después (sin deshacer la baja si falla): finiquito
     * propuesto y avisos por la capa única de notificaciones.
     *
     * @param  array<string, mixed>  $datos  colaborador_id, forma_salida, causal_rotacion_id, fecha_baja, recontratable, comentarios, fecha_conocimiento_falta, entrega_aviso_rescision, fecha_entrega_aviso
     */
    public function registrar(array $datos, User $actor): Baja
    {
        $colaborador = $this->colaboradorVigente($datos, $actor);
        $baja = $this->registro->preparar($colaborador, [], $actor);
        $this->aplicarCaptura($baja, $datos, $actor);
        $this->validarCaptura($baja);
        $baja->unsetRelation('causal');
        $this->reglas->exigirCompleta($baja);
        $this->exigirFormaPermitida($baja, $actor);

        $baja = DB::transaction(function () use ($baja, $colaborador, $actor): Baja {
            Colaborador::query()->lockForUpdate()->where('id', $colaborador->id)->first();
            $this->registro->exigirSinBajaAbierta($colaborador);

            // Flujo sin Jurídico (propuesta Etapa 1): la baja se registra «En
            // trámite» sin importar la causal. Jurídico ya no autoriza; si la
            // causal pide integrar expediente probatorio es solo un aviso.
            $baja->folio = $this->registro->siguienteFolio((int) now()->year);
            $baja->forceFill([
                'estatus' => EstatusBaja::EnRevisionRh,
                'solicitado_por' => $actor->id,
                'enviado_en' => now(),
            ])->save();

            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::Registro, 'estatus', null, $baja->estatus->etiqueta());

            // Cierre laboral aplicado: solicitud (folio del trámite), cierre
            // «Baja aplicada», acceso suspendido y documentos de la causa.
            $cierre = $this->cierres->aplicarDesdeBaja($baja, $actor);

            $baja->forceFill([
                'cierre_laboral_id' => $cierre->id,
                'acceso_suspendido_en' => $cierre->refresh()->acceso_suspendido_en ?? $baja->acceso_suspendido_en,
            ])->save();

            return $baja;
        });

        $this->proponerFiniquito($baja, $actor);
        $this->alertas->alRegistrar($baja->refresh(), $actor);

        return $baja;
    }

    /**
     * Vista previa del FINIQUITO que se propondrá al registrar la baja, con
     * los datos del formulario y el salario registrado. Se arma en memoria:
     * no guarda nada (ni baja, ni solicitud, ni finiquito).
     *
     * @param  array<string, mixed>  $datos  colaborador_id, forma_salida, causal_rotacion_id?, fecha_baja?
     */
    public function vistaPreviaFiniquito(array $datos, User $actor): string
    {
        $this->exigirPermiso($actor, self::PERMISO_PREVIA_FINIQUITO);
        $colaborador = $this->colaboradorVigente($datos, $actor);
        $forma = FormaSalida::tryFrom((string) ($datos['forma_salida'] ?? ''));

        if ($forma === null) {
            throw ValidationException::withMessages(['forma_salida' => 'Elige cómo se va la persona para ver su finiquito.']);
        }

        $causalId = $datos['causal_rotacion_id'] ?? null;
        $causal = is_numeric($causalId) ? CausalRotacion::query()->activas()->where('id', (int) $causalId)->first() : null;
        $causal = $causal !== null && $causal->forma() === $forma ? $causal : null;
        $fecha = is_string($datos['fecha_baja'] ?? null) && $datos['fecha_baja'] !== '' ? Carbon::parse($datos['fecha_baja'])->startOfDay() : FechaMexico::inicioDeHoy();

        $solicitud = new SolicitudInterna;
        $solicitud->forceFill([
            'folio' => 'Vista previa',
            'tipo_baja' => $forma->tipoBaja(),
            'causal_rotacion_id' => $causal?->id,
            'fecha_efectiva' => $fecha->toDateString(),
        ]);
        $solicitud->setRelation('causalRotacion', $causal);

        $sueldo = $this->finiquitos->sueldoMensualSugerido($colaborador);

        if ($sueldo <= 0) {
            throw ValidationException::withMessages(['finiquito' => sprintf('%s no tiene salario registrado: el finiquito lo calcula RH capturando el sueldo.', $colaborador->nombreCompleto())]);
        }

        return $this->finiquitos->vistaPreviaPdf($this->finiquitos->propuestaEnMemoria($solicitud, $colaborador, $sueldo));
    }

    /**
     * Compatibilidad (API v1 «enviar»): la baja ya quedó aplicada al
     * registrarla; no hay paso de envío.
     */
    public function enviar(Baja $baja, User $actor): Baja
    {
        if ($baja->estatus !== EstatusBaja::EnRevisionRh) {
            throw ValidationException::withMessages(['estatus' => sprintf('La baja está «%s»: ya no hay nada que enviar.', $baja->estatus->etiqueta())]);
        }

        if (! $this->puedeVerComoResponsable($baja, $actor)) {
            throw new AuthorizationException('No puedes modificar esta baja.');
        }

        return $baja;
    }

    /**
     * COMPUERTA RETIRADA (propuesta Etapa 1): Jurídico ya no autoriza bajas.
     * Se conserva la firma por compatibilidad de rutas (web/API), pero el
     * flujo de envío a Jurídico se desactivó: la baja se registra y se cierra
     * directamente. Jurídico mantiene acceso de consulta (BajaConsultaService).
     */
    public function enviarJuridico(Baja $baja, User $actor): Baja
    {
        throw ValidationException::withMessages(['estatus' => 'El flujo de revisión de Jurídico se retiró: la baja se registra y se cierra directamente.']);
    }

    /**
     * COMPUERTA RETIRADA (propuesta Etapa 1): ver enviarJuridico().
     */
    public function aprobarJuridico(Baja $baja, User $actor, ?string $comentario): Baja
    {
        throw ValidationException::withMessages(['estatus' => 'El flujo de revisión de Jurídico se retiró: la baja se registra y se cierra directamente.']);
    }

    /**
     * COMPUERTA RETIRADA (propuesta Etapa 1): ver enviarJuridico().
     */
    public function devolverJuridico(Baja $baja, User $actor, string $motivo): Baja
    {
        throw ValidationException::withMessages(['estatus' => 'El flujo de revisión de Jurídico se retiró: la baja se registra y se cierra directamente.']);
    }

    /**
     * RH corrige la causal (también de otra forma de salida) con motivo; el
     * cambio queda en la bitácora con usuario, fecha, valor anterior y nuevo
     * (criterio de aceptación 8). Flujo sin Jurídico (propuesta Etapa 1):
     * cambiar a una causal que pide expediente probatorio ya NO reenvía a
     * Jurídico; la baja sigue en trámite.
     */
    public function cambiarCausal(Baja $baja, User $actor, int $causalId, string $motivo): Baja
    {
        $this->exigirPermiso($actor, self::PERMISO_CAMBIAR_CAUSAL);
        $this->exigirEstatus($baja, [EstatusBaja::EnRevisionRh]);
        $motivo = $this->exigirMotivo($motivo);
        $nueva = CausalRotacion::query()->activas()->where('id', $causalId)->first();

        if ($nueva === null) {
            throw ValidationException::withMessages(['causal_rotacion_id' => 'Esa causal no existe o ya no está activa.']);
        }

        $baja->loadMissing('causal');
        $anterior = $baja->causal;

        if ($anterior !== null && $anterior->id === $nueva->id) {
            throw ValidationException::withMessages(['causal_rotacion_id' => 'Es la misma causal que ya tiene la baja.']);
        }

        DB::transaction(function () use ($baja, $actor, $nueva, $anterior, $motivo): void {
            $forma = $nueva->forma();
            $formaAnterior = $baja->forma_salida;
            $cambios = ['causal_rotacion_id' => $nueva->id, 'forma_salida' => $forma, 'causal_migrada_de' => null];

            // RN-09: al pasar a rescisión, «No» por defecto si no estaba capturado.
            if ($forma === FormaSalida::Rescision && $formaAnterior !== FormaSalida::Rescision && $baja->recontratable === null) {
                $cambios['recontratable'] = Recontratable::No;
            }

            if ($forma !== FormaSalida::Rescision) {
                $cambios += ['fecha_conocimiento_falta' => null, 'entrega_aviso_rescision' => null, 'fecha_entrega_aviso' => null];
            }

            $baja->update($cambios);
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::CambioCausal, 'causal', $anterior !== null ? sprintf('%s (%s)', $anterior->etiquetaConClave(), $formaAnterior?->etiqueta() ?? '—') : 'Sin causal', sprintf('%s (%s)', $nueva->etiquetaConClave(), $forma->etiqueta()), $motivo);

            if ($baja->cierre !== null) {
                $this->cierres->sincronizarDesdeBaja($baja->cierre, $baja->refresh(), $actor);
            }
        });

        $this->alertas->alertas($baja->refresh(), $actor);

        return $baja;
    }

    /**
     * RH completa o corrige datos de la baja en trámite (fecha, comentario,
     * recontratable —RN-09: solo RH lo cambia en rescisión—, datos del aviso
     * de rescisión) y marca si cuenta en rotación (movimiento interno). Todo
     * con bitácora.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarPorRh(Baja $baja, User $actor, array $datos): Baja
    {
        $this->exigirPermiso($actor, self::PERMISO_EDITAR);
        $this->exigirEstatus($baja, [EstatusBaja::EnRevisionRh, EstatusBaja::EnRevisionJuridico]);
        $baja->loadMissing('causal');

        DB::transaction(function () use ($baja, $actor, $datos): void {
            $antes = $this->instantanea($baja);
            $this->aplicarCaptura($baja, array_diff_key($datos, array_flip(['forma_salida', 'causal_rotacion_id', 'colaborador_id'])), $actor);

            if (array_key_exists('es_rotacion', $datos) && $datos['es_rotacion'] !== null) {
                $baja->es_rotacion = (bool) $datos['es_rotacion'];
            }

            $this->validarCaptura($baja);
            $baja->save();
            $this->registrarCambios($baja, $actor, $antes, $this->textoOpcional($datos['motivo_cambio'] ?? null));

            if ($baja->cierre !== null && $baja->wasChanged('fecha_baja')) {
                $this->cierres->sincronizarDesdeBaja($baja->cierre, $baja, $actor);
            }
        });

        $this->alertas->alertas($baja->refresh(), $actor);

        return $baja;
    }

    /**
     * RH cierra la baja (RN-05, RN-06, RN-11): datos completos, Jurídico
     * aprobado si la causal lo requiere, documentos obligatorios completos y
     * ya pasó el último día laborado. El colaborador queda Inactivo y su
     * acceso bloqueado de forma definitiva (cuenta, tokens Sanctum,
     * dispositivos). Nunca se borra nada.
     */
    public function cerrar(Baja $baja, User $actor): Baja
    {
        $this->exigirPermiso($actor, self::PERMISO_CERRAR);

        // Flujo sin Jurídico (propuesta Etapa 1): el cierre ya no depende de una
        // aprobación de Jurídico; solo de los documentos obligatorios completos,
        // el último día laborado y el finiquito.
        $this->exigirEstatus($baja, [EstatusBaja::EnRevisionRh]);
        $baja->loadMissing(['causal', 'cierre']);
        $this->reglas->exigirCompleta($baja);

        $faltantes = $this->documentos->faltantes($baja);

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['documentos' => sprintf('No se puede cerrar: faltan documentos obligatorios (%s).', implode(', ', $faltantes))]);
        }

        if ($baja->fecha_baja !== null && $baja->fecha_baja->isAfter(FechaMexico::inicioDeHoy())) {
            throw ValidationException::withMessages(['fecha_baja' => sprintf('El último día laborado es el %s: la baja se cierra a partir de esa fecha.', $baja->fecha_baja->format('d/m/Y'))]);
        }

        // Si la baja laboral aún no se ejecutó (normalmente la ejecuta la
        // entrega del finiquito), RH debe tener el finiquito calculado y
        // revisado: la baja no se aplica sin su finiquito.
        $cierre = $baja->cierre;

        if ($cierre !== null && ! in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado], true)) {
            $finiquito = $this->cierres->finiquito($cierre);

            if (config('finiquitos.exigir_finiquito_revisado_para_aprobar_baja') && ! $actor->can('solicitudes.bajas.omitir_finiquito')
                && ($finiquito === null || ! in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado, EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true))) {
                throw ValidationException::withMessages(['finiquito' => 'Antes de cerrar la baja, revisa el finiquito o registra su entrega (Bajas y finiquitos). Al registrar su entrega, la baja laboral se ejecuta.']);
            }
        }

        DB::transaction(function () use ($baja, $actor): void {
            $cierre = $baja->cierre;

            // Colaborador Inactivo + bloqueo definitivo (BajaColaboradorService
            // vía el cierre: estatus, cuenta, tokens, dispositivos, vacante).
            if ($cierre !== null && ! in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado], true)) {
                $this->cierres->ejecutarBaja($cierre, $actor, exigirFiniquito: false);
            }

            $baja->update(['estatus' => EstatusBaja::Cerrada, 'cerrado_por' => $actor->id, 'cerrado_en' => now(), 'validado_por' => $baja->validado_por ?? $actor->id, 'validado_en' => $baja->validado_en ?? now()]);
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::Cerro, 'estatus', EstatusBaja::EnRevisionRh->etiqueta(), EstatusBaja::Cerrada->etiqueta());
        });

        $this->alertas->resultado($baja, $actor, sprintf('Baja cerrada: %s', $baja->folio), sprintf('RH cerró la baja de %s.', $baja->nombre_colaborador));

        return $baja->refresh();
    }

    /**
     * RN-13: una baja cerrada no se edita; RH puede reabrirla con motivo
     * (regresa a «En trámite»; el colaborador sigue inactivo).
     */
    public function reabrir(Baja $baja, User $actor, string $motivo): Baja
    {
        $this->exigirPermiso($actor, self::PERMISO_REABRIR);
        $this->exigirEstatus($baja, [EstatusBaja::Cerrada]);
        $motivo = $this->exigirMotivo($motivo);

        DB::transaction(function () use ($baja, $actor, $motivo): void {
            $baja->update(['estatus' => EstatusBaja::EnRevisionRh, 'cerrado_por' => null, 'cerrado_en' => null]);
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::Reabrio, 'estatus', EstatusBaja::Cerrada->etiqueta(), EstatusBaja::EnRevisionRh->etiqueta(), $motivo);
        });

        return $baja->refresh();
    }

    /**
     * «No procede» (solo RH): revierte la baja abierta —la persona sigue
     * laborando—, cancela su cierre laboral y le devuelve el acceso. Nunca se
     * borra nada.
     */
    public function cancelar(Baja $baja, User $actor, string $motivo): Baja
    {
        $this->exigirPermiso($actor, self::PERMISO_CANCELAR);

        if (! $baja->estatus->esAbierta()) {
            throw ValidationException::withMessages(['estatus' => sprintf('La baja ya está «%s».', $baja->estatus->etiqueta())]);
        }

        $motivo = $this->exigirMotivo($motivo);

        DB::transaction(function () use ($baja, $actor, $motivo): void {
            $cierre = $baja->cierre;

            if ($cierre !== null && ! $cierre->estado->esFinal()) {
                if ($cierre->estado === EstadoCierreLaboral::BajaEjecutada) {
                    throw ValidationException::withMessages(['estatus' => 'La baja laboral ya se ejecutó (colaborador inactivo): para regresarlo usa Reingresos.']);
                }

                // Cancela el cierre (devuelve el acceso) y marca la baja «No procede».
                $this->cierres->cancelar($cierre, $actor, $motivo);
            }

            $baja->refresh();

            if ($baja->estatus->esAbierta()) {
                $anterior = $baja->estatus->etiqueta();
                $baja->update(['estatus' => EstatusBaja::Cancelada, 'cancelado_por' => $actor->id, 'cancelado_en' => now(), 'motivo_cancelacion' => $motivo]);
                $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::Cancelo, 'estatus', $anterior, EstatusBaja::Cancelada->etiqueta(), $motivo);
            }
        });

        $this->alertas->resultado($baja, $actor, sprintf('Baja no procede: %s', $baja->folio), sprintf('La baja de %s se marcó como «No procede»: %s', $baja->nombre_colaborador, $motivo));

        return $baja->refresh();
    }

    /**
     * Acciones que el usuario puede ejecutar sobre la baja (para la UI; el
     * backend vuelve a validar cada una).
     *
     * @return list<string>
     */
    public function acciones(Baja $baja, User $usuario): array
    {
        $acciones = [];
        $rh = $usuario->can(self::PERMISO_GESTIONAR);
        // Flujo sin Jurídico (propuesta Etapa 1): la única baja abierta vigente
        // es «En trámite»; ya no hay estado «En revisión Jurídico».
        $abiertaVigente = $baja->estatus === EstatusBaja::EnRevisionRh;

        // Completar / adjuntar documentos: RH y el gerente con alcance.
        if ($abiertaVigente && $usuario->can(self::PERMISO_ADJUNTAR) && ($rh || $this->puedeOperarComoSolicitante($baja, $usuario))) {
            $acciones[] = 'adjuntar';
        }

        // Acciones de RH: cada una con su permiso (BajaPolicy valida igual).
        if ($abiertaVigente && $usuario->can(self::PERMISO_CERRAR)) {
            $acciones[] = 'cerrar';
        }

        if ($abiertaVigente) {
            foreach (['cambiar_causal' => self::PERMISO_CAMBIAR_CAUSAL, 'actualizar' => self::PERMISO_EDITAR, 'cancelar' => self::PERMISO_CANCELAR] as $accion => $permiso) {
                if ($usuario->can($permiso)) {
                    $acciones[] = $accion;
                }
            }
        }

        if ($baja->estatus === EstatusBaja::Cerrada && $usuario->can(self::PERMISO_REABRIR)) {
            $acciones[] = 'reabrir';
        }

        return array_values(array_unique($acciones));
    }

    /**
     * Detalle del expediente de la baja.
     *
     * @return array<string, mixed>
     */
    public function detalle(Baja $baja, User $usuario): array
    {
        $baja->loadMissing(['causal', 'sucursal:id,nombre', 'puesto:id,nombre', 'cierre', 'solicitante:id,name,apellidos', 'creador:id,name,apellidos']);
        $bitacora = BajaBitacora::query()->where('baja_id', $baja->id)->with('usuario:id,name,apellidos')->orderByDesc('id')->get();

        return [
            ...$this->consulta->fila($baja),
            'colaborador_id' => $baja->colaborador_id,
            'fecha_ingreso' => $baja->fecha_ingreso?->toDateString(),
            'antiguedad_dias' => $baja->antiguedad_dias,
            'causal_rotacion_id' => $baja->causal_rotacion_id,
            'causal_clave' => $baja->causal?->clave,
            'causal_nombre' => $baja->causal?->nombre,
            'causal_ayuda' => $baja->causal?->descripcion,
            'causal_migrada_de' => $baja->causal_migrada_de,
            'comentarios' => $baja->comentarios,
            'recontratable' => $baja->recontratable?->value,
            'recontratable_etiqueta' => $baja->recontratable?->etiqueta(),
            'fecha_conocimiento_falta' => $baja->fecha_conocimiento_falta?->toDateString(),
            'entrega_aviso_rescision' => $baja->entrega_aviso_rescision?->value,
            'entrega_aviso_rescision_etiqueta' => $baja->entrega_aviso_rescision?->etiqueta(),
            'fecha_entrega_aviso' => $baja->fecha_entrega_aviso?->toDateString(),
            'es_rotacion' => $baja->es_rotacion,
            'origen_etiqueta' => $baja->origen->etiqueta(),
            'motivo_original' => $baja->motivo_original,
            'motivo_rechazo' => $baja->motivo_rechazo,
            'rechazado_area' => $baja->rechazado_area,
            'motivo_cancelacion' => $baja->motivo_cancelacion,
            'validado_en' => $baja->validado_en?->toDateTimeString(),
            'juridico_en' => $baja->juridico_en?->toDateTimeString(),
            'cerrado_en' => $baja->cerrado_en?->toDateTimeString(),
            'acceso_suspendido_en' => $baja->acceso_suspendido_en?->toDateTimeString(),
            'solicitado_por' => $baja->solicitante !== null ? trim(sprintf('%s %s', $baja->solicitante->name, $baja->solicitante->apellidos)) : null,
            'cierre' => $baja->cierre !== null ? [
                'id' => $baja->cierre->id,
                'estado' => $baja->cierre->estado->value,
                'estado_etiqueta' => $baja->cierre->estado->etiqueta(),
                'colaborador_id' => $baja->cierre->colaborador_id,
            ] : null,
            'errores' => $baja->estatus->esAbierta() ? $this->reglas->errores($baja) : [],
            'advertencias' => $this->reglas->advertencias($baja),
            'documentos' => $this->documentos->espacios($baja),
            'faltantes' => $this->documentos->faltantes($baja),
            'acciones' => $this->acciones($baja, $usuario),
            'bitacora' => array_values($bitacora->map(fn (BajaBitacora $b): array => [
                'id' => $b->id,
                'accion' => $b->accion->value,
                'accion_etiqueta' => $b->accion->etiqueta(),
                'campo' => $b->campo,
                'valor_anterior' => $b->valor_anterior,
                'valor_nuevo' => $b->valor_nuevo,
                'motivo' => $b->motivo,
                'usuario' => $b->usuario !== null ? trim(sprintf('%s %s', $b->usuario->name, $b->usuario->apellidos)) : 'Sistema',
                'fecha' => $b->created_at?->toDateTimeString(),
            ])->all()),
        ];
    }

    /**
     * Gerente (bajas.solicitar + alcance del módulo «bajas»: su personal /
     * sucursal / región) o RH; nunca sobre sí mismo.
     */
    public function puedeSolicitarPara(User $actor, Colaborador $colaborador): bool
    {
        if ($actor->colaborador_id === $colaborador->id) {
            return false;
        }

        if ($actor->can(self::PERMISO_GESTIONAR)) {
            return true;
        }

        return $actor->can(self::PERMISO_SOLICITAR) && $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, BajaConsultaService::MODULO);
    }

    /**
     * Finiquito propuesto al registrar la baja (salario registrado y fecha de
     * baja). Si no se puede (sin salario, sin fecha de ingreso…) la baja
     * sigue aplicada y queda el pendiente «Finiquito pendiente» para RH.
     */
    private function proponerFiniquito(Baja $baja, User $actor): void
    {
        $cierre = $baja->cierre()->first();

        if ($cierre === null) {
            return;
        }

        try {
            $this->cierres->calcularFiniquito($cierre, $actor);
        } catch (Throwable $e) {
            Log::warning('BajaService: no se pudo proponer el finiquito al registrar la baja; RH lo calcula después.', ['baja_id' => $baja->id, 'cierre_id' => $cierre->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function colaboradorVigente(array $datos, User $actor): Colaborador
    {
        $colaborador = Colaborador::query()->where('id', (int) ($datos['colaborador_id'] ?? 0))->first();

        if ($colaborador === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'Elige al colaborador (busca por nombre o número de empleado).']);
        }

        $this->exigirPuedeSolicitar($actor, $colaborador);

        if (! in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true)) {
            throw ValidationException::withMessages(['colaborador_id' => sprintf('%s ya no está activo.', $colaborador->nombreCompleto())]);
        }

        return $colaborador;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function aplicarCaptura(Baja $baja, array $datos, User $actor): void
    {
        $esRh = $actor->can(self::PERMISO_GESTIONAR);

        if (array_key_exists('forma_salida', $datos)) {
            $forma = FormaSalida::tryFrom((string) $datos['forma_salida']);

            // RN-02: si cambia la forma se limpia la causal.
            if ($forma !== $baja->forma_salida) {
                $baja->forma_salida = $forma;
                $baja->causal_rotacion_id = null;
                $baja->setRelation('causal', null);
            }
        }

        if (array_key_exists('causal_rotacion_id', $datos)) {
            $baja->causal_rotacion_id = is_numeric($datos['causal_rotacion_id']) ? (int) $datos['causal_rotacion_id'] : null;
            $baja->unsetRelation('causal');
        }

        if (array_key_exists('fecha_baja', $datos)) {
            $baja->fecha_baja = $this->fechaOpcional($datos['fecha_baja']);
            $this->registro->recalcularAntiguedad($baja);
        }

        if (array_key_exists('comentarios', $datos)) {
            $texto = $this->textoOpcional($datos['comentarios']);
            $baja->comentarios = $texto !== null ? Str::limit($texto, (int) config('bajas.reglas.comentario_maximo', 500), '') : null;
        }

        $esRescision = $baja->forma_salida === FormaSalida::Rescision;

        if (array_key_exists('recontratable', $datos)) {
            $valor = Recontratable::tryFrom((string) $datos['recontratable']);

            // RN-09: en rescisión el valor es «No» y solo RH lo cambia.
            $baja->recontratable = $esRescision && ! $esRh ? Recontratable::No : $valor;
        }

        if ($esRescision) {
            $baja->recontratable ??= Recontratable::No;

            if (! $esRh && $baja->recontratable !== Recontratable::No) {
                $baja->recontratable = Recontratable::No;
            }

            foreach (['fecha_conocimiento_falta', 'fecha_entrega_aviso'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $baja->{$campo} = $this->fechaOpcional($datos[$campo]);
                }
            }

            if (array_key_exists('entrega_aviso_rescision', $datos)) {
                $baja->entrega_aviso_rescision = EntregaAvisoRescision::tryFrom((string) $datos['entrega_aviso_rescision']);
            }
        } else {
            $baja->fecha_conocimiento_falta = null;
            $baja->entrega_aviso_rescision = null;
            $baja->fecha_entrega_aviso = null;
        }
    }

    /**
     * Lo que se valida de la captura además de la completitud: RN-02 y RN-10.
     */
    private function validarCaptura(Baja $baja): void
    {
        $errores = [];
        $causal = $baja->causal_rotacion_id !== null ? CausalRotacion::query()->where('id', $baja->causal_rotacion_id)->first() : null;

        if ($baja->causal_rotacion_id !== null && ($causal === null || $causal->legado || ! $causal->activo)) {
            $errores['causal_rotacion_id'] = 'Esa causal no existe o ya no está activa.';
        } elseif ($causal !== null && $baja->forma_salida !== null && $causal->forma() !== $baja->forma_salida) {
            $errores['causal_rotacion_id'] = sprintf('«%s» no es una causal de %s.', $causal->nombre, mb_strtolower($baja->forma_salida->etiqueta()));
        } elseif ($causal !== null && $baja->forma_salida === null) {
            $baja->forma_salida = $causal->forma();
        }

        if ($baja->fecha_baja !== null) {
            $error = $this->reglas->errorFechaBaja($baja);

            if ($error !== null) {
                $errores['fecha_baja'] = $error;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function instantanea(Baja $baja): array
    {
        $causal = $baja->causal_rotacion_id !== null ? CausalRotacion::query()->where('id', $baja->causal_rotacion_id)->first() : null;

        return [
            'forma_salida' => $baja->forma_salida?->etiqueta(),
            'causal' => $causal?->etiquetaConClave(),
            'fecha_baja' => $baja->fecha_baja?->format('d/m/Y'),
            'recontratable' => $baja->recontratable?->etiqueta(),
            'comentarios' => $baja->comentarios,
            'fecha_conocimiento_falta' => $baja->fecha_conocimiento_falta?->format('d/m/Y'),
            'entrega_aviso_rescision' => $baja->entrega_aviso_rescision?->etiqueta(),
            'fecha_entrega_aviso' => $baja->fecha_entrega_aviso?->format('d/m/Y'),
            'es_rotacion' => $baja->es_rotacion ? 'Sí' : 'No (movimiento interno)',
        ];
    }

    /**
     * Una entrada de bitácora por campo que cambió (valor anterior y nuevo).
     *
     * @param  array<string, string|null>  $antes
     */
    private function registrarCambios(Baja $baja, User $actor, array $antes, ?string $motivo): void
    {
        $despues = $this->instantanea($baja);

        foreach ($despues as $campo => $nuevo) {
            $anterior = $antes[$campo] ?? null;

            if ($anterior === $nuevo) {
                continue;
            }

            $accion = match ($campo) {
                'causal' => AccionBitacoraBaja::CambioCausal,
                'recontratable' => AccionBitacoraBaja::CambioRecontratable,
                'es_rotacion' => AccionBitacoraBaja::CambioRotacion,
                default => AccionBitacoraBaja::Actualizo,
            };

            $this->registro->bitacora($baja, $actor, $accion, $campo, $anterior, $nuevo, $motivo);
        }
    }

    private function exigirPuedeSolicitar(User $actor, Colaborador $colaborador): void
    {
        if ($actor->colaborador_id === $colaborador->id) {
            throw new AuthorizationException('No puedes registrar tu propia baja: si deseas renunciar, platícalo con tu jefe o con RH.');
        }

        if (! $this->puedeSolicitarPara($actor, $colaborador)) {
            throw new AuthorizationException('Solo puedes registrar la baja de personal de tu sucursal o equipo.');
        }
    }

    /**
     * Gerente con alcance sobre la persona (completa documentos de la baja).
     */
    private function puedeOperarComoSolicitante(Baja $baja, User $usuario): bool
    {
        $colaborador = $baja->colaborador;

        return $colaborador !== null && $usuario->can(self::PERMISO_SOLICITAR) && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, BajaConsultaService::MODULO);
    }

    private function puedeVerComoResponsable(Baja $baja, User $usuario): bool
    {
        return $usuario->can(self::PERMISO_GESTIONAR) || $baja->creado_por === $usuario->id || $this->puedeOperarComoSolicitante($baja, $usuario);
    }

    /**
     * Formas que el usuario puede registrar (ciclo_laboral.cierre.causas_solicitables).
     */
    private function exigirFormaPermitida(Baja $baja, User $actor): void
    {
        if ($baja->forma_salida !== null && ! in_array($baja->forma_salida, $this->causales->formasPermitidas($actor), true)) {
            throw ValidationException::withMessages(['forma_salida' => sprintf('No puedes registrar una baja por %s: pídela a RH.', mb_strtolower($baja->forma_salida->etiqueta()))]);
        }
    }

    private function exigirPermiso(User $actor, string $permiso): void
    {
        if (! $actor->can($permiso)) {
            throw new AuthorizationException('No tienes permiso para esta acción de la baja.');
        }
    }

    /**
     * @param  list<EstatusBaja>  $permitidos
     */
    private function exigirEstatus(Baja $baja, array $permitidos): void
    {
        if (! in_array($baja->estatus, $permitidos, true)) {
            throw ValidationException::withMessages(['estatus' => sprintf('La baja está «%s»: esta acción no aplica ahora.', $baja->estatus->etiqueta())]);
        }
    }

    private function exigirMotivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo (al menos 5 caracteres).']);
        }

        return Str::limit($motivo, 1000, '');
    }

    private function textoOpcional(mixed $texto): ?string
    {
        $texto = is_string($texto) ? trim($texto) : '';

        return $texto === '' ? null : $texto;
    }

    private function fechaOpcional(mixed $valor): ?Carbon
    {
        return is_string($valor) && $valor !== '' ? Carbon::parse($valor)->startOfDay() : null;
    }
}
