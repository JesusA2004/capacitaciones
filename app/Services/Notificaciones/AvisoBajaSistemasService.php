<?php

namespace App\Services\Notificaciones;

use App\Enums\EstadoAvisoBajaSistemas;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EventoAvisoBajaSistemas;
use App\Enums\OrigenAvisoBajaSistemas;
use App\Jobs\EnviarAvisoBajaSistemasJob;
use App\Mail\BajaColaboradorSistemasMail;
use App\Models\AvisoBajaSistemas;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Support\FechaTexto;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Correos de BAJA a Sistemas: que dé de baja los usuarios de la persona en
 * los DEMÁS sistemas (PEOPLE ya bloqueó los suyos). Mismos destinatarios y
 * mismo interruptor que el aviso de alta (config/notificaciones.php →
 * sistemas). Tres momentos, cada uno a lo más una vez por baja (clave única
 * origen + evento en avisos_baja_sistemas):
 *
 *  1. «Dar de baja accesos» (EventoAvisoBajaSistemas::AccesosBloqueados): al
 *     aplicarse la baja, cuando se suspenden sus accesos —
 *     CierreLaboralService::aplicar (jefe directo, RH o módulo de Bajas) e
 *     iniciarDesdeEvaluacion (no renovación); o la baja administrativa
 *     directa sin cierre (BajaColaboradorService::ejecutar).
 *  2. «Baja concluida» (BajaConcluida): al registrar la entrega del finiquito
 *     (CierreLaboralService::registrarEntregaFiniquito), para que Sistemas
 *     confirme que ya no queda nada activo.
 *  3. «Baja cancelada» (BajaCancelada): solo si la baja se cancela («No
 *     procede») o se revierte DESPUÉS de que Sistemas ya recibió el primer
 *     aviso — para que reactive lo que haya dado de baja. Lo que aún no
 *     salía se marca «cancelado» y ya no se manda ni se reenvía.
 *
 * Reglas (las mismas del alta): se encola después del commit
 * (EnviarAvisoBajaSistemasJob::afterCommit); un reintento nunca duplica
 * (enviado_en); un fallo al programar, enviar o registrar NUNCA deshace la
 * baja (try/catch + Log::warning + auditoría del fallo). El correo no lleva
 * causal, motivo, montos ni quién capturó la baja.
 */
class AvisoBajaSistemasService
{
    /** Bandeja «Pendientes de baja en sistemas»: los mismos permisos que la de alta. */
    public const PERMISO_VER_BANDEJA = AvisoAltaSistemasService::PERMISO_VER_BANDEJA;

    public const PERMISO_ATENDER = AvisoAltaSistemasService::PERMISO_ATENDER;

    /** RH que gestiona cierres puede reenviar dentro de su alcance. */
    public const PERMISO_REENVIAR_RH = 'cierres.gestionar';

