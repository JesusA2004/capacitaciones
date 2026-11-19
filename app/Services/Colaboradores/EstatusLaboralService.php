<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\AvisoAltaSistemas;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\IncorporacionInvitacion;
use App\Models\OnboardingProceso;
use App\Models\Reingreso;
use App\Models\User;
use App\Services\Administracion\AccesoCuentaService;
use App\Services\Auditoria\AuditoriaService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FUENTE ÚNICA de «¿esta persona sigue laborando?» y de lo que una baja
 * deja coherente (docs/SOLICITUDES_UNIFICADAS.md, «Baja de colaborador»).
 *
 * Antes la baja aplicada (RH o jefe directo, inmediata) solo suspendía el
 * acceso y el estatus laboral cambiaba hasta entregar el finiquito: la
 * persona aparecía en «Bajas» pero seguía ACTIVA en Colaboradores, su
 * expediente, Personas en contratación, Pendientes de alta en sistemas,
 * inducción… Ahora, al aplicar la baja (y su último día ya llegó):
 *
 *  - colaborador: estatus Inactivo, estado del alta «Baja», fecha de baja;
 *  - cuenta: acceso bloqueado, tokens Sanctum y dispositivos revocados;
 *  - procesos de origen cerrados (nunca borrados): onboarding/inducción
 *    cancelado, QR de registro revocado, candidato «en contratación» →
 *    «Desistió», pendiente de alta en sistemas cerrado sin enviar;
 *  - plaza: ColaboradorPlantillaObserver resincroniza la vacante al cambiar
 *    el estatus.
 *
 * Todo lo cambiado se guarda en `cierres_laborales.reversion_baja` para que
 * «No procede» lo regrese tal cual (revertir()). Con último día futuro la
 * baja queda «programada» y `cierres:revisar-fechas` (diario, 06:45) la
 * aplica ese día; `bajas:reparar-estatus` corrige datos ya inconsistentes. El soft-delete, el movimiento laboral y la terminación del
 * contrato siguen ocurriendo al concluir el cierre (BajaColaboradorService).
 *
 * Nunca borra usuarios, expedientes ni historial.
 *
 * @phpstan-type Hallazgo array{colaborador_id: int, nombre: string, registro: string, campo: string, actual: string, nuevo: string}
 */
class EstatusLaboralService
{
    /** Prefijo de lo que cierra la baja: identifica qué regresar en «No procede». */
    public const PREFIJO = 'Cerrado por baja';

    public function __construct(
        private readonly AccesoCuentaService $acceso,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Colaboradores que siguen laborando (o en su alta): sin soft-delete,
     * estatus vigente y estado del alta distinto de «Baja».
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $consulta  Consulta sobre `colaboradores`.
     * @return Builder<TModel>
     */
    public static function limitarVigentes(Builder $consulta): Builder
    {
        $tabla = $consulta->getModel()->getTable();

        return $consulta
            ->whereNull(sprintf('%s.deleted_at', $tabla))
            ->whereIn(sprintf('%s.estatus', $tabla), EstadoUsuario::valoresVigentes())
            ->where(fn (Builder $q) => $q->whereNull(sprintf('%s.estado_alta', $tabla))->orWhere(sprintf('%s.estado_alta', $tabla), '!=', EstadoAltaColaborador::Baja->value));
    }

    public function estaDadoDeBaja(Colaborador $colaborador): bool
    {
        return $colaborador->trashed()
            || $colaborador->estatus === EstadoUsuario::Inactivo
            || $colaborador->estado_alta === EstadoAltaColaborador::Baja;
    }

    public function estaActivo(Colaborador $colaborador): bool
    {
        if ($this->estaDadoDeBaja($colaborador) || ! in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true)) {
            return false;
        }

        $baja = $this->bajaAplicada($colaborador);

        return $baja === null || $baja['fecha']->isAfter(FechaMexico::inicioDeHoy());
    }

    /**
     * Estado laboral para mostrar (chip, banner): una sola regla para el
     * listado de Colaboradores, el expediente, su carpeta y el ciclo laboral.
     *
     * @return array{clave: string, etiqueta: string, fecha_baja: string|null, mensaje: string|null, folio: string|null}
     */
    public function estado(Colaborador $colaborador): array
    {
        $baja = $this->estaDadoDeBaja($colaborador) || in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true)
            ? $this->bajaAplicada($colaborador)
            : null;
        $fechaColaborador = $colaborador->fecha_baja !== null ? CarbonImmutable::parse($colaborador->fecha_baja->toDateString()) : null;

        if ($this->estaDadoDeBaja($colaborador) || ($baja !== null && ! $baja['fecha']->isAfter(FechaMexico::inicioDeHoy()))) {
            $fecha = $fechaColaborador ?? $baja['fecha'] ?? null;

            return [
                'clave' => 'baja',
                'etiqueta' => 'Baja',
                'fecha_baja' => $fecha?->toDateString(),
                'mensaje' => $fecha !== null
                    ? sprintf('Dado de baja el %s%s.', $fecha->format('d/m/Y'), $baja !== null && $baja['causa'] !== null ? sprintf(' (%s)', $baja['causa']) : '')
                    : 'Dado de baja.',
                'folio' => $baja['folio'] ?? null,
            ];
        }

        if ($baja !== null) {
            return [
                'clave' => 'baja_programada',
                'etiqueta' => sprintf('Baja el %s', $baja['fecha']->format('d/m/Y')),
                'fecha_baja' => $baja['fecha']->toDateString(),
                'mensaje' => $baja['cierre'] !== null && $baja['cierre']->esProgramadaPendiente()
                    ? sprintf('Baja programada para el %s: conserva su acceso hasta ese día; ese día se suspende y queda inactivo.', $baja['fecha']->format('d/m/Y'))
                    : sprintf('Baja registrada: su último día es el %s. Ese día queda inactivo; su acceso ya está suspendido.', $baja['fecha']->format('d/m/Y')),
                'folio' => $baja['folio'],
            ];
        }