    public function __construct(
        private readonly AvisoAltaSistemasService $alta,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /** El CORREO está encendido y tiene a quién mandarse (mismo interruptor que el alta). */
    public function activo(): bool
    {
        return $this->alta->activo();
    }

    /** Baja aplicada con sus accesos suspendidos (cierre laboral). Nunca lanza. */
    public function alAplicarBaja(CierreLaboral $cierre, ?User $actor = null): ?AvisoBajaSistemas
    {
        return $this->programar($cierre->colaborador_id, OrigenAvisoBajaSistemas::Cierre, $cierre->id, EventoAvisoBajaSistemas::AccesosBloqueados, $actor);
    }

    /** RH registró la entrega del finiquito: la baja quedó concluida. Nunca lanza. */
    public function alEntregarFiniquito(CierreLaboral $cierre, ?User $actor = null): ?AvisoBajaSistemas
    {
        return $this->programar($cierre->colaborador_id, OrigenAvisoBajaSistemas::Cierre, $cierre->id, EventoAvisoBajaSistemas::BajaConcluida, $actor);
    }

    /**
     * Baja administrativa directa (sin cierre laboral): accesos bloqueados y
     * baja definitiva a la vez. Si la persona tiene un cierre abierto, la baja
     * viene de ese cierre (ejecutarBaja) y su aviso ya salió por él. Nunca lanza.
     */
    public function alBajaDirecta(Colaborador $colaborador, int $movimientoId, ?User $actor = null): ?AvisoBajaSistemas
    {
        try {
            $conCierre = CierreLaboral::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereIn('estado', array_map(fn (EstadoCierreLaboral $e): string => $e->value, EstadoCierreLaboral::abiertos()))
                ->exists();
        } catch (Throwable $e) {
            Log::warning('AvisoBajaSistemasService: no se pudo revisar si la baja viene de un cierre.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $conCierre ? null : $this->programar($colaborador->id, OrigenAvisoBajaSistemas::BajaDirecta, $movimientoId, EventoAvisoBajaSistemas::AccesosBloqueados, $actor);
    }

    /** RH canceló el cierre («No procede»): la persona sigue laborando. Nunca lanza. */
    public function alCancelarCierre(CierreLaboral $cierre, ?User $actor = null): void
    {
        $this->revertir($cierre->colaborador_id, OrigenAvisoBajaSistemas::Cierre, $cierre->id, $actor);
    }

    /** Se reactivó la relación laboral de una baja directa. Nunca lanza. */
    public function alReactivar(Colaborador $colaborador, ?User $actor = null): void
    {
        try {
            $origenes = AvisoBajaSistemas::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('origen_tipo', OrigenAvisoBajaSistemas::BajaDirecta->value)
                ->where('evento', EventoAvisoBajaSistemas::AccesosBloqueados->value)
                ->pluck('origen_id');
        } catch (Throwable $e) {
            Log::warning('AvisoBajaSistemasService: no se pudo revisar los avisos de la baja reactivada.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($origenes as $origenId) {
            $this->revertir($colaborador->id, OrigenAvisoBajaSistemas::BajaDirecta, (int) $origenId, $actor);
        }
    }

    /**
     * Crea el registro del aviso (una vez por baja y evento) y encola su
     * correo si está activo. Nunca lanza: lo llaman flujos que ya
     * confirmaron la baja.
     */
    public function programar(int $colaboradorId, OrigenAvisoBajaSistemas $origen, int $origenId, EventoAvisoBajaSistemas $evento, ?User $actor = null): ?AvisoBajaSistemas
    {
        try {
            $mandar = $this->activo();

            $aviso = AvisoBajaSistemas::query()->firstOrCreate(
                ['origen_tipo' => $origen->value, 'origen_id' => $origenId, 'evento' => $evento->value],
                [
                    'colaborador_id' => $colaboradorId,
                    'estado' => $mandar ? EstadoAvisoBajaSistemas::Pendiente : EstadoAvisoBajaSistemas::SinEnviar,
                    'solicitado_por' => $actor?->id,
                ],
            );

            // Ya existía (la baja se reenvió tras un rechazo, se repitió la
            // acción o reintentó la cola): no se vuelve a mandar.
            if ($aviso->wasRecentlyCreated && $mandar) {
                EnviarAvisoBajaSistemasJob::dispatch($aviso->id);
            }

            return $aviso;
        } catch (Throwable $e) {
            Log::warning('AvisoBajaSistemasService: no se pudo programar el aviso de baja a Sistemas.', [
                'colaborador_id' => $colaboradorId,
                'origen' => $origen->value,
                'origen_id' => $origenId,
                'evento' => $evento->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Manda el correo de un aviso. Idempotente: con enviado_en, o si la baja
     * se canceló, no hace nada. Nunca lanza; devuelve false solo si el envío
     * falló (para que la cola reintente).
     */
    public function enviar(int $avisoId, ?int $actorId = null): bool
    {
        $aviso = AvisoBajaSistemas::query()->where('id', $avisoId)->first();

        if ($aviso === null || ! $this->activo() || $aviso->enviado_en !== null || $aviso->estado === EstadoAvisoBajaSistemas::Cancelado) {
            return true;
        }

        $actor = $actorId !== null ? User::query()->where('id', $actorId)->first() : null;
        $colaborador = Colaborador::withTrashed()->where('id', $aviso->colaborador_id)->first();
        $para = $this->alta->para();
        $cc = $this->alta->cc();

        try {
            if ($colaborador === null) {
                throw new RuntimeException('El colaborador del aviso ya no existe.');
            }

            Mail::to($para)->cc($cc)->send($this->mailable($aviso, $colaborador));

            $aviso->update([
                'estado' => EstadoAvisoBajaSistemas::Enviado,
                'enviado_en' => now(),
                'intentos' => $aviso->intentos + 1,
                'ultimo_error' => null,
                'destinatarios' => sprintf('para: %s; cc: %s', implode(', ', $para), $cc !== [] ? implode(', ', $cc) : '—'),
            ]);

            $this->auditoria->registrar('aviso_sistemas_baja_enviado', $colaborador, $actor, [
                'aviso_id' => $aviso->id,
                'evento' => $aviso->evento->value,
                'origen_aviso' => $aviso->origen_tipo->value,
                'para' => $para,
                'cc' => $cc,
                'reenvio' => $aviso->intentos > 1,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('AvisoBajaSistemasService: no se pudo enviar el correo de baja a Sistemas.', [
                'aviso_id' => $aviso->id,
                'colaborador_id' => $aviso->colaborador_id,
                'error' => $e->getMessage(),
            ]);

            try {
                $aviso->update([
                    'estado' => EstadoAvisoBajaSistemas::Fallido,
                    'intentos' => $aviso->intentos + 1,
                    'ultimo_error' => Str::limit($e->getMessage(), 1000),
                ]);

                $this->auditoria->registrar('aviso_sistemas_baja_fallido', $colaborador, $actor, [
                    'aviso_id' => $aviso->id,
                    'colaborador_id' => $aviso->colaborador_id,
                    'evento' => $aviso->evento->value,
                    'error' => Str::limit($e->getMessage(), 300),
                ]);
            } catch (Throwable $registro) {
                Log::warning('AvisoBajaSistemasService: no se pudo registrar el fallo del aviso.', ['aviso_id' => $aviso->id, 'error' => $registro->getMessage()]);
            }

            return false;
        }
    }

    /** Reenvío manual desde la bandeja (Sistemas o RH con alcance). */
    public function reenviarAviso(AvisoBajaSistemas $aviso, User $actor): AvisoBajaSistemas
    {
        $colaborador = Colaborador::withTrashed()->where('id', $aviso->colaborador_id)->first();

        if ($colaborador === null || ! $this->puedeReenviar($actor, $colaborador)) {
            throw new AuthorizationException('No tienes permiso para reenviar el aviso de baja a Sistemas.');
        }

        if (! $this->activo()) {
            throw ValidationException::withMessages([
                'aviso_sistemas' => 'El aviso por correo a Sistemas está desactivado (SISTEMAS_ALTA_CORREO_ACTIVO / SISTEMAS_ALTA_CORREO_PARA en el .env).',
            ]);
        }

        if ($aviso->estado === EstadoAvisoBajaSistemas::Cancelado) {
            throw ValidationException::withMessages(['aviso_sistemas' => 'La baja se canceló: este aviso ya no se envía.']);
        }

        $this->auditoria->registrar('aviso_sistemas_baja_reenvio_solicitado', $colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'evento' => $aviso->evento->value,
            'enviado_antes_en' => $aviso->enviado_en?->toIso8601String(),
        ]);

        // Limpiar la marca es lo que habilita el reenvío.
        $aviso->update(['estado' => EstadoAvisoBajaSistemas::Pendiente, 'enviado_en' => null, 'solicitado_por' => $actor->id]);

        EnviarAvisoBajaSistemasJob::dispatch($aviso->id, $actor->id);

        return $aviso->refresh();
    }

    /** Sistemas (toda la organización) o RH que gestiona cierres dentro de su alcance. */
    public function puedeReenviar(User $actor, Colaborador $colaborador): bool
    {
        if ($actor->can(self::PERMISO_ATENDER)) {
            return true;
        }

        return $actor->can(self::PERMISO_REENVIAR_RH) && $this->alcance->alcanzaColaborador($actor, $colaborador);
    }

    /**
     * Datos de la persona para darla de baja en otros sistemas, con «Sin
     * capturar» en lo que falte. Fuente única del correo y del detalle de la
     * bandeja. Sin causal, motivo, montos ni datos personales que Sistemas no
     * necesita (CURP, RFC, NSS, domicilio).
     *
     * @return array<string, array{titulo: string, datos: array<string, string>}>
     */
    public function datos(AvisoBajaSistemas $aviso, Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre', 'user']);
        $baja = $this->contextoBaja($aviso, $colaborador);
        $usuario = $colaborador->user;

        $datosBaja = [
            'Tipo de baja' => $baja['tipo'],
            'Fecha efectiva de baja' => FechaTexto::doble($baja['fecha_efectiva']),
        ];

        if ($aviso->evento === EventoAvisoBajaSistemas::BajaConcluida) {
            $datosBaja['Finiquito entregado'] = FechaTexto::doble($baja['finiquito_en']);
        }

        return [
            'persona' => ['titulo' => 'Persona', 'datos' => $this->rellenar([
                'Nombre completo' => $colaborador->nombreCompleto(),
                'Número de empleado' => $colaborador->numero_empleado,
            ])],
            'puesto' => ['titulo' => 'Puesto', 'datos' => $this->rellenar([
                'Puesto' => $colaborador->puesto?->nombre,
                'Departamento' => $colaborador->departamento?->nombre,
                'Sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ])],
            'baja' => ['titulo' => 'Baja', 'datos' => $this->rellenar($datosBaja)],
            'accesos' => ['titulo' => 'Accesos', 'datos' => $this->rellenar([
                'Correo corporativo' => $colaborador->correo_corporativo,
                'Usuario de PEOPLE' => $usuario !== null ? $usuario->username : 'Sin cuenta en PEOPLE',
                'Acceso a PEOPLE' => match (true) {
                    $usuario === null => 'Sin cuenta',
                    $usuario->acceso_bloqueado_en !== null => sprintf('Bloqueado desde el %s', $usuario->acceso_bloqueado_en->format('d/m/Y')),
                    default => 'Activo',
                },
            ])],
        ];
    }

    /**
     * Lista de verificación de lo que Sistemas debe desactivar
     * (config notificaciones.sistemas.plataformas, SISTEMAS_PLATAFORMAS en el
     * .env). Sin catálogo configurado, el correo no la muestra.
     *
     * @return list<string>
     */
    public function plataformas(): array
    {
        $valor = config('notificaciones.sistemas.plataformas', []);
        $lista = is_array($valor) ? $valor : explode(',', (string) $valor);

        return array_values(array_unique(array_filter(array_map(fn (mixed $p): string => trim((string) $p), $lista), fn (string $p): bool => $p !== '')));
    }

    public function mailable(AvisoBajaSistemas $aviso, Colaborador $colaborador): BajaColaboradorSistemasMail
    {
        $secciones = $this->datos($aviso, $colaborador);
        $baja = $this->contextoBaja($aviso, $colaborador);

        return new BajaColaboradorSistemasMail(
            evento: $aviso->evento,
            nombreCompleto: $colaborador->nombreCompleto(),
            puesto: $secciones['puesto']['datos']['Puesto'],
            sucursal: $secciones['puesto']['datos']['Sucursal'],
            secciones: $secciones,
            plataformas: $aviso->evento === EventoAvisoBajaSistemas::BajaCancelada ? [] : $this->plataformas(),
            fechaBajaLarga: $baja['fecha_efectiva'] !== null ? FechaTexto::larga($baja['fecha_efectiva']) : AvisoAltaSistemasService::SIN_CAPTURAR,
            fechaFiniquitoLarga: $baja['finiquito_en'] !== null ? FechaTexto::larga($baja['finiquito_en']) : null,
            urlDetalle: route('sistemas.pendientes-baja.show', $aviso->id),
            urlBandeja: route('sistemas.pendientes-baja.index'),
            logoUrl: sprintf('%s/branding/MrLanaPeople_horizontal_color.png', rtrim((string) config('app.url'), '/')),
        );
    }

    /**
     * Cancela lo que aún no salía y, si Sistemas ya había recibido el aviso
     * de dar de baja accesos, programa el de «Baja cancelada». Nunca lanza.
     */
    private function revertir(int $colaboradorId, OrigenAvisoBajaSistemas $origen, int $origenId, ?User $actor): void
    {
        try {
            $avisos = AvisoBajaSistemas::query()
                ->where('origen_tipo', $origen->value)
                ->where('origen_id', $origenId)
                ->whereIn('evento', [EventoAvisoBajaSistemas::AccesosBloqueados->value, EventoAvisoBajaSistemas::BajaConcluida->value])
                ->get();

            $yaRecibido = false;

            foreach ($avisos as $aviso) {
                $yaRecibido = $yaRecibido || $aviso->enviado_en !== null;

                if ($aviso->estado !== EstadoAvisoBajaSistemas::Cancelado) {
                    $aviso->update(['estado' => EstadoAvisoBajaSistemas::Cancelado]);
                }
            }

            if ($avisos->isNotEmpty()) {
                $this->auditoria->registrar('aviso_sistemas_baja_cancelado', Colaborador::withTrashed()->where('id', $colaboradorId)->first(), $actor, [
                    'origen_aviso' => $origen->value,
                    'origen_id' => $origenId,
                    'avisos' => $avisos->pluck('id')->all(),
                    'sistemas_ya_avisado' => $yaRecibido,
                ]);
            }

            if ($yaRecibido) {
                $this->programar($colaboradorId, $origen, $origenId, EventoAvisoBajaSistemas::BajaCancelada, $actor);
            }
        } catch (Throwable $e) {
            Log::warning('AvisoBajaSistemasService: no se pudo cancelar el aviso de baja a Sistemas.', [
                'colaborador_id' => $colaboradorId,
                'origen' => $origen->value,
                'origen_id' => $origenId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tipo y fechas de la baja según su origen (cierre laboral o baja directa).
     *
     * @return array{tipo: string, fecha_efectiva: CarbonInterface|null, finiquito_en: CarbonInterface|null}
     */
    private function contextoBaja(AvisoBajaSistemas $aviso, Colaborador $colaborador): array
    {
        if ($aviso->origen_tipo === OrigenAvisoBajaSistemas::Cierre) {
            $cierre = CierreLaboral::query()->where('id', $aviso->origen_id)->first();

            if ($cierre !== null) {
                return [
                    'tipo' => $cierre->tipo_baja->etiqueta(),
                    'fecha_efectiva' => $cierre->fecha_efectiva,
                    'finiquito_en' => $cierre->pago_confirmado_en,
                ];
            }
        }

        return [
            'tipo' => OrigenAvisoBajaSistemas::BajaDirecta->etiqueta(),
            'fecha_efectiva' => $colaborador->fecha_baja,
            'finiquito_en' => null,
        ];
    }

    /**
     * @param  array<string, string|null>  $datos
     * @return array<string, string>
     */
    private function rellenar(array $datos): array
    {
        $resultado = [];

        foreach ($datos as $etiqueta => $valor) {
            $texto = trim((string) $valor);
            $resultado[$etiqueta] = $texto !== '' ? $texto : AvisoAltaSistemasService::SIN_CAPTURAR;
        }

        return $resultado;
    }
}