        return [
            'clave' => $colaborador->estatus->value,
            'etiqueta' => $colaborador->estatus->etiqueta(),
            'fecha_baja' => null,
            'mensaje' => null,
            'folio' => null,
        ];
    }

    /**
     * Indicador «Acceso al sistema»: cuenta, bloqueo, estatus laboral y
     * sesiones de la app. Nunca dice «Sí» a quien no puede entrar.
     *
     * @return array{clave: string, etiqueta: string, puede_entrar: bool, tiene_cuenta: bool, motivo: string|null, bloqueado_en: string|null, sesiones_app: int}
     */
    public function acceso(Colaborador $colaborador): array
    {
        $cuenta = $colaborador->user;

        if ($cuenta === null) {
            return ['clave' => 'sin_cuenta', 'etiqueta' => 'Sin cuenta', 'puede_entrar' => false, 'tiene_cuenta' => false, 'motivo' => 'Todavía no tiene cuenta de acceso.', 'bloqueado_en' => null, 'sesiones_app' => 0];
        }

        $sesiones = $cuenta->tokens()->count();
        $base = ['tiene_cuenta' => true, 'bloqueado_en' => $cuenta->acceso_bloqueado_en?->toIso8601String(), 'sesiones_app' => $sesiones];

        if ($cuenta->acceso_bloqueado_en !== null) {
            return [...$base, 'clave' => 'bloqueado', 'etiqueta' => 'Bloqueado', 'puede_entrar' => false, 'motivo' => $cuenta->acceso_bloqueado_motivo ?? 'Acceso revocado'];
        }

        if (! $this->estaActivo($colaborador) && $colaborador->estatus !== EstadoUsuario::EnIncorporacion) {
            return [...$base, 'clave' => 'sin_acceso_baja', 'etiqueta' => 'Sin acceso (baja)', 'puede_entrar' => false, 'motivo' => 'Colaborador dado de baja: no puede iniciar sesión.'];
        }

        return [...$base, 'clave' => 'con_acceso', 'etiqueta' => 'Con acceso', 'puede_entrar' => true, 'motivo' => null];
    }

    /**
     * Aplica el estatus laboral de la baja en UNA transacción. Con último día
     * futuro no cambia nada (queda programada) y devuelve false.
     */
    public function aplicar(Colaborador $colaborador, ?User $actor, CarbonInterface|string $fechaBaja, ?CierreLaboral $cierre, string $motivo): bool
    {
        $fecha = CarbonImmutable::parse(is_string($fechaBaja) ? $fechaBaja : $fechaBaja->toDateString())->startOfDay();

        if ($fecha->isAfter(FechaMexico::inicioDeHoy())) {
            return false;
        }

        $cambios = DB::transaction(function () use ($colaborador, $actor, $fecha, $cierre, $motivo): array {
            $colaborador = Colaborador::withTrashed()->lockForUpdate()->where('id', $colaborador->id)->firstOrFail();
            $antes = [
                'estatus' => $colaborador->estatus->value,
                'estado_alta' => $colaborador->estado_alta?->value,
                'fecha_baja' => $colaborador->fecha_baja?->toDateString(),
            ];

            // forceFill + save: dispara ColaboradorPlantillaObserver (vacante
            // de la plaza) y LogsActivity (auditoría del estatus).
            $colaborador->forceFill([
                'estatus' => EstadoUsuario::Inactivo,
                'estado_alta' => EstadoAltaColaborador::Baja,
                'fecha_baja' => $colaborador->fecha_baja ?? $fecha->toDateString(),
            ])->save();

            $procesos = $this->cerrarProcesos($colaborador, $actor, $motivo);
            $this->bloquearAcceso($colaborador, $actor, $cierre);

            if ($cierre !== null) {
                $cierre->forceFill([
                    'reversion_baja' => ['colaborador' => $antes, 'procesos' => $procesos, 'aplicada_en' => now()->toIso8601String()],
                    'estatus_laboral_aplicado_en' => now(),
                ])->save();
            }

            return ['antes' => $antes, 'procesos' => $procesos];
        });

        $this->auditoria->registrar('baja_estatus_laboral_aplicado', $colaborador, $actor, [
            'colaborador_id' => $colaborador->id,
            'cierre_id' => $cierre?->id,
            'fecha_baja' => $fecha->toDateString(),
            'antes' => $cambios['antes'],
            'procesos_cerrados' => count($cambios['procesos']),
        ]);

        return true;
    }

    /**
     * «No procede»: regresa estatus, estado del alta, fecha de baja y cada
     * proceso que cerró la baja (solo si nadie lo movió después).
     */
    public function revertir(CierreLaboral $cierre, ?User $actor): void
    {
        $reversion = $cierre->reversion_baja;

        if (! is_array($reversion) || $cierre->estatus_laboral_aplicado_en === null) {
            return;
        }

        DB::transaction(function () use ($cierre, $reversion): void {
            $colaborador = Colaborador::withTrashed()->lockForUpdate()->where('id', $cierre->colaborador_id)->first();
            $antes = is_array($reversion['colaborador'] ?? null) ? $reversion['colaborador'] : [];

            if ($colaborador !== null && ! $colaborador->trashed() && $colaborador->estatus === EstadoUsuario::Inactivo) {
                $colaborador->forceFill([
                    'estatus' => EstadoUsuario::tryFrom((string) ($antes['estatus'] ?? '')) ?? EstadoUsuario::Activo,
                    'estado_alta' => EstadoAltaColaborador::tryFrom((string) ($antes['estado_alta'] ?? '')),
                    'fecha_baja' => is_string($antes['fecha_baja'] ?? null) ? $antes['fecha_baja'] : null,
                ])->save();
            }

            foreach (is_array($reversion['procesos'] ?? null) ? $reversion['procesos'] : [] as $proceso) {
                if (is_array($proceso)) {
                    $this->reabrirProceso($proceso);
                }
            }

            $cierre->forceFill(['estatus_laboral_aplicado_en' => null])->save();
        });

        $this->auditoria->registrar('baja_estatus_laboral_revertido', $cierre->colaborador, $actor, ['cierre_id' => $cierre->id]);
    }

    /**
     * Inconsistencias entre la baja registrada y lo que muestran los demás
     * módulos (auditable: registro, campo, valor actual → nuevo). No escribe.
     *
     * @return list<Hallazgo>
     */
    public function diagnosticar(?int $soloColaborador = null): array
    {
        $hallazgos = [];

        foreach ($this->candidatosAReparar($soloColaborador) as $colaborador) {
            $nombre = $colaborador->nombreCompleto();
            $baja = $this->bajaAplicada($colaborador);
            $dadoDeBaja = $this->estaDadoDeBaja($colaborador);
            $debeEstarDeBaja = $dadoDeBaja || ($baja !== null && ! $baja['fecha']->isAfter(FechaMexico::inicioDeHoy()));
            $fila = fn (string $registro, string $campo, string $actual, string $nuevo): array => ['colaborador_id' => $colaborador->id, 'nombre' => $nombre, 'registro' => $registro, 'campo' => $campo, 'actual' => $actual, 'nuevo' => $nuevo];

            if (! $debeEstarDeBaja) {
                continue;
            }

            $registro = sprintf('colaboradores#%d', $colaborador->id);

            if ($colaborador->estatus !== EstadoUsuario::Inactivo) {
                $hallazgos[] = $fila($registro, 'estatus', $colaborador->estatus->value, EstadoUsuario::Inactivo->value);
            }

            if ($colaborador->estado_alta !== EstadoAltaColaborador::Baja) {
                $hallazgos[] = $fila($registro, 'estado_alta', $colaborador->estado_alta->value ?? '(vacío)', EstadoAltaColaborador::Baja->value);
            }

            if ($colaborador->fecha_baja === null) {
                $hallazgos[] = $fila($registro, 'fecha_baja', '(vacía)', $baja !== null ? $baja['fecha']->toDateString() : 'NO CUADRA: sin baja registrada con fecha; captúrala a mano');
            }

            $cuenta = $colaborador->user;

            if ($cuenta !== null && $cuenta->acceso_bloqueado_en === null) {
                $hallazgos[] = $fila(sprintf('users#%d', $cuenta->id), 'acceso_bloqueado_en', '(con acceso)', 'bloqueado + tokens y dispositivos revocados');
            } elseif ($cuenta !== null && $cuenta->tokens()->exists()) {
                $hallazgos[] = $fila(sprintf('users#%d', $cuenta->id), 'tokens', sprintf('%d sesión(es) de la app', $cuenta->tokens()->count()), '0');
            }

            foreach ($this->procesosAbiertos($colaborador) as $proceso) {
                $hallazgos[] = $fila($proceso['registro'], $proceso['campo'], $proceso['actual'], $proceso['nuevo']);
            }
        }

        return $hallazgos;
    }

    /**
     * Corrige lo que diagnosticar() reporta. Nunca borra nada.
     *
     * @return int Colaboradores corregidos.
     */
    public function reparar(?int $soloColaborador = null, ?User $actor = null): int
    {
        $corregidos = 0;

        foreach ($this->candidatosAReparar($soloColaborador) as $colaborador) {
            // Solo quien tiene algo que corregir (misma regla que el reporte).
            if ($this->diagnosticar($colaborador->id) === []) {
                continue;
            }

            $baja = $this->bajaAplicada($colaborador);
            $cierre = $baja !== null ? $baja['cierre'] : null;
            $motivo = $baja['causa'] ?? 'baja registrada';

            if (! $this->estaDadoDeBaja($colaborador) && $baja !== null) {
                // Baja aplicada que nunca cambió el estatus: el mismo camino que
                // una baja nueva (con snapshot si el cierre sigue abierto).
                $this->aplicar($colaborador, $actor, $baja['fecha'], $cierre !== null && ! $cierre->estado->esFinal() && $cierre->estatus_laboral_aplicado_en === null ? $cierre : null, $motivo);
            } else {
                // Ya inactivo: completa el estado del alta y la fecha (nunca se
                // inventa: sin baja registrada la fecha se queda vacía y se
                // reporta como «NO CUADRA»).
                $colaborador->forceFill([
                    'estatus' => EstadoUsuario::Inactivo,
                    'estado_alta' => EstadoAltaColaborador::Baja,
                    'fecha_baja' => $colaborador->fecha_baja ?? $baja['fecha'] ?? null,
                ])->save();
                $this->cerrarProcesos($colaborador, $actor, $motivo);
                $this->bloquearAcceso($colaborador, $actor, null);
            }

            $corregidos++;
        }

        return $corregidos;
    }

    /**
     * Baja vigente registrada para la persona (cierre aplicado o expediente
     * del módulo de Bajas), posterior a su último ingreso/reingreso.
     *
     * @return array{fecha: CarbonImmutable, causa: string|null, folio: string|null, cierre: CierreLaboral|null}|null
     */
    public function bajaAplicada(Colaborador $colaborador): ?array
    {
        $cierre = CierreLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotIn('estado', [EstadoCierreLaboral::Solicitado->value, EstadoCierreLaboral::PendienteRh->value, EstadoCierreLaboral::Rechazado->value, EstadoCierreLaboral::Cancelado->value])
            ->latest('id')
            ->first();

        $baja = Baja::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->whereNotNull('fecha_baja')
            ->when($cierre !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('cierre_laboral_id', $cierre?->id)->orWhereNull('cierre_laboral_id')))
            ->latest('id')
            ->first();

        $fecha = $cierre !== null ? $cierre->fecha_efectiva : $baja?->fecha_baja;

        if ($fecha === null) {
            return null;
        }

        $fecha = CarbonImmutable::parse($fecha->toDateString());
        $registradaEn = $cierre->created_at ?? $baja?->created_at;

        // Reingreso posterior (o ingreso después de esa baja): la baja ya es
        // historia, la persona volvió a laborar.
        if ($colaborador->fecha_ingreso !== null && $colaborador->fecha_ingreso->toDateString() > $fecha->toDateString()) {
            return null;
        }

        if ($registradaEn !== null && Reingreso::query()->where('colaborador_id', $colaborador->id)->where('created_at', '>', $registradaEn)->exists()) {
            return null;
        }

        $baja ??= $cierre !== null ? Baja::query()->where('cierre_laboral_id', $cierre->id)->first() : null;
        $causa = $baja?->forma_salida?->etiqueta() ?? $cierre?->tipo_baja->etiqueta();

        return ['fecha' => $fecha, 'causa' => $causa, 'folio' => $baja?->folio, 'cierre' => $cierre];
    }

    /**
     * Cierra los procesos de ORIGEN que una persona dada de baja ya no
     * debe tener abiertos. Devuelve qué cerró (para revertir).
     *
     * @return list<array<string, mixed>>
     */
    private function cerrarProcesos(Colaborador $colaborador, ?User $actor, string $motivo): array
    {
        $nota = Str::limit(sprintf('%s: %s', self::PREFIJO, $motivo), 250, '');
        $cerrados = [];

        // Onboarding / inducción en curso (las bandejas de inducción y su
        // evaluación solo ven procesos abiertos).
        OnboardingProceso::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotIn('estado', [EstadoOnboarding::Completado->value, EstadoOnboarding::Cancelado->value])
            ->get()
            ->each(function (OnboardingProceso $proceso) use (&$cerrados): void {
                $cerrados[] = ['tipo' => 'onboarding', 'id' => $proceso->id, 'estado' => $proceso->estado->value, 'colaborador_abierto_id' => $proceso->colaborador_abierto_id];
                $proceso->forceFill(['estado' => EstadoOnboarding::Cancelado, 'colaborador_abierto_id' => null])->save();
            });

        // Candidato(s) de reclutamiento de la persona.
        $candidatos = Candidato::query()
            ->where('historico', false)
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)
                ->when($colaborador->candidato_id !== null, fn (Builder $o) => $o->orWhere('id', $colaborador->candidato_id)))
            ->get();

        // QR de registro todavía usable.
        IncorporacionInvitacion::query()
            ->where('estado', EstadoInvitacionIncorporacion::Activo->value)
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)->orWhereIn('candidato_id', $candidatos->modelKeys()))
            ->get()
            ->each(function (IncorporacionInvitacion $invitacion) use (&$cerrados): void {
                $cerrados[] = ['tipo' => 'invitacion', 'id' => $invitacion->id, 'estado' => $invitacion->estado->value];
                $invitacion->forceFill(['estado' => EstadoInvitacionIncorporacion::Revocado, 'revoked_at' => now()])->save();
            });

        // En contratación sin concluir → «Desistió» (sale de Personas en
        // contratación y del tablero). Un contratado conserva su historia.
        $candidatos
            ->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::EnContratacion)
            ->each(function (Candidato $candidato) use (&$cerrados, $actor, $nota): void {
                $cerrados[] = ['tipo' => 'candidato', 'id' => $candidato->id, 'estado' => $candidato->estado->value];
                $candidato->forceFill([
                    'estado' => EstadoCandidato::Desistio,
                    'motivo_salida' => $nota,
                    'salida_en' => now(),
                    'salida_por' => $actor?->id,
                ])->save();
                $candidato->seguimientos()->create([
                    'tipo' => TipoSeguimientoCandidato::CambioEstado,
                    'nota' => sprintf('%s. La contratación no concluyó.', $nota),
                    'estado_anterior' => EstadoCandidato::EnContratacion->value,
                    'estado_nuevo' => EstadoCandidato::Desistio->value,
                    'fecha' => now(),
                    'registrado_por' => $actor?->id,
                ]);
            });

        // «Pendientes de alta en sistemas»: ya no hay a quién dar de alta.
        // Se cierra con nota (auditable) y el correo pendiente ya no sale.
        AvisoAltaSistemas::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNull('atendido_en')
            ->get()
            ->each(function (AvisoAltaSistemas $aviso) use (&$cerrados, $actor, $nota): void {
                $cerrados[] = ['tipo' => 'aviso_alta_sistemas', 'id' => $aviso->id, 'estado' => $aviso->estado->value];
                $sinSalir = in_array($aviso->estado, [EstadoAvisoAltaSistemas::Pendiente, EstadoAvisoAltaSistemas::EsperandoDatos], true);
                $aviso->forceFill([
                    'atendido_en' => now(),
                    'atendido_por' => $actor?->id,
                    'notas_atencion' => $nota,
                    'estado' => $sinSalir ? EstadoAvisoAltaSistemas::SinEnviar : $aviso->estado,
                ])->save();
            });

        return $cerrados;
    }

    /**
     * Lo que cerrarProcesos() cerraría, en formato de hallazgo.
     *
     * @return list<array{registro: string, campo: string, actual: string, nuevo: string}>
     */
    private function procesosAbiertos(Colaborador $colaborador): array
    {
        $filas = [];

        OnboardingProceso::query()->where('colaborador_id', $colaborador->id)
            ->whereNotIn('estado', [EstadoOnboarding::Completado->value, EstadoOnboarding::Cancelado->value])
            ->get()
            ->each(function (OnboardingProceso $p) use (&$filas): void {
                $filas[] = ['registro' => sprintf('onboarding_procesos#%d', $p->id), 'campo' => 'estado', 'actual' => $p->estado->value, 'nuevo' => EstadoOnboarding::Cancelado->value];
            });

        $candidatos = Candidato::query()->where('historico', false)
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)
                ->when($colaborador->candidato_id !== null, fn (Builder $o) => $o->orWhere('id', $colaborador->candidato_id)))
            ->get();

        IncorporacionInvitacion::query()->where('estado', EstadoInvitacionIncorporacion::Activo->value)
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)->orWhereIn('candidato_id', $candidatos->modelKeys()))
            ->get()
            ->each(function (IncorporacionInvitacion $i) use (&$filas): void {
                $filas[] = ['registro' => sprintf('incorporacion_invitaciones#%d', $i->id), 'campo' => 'estado', 'actual' => $i->estado->value, 'nuevo' => EstadoInvitacionIncorporacion::Revocado->value];
            });

        $candidatos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::EnContratacion)
            ->each(function (Candidato $c) use (&$filas): void {
                $filas[] = ['registro' => sprintf('candidatos#%d', $c->id), 'campo' => 'estado', 'actual' => $c->estado->value, 'nuevo' => EstadoCandidato::Desistio->value];
            });

        AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->whereNull('atendido_en')
            ->get()
            ->each(function (AvisoAltaSistemas $a) use (&$filas): void {
                $filas[] = ['registro' => sprintf('avisos_alta_sistemas#%d', $a->id), 'campo' => 'atendido_en', 'actual' => '(pendiente)', 'nuevo' => sprintf('cerrado: «%s»', self::PREFIJO)];
            });

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $proceso
     */
    private function reabrirProceso(array $proceso): void
    {
        $id = (int) ($proceso['id'] ?? 0);
        $estado = (string) ($proceso['estado'] ?? '');

        match ($proceso['tipo'] ?? null) {
            'onboarding' => $this->reabrirOnboarding($id, $estado, $proceso['colaborador_abierto_id'] ?? null),
            'invitacion' => IncorporacionInvitacion::query()->where('id', $id)
                ->where('estado', EstadoInvitacionIncorporacion::Revocado->value)
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->update(['estado' => $estado, 'revoked_at' => null]),
            'candidato' => $this->reabrirCandidato($id, $estado),
            'aviso_alta_sistemas' => AvisoAltaSistemas::query()->where('id', $id)
                ->where('notas_atencion', 'like', self::PREFIJO.'%')
                ->update(['atendido_en' => null, 'atendido_por' => null, 'notas_atencion' => null, 'estado' => $estado]),
            default => null,
        };
    }

    private function reabrirOnboarding(int $id, string $estado, mixed $abiertoId): void
    {
        $proceso = OnboardingProceso::query()->where('id', $id)->where('estado', EstadoOnboarding::Cancelado->value)->first();

        if ($proceso === null) {
            return;
        }

        // Índice «uno abierto por colaborador»: si ya abrieron otro, se respeta.
        $otroAbierto = is_numeric($abiertoId) && OnboardingProceso::query()->where('colaborador_abierto_id', (int) $abiertoId)->exists();

        $proceso->forceFill([
            'estado' => EstadoOnboarding::tryFrom($estado) ?? EstadoOnboarding::InduccionInstitucional,
            'colaborador_abierto_id' => $otroAbierto || ! is_numeric($abiertoId) ? null : (int) $abiertoId,
        ])->save();
    }

    private function reabrirCandidato(int $id, string $estado): void
    {
        $candidato = Candidato::query()->where('id', $id)->where('estado', EstadoCandidato::Desistio->value)
            ->where('motivo_salida', 'like', self::PREFIJO.'%')->first();

        if ($candidato === null) {
            return;
        }

        $candidato->forceFill(['estado' => EstadoCandidato::tryFrom($estado) ?? EstadoCandidato::EnContratacion, 'motivo_salida' => null, 'salida_en' => null, 'salida_por' => null])->save();
        $candidato->seguimientos()->create([
            'tipo' => TipoSeguimientoCandidato::CambioEstado,
            'nota' => 'La baja no procede: el candidato regresa a contratación.',
            'estado_anterior' => EstadoCandidato::Desistio->value,
            'estado_nuevo' => $candidato->estado->value,
            'fecha' => now(),
        ]);
    }

    /**
     * Cuenta bloqueada + tokens Sanctum + dispositivos (AccesoCuentaService).
     * Si la bloquea la baja, el cierre lo anota para devolverlo en «No procede».
     */
    private function bloquearAcceso(Colaborador $colaborador, ?User $actor, ?CierreLaboral $cierre): void
    {
        $cuenta = $colaborador->user()->first();

        if ($cuenta === null) {
            return;
        }

        if ($cuenta->acceso_bloqueado_en === null) {
            $this->acceso->revocar($cuenta, $actor, $cierre !== null ? sprintf('Baja en trámite: %s', $cierre->tipo_baja->etiqueta()) : 'Baja laboral');

            if ($cierre !== null && $cierre->acceso_suspendido_en === null) {
                $cierre->forceFill(['acceso_suspendido_en' => now()])->save();
            }

            return;
        }

        // Ya bloqueada: que no quede ninguna sesión de la app viva.
        $cuenta->tokens()->delete();
        $cuenta->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * Personas a revisar: dadas de baja (estatus, alta o soft-delete) o con
     * una baja aplicada registrada.
     *
     * @return iterable<Colaborador>
     */
    private function candidatosAReparar(?int $soloColaborador): iterable
    {
        $conBaja = CierreLaboral::query()
            ->whereNotIn('estado', [EstadoCierreLaboral::Solicitado->value, EstadoCierreLaboral::PendienteRh->value, EstadoCierreLaboral::Rechazado->value, EstadoCierreLaboral::Cancelado->value])
            ->pluck('colaborador_id')
            ->concat(Baja::query()->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])->whereNotNull('colaborador_id')->pluck('colaborador_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Colaborador::withTrashed()
            ->with('user')
            ->when($soloColaborador !== null, fn (Builder $q) => $q->where('id', $soloColaborador))
            ->where(fn (Builder $q) => $q->whereIn('id', $conBaja)
                ->orWhereNotNull('deleted_at')
                ->orWhere('estatus', EstadoUsuario::Inactivo->value)
                ->orWhere('estado_alta', EstadoAltaColaborador::Baja->value))
            ->orderBy('id')
            ->lazy();
    }
}
