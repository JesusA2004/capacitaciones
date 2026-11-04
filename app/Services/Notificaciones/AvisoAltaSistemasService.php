<?php

namespace App\Services\Notificaciones;

use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\OrigenAvisoAltaSistemas;
use App\Jobs\EnviarAvisoAltaSistemasJob;
use App\Mail\AltaColaboradorSistemasMail;
use App\Models\AvisoAltaSistemas;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Correo «Alta para asignar usuarios» a Sistemas (config/notificaciones.php →
 * sistemas). Se programa cuando una persona entra a la fase 6 «Contratación»
 * del tablero de candidatos — el momento en que RH inicia su contratación y
 * se crea su Colaborador (ContratacionCandidatoService::iniciarContratacion)
 * — y cuando RH autoriza un reingreso (ReingresoService::decidir).
 *
 * Reglas:
 *  - una sola vez por contratación: la fila de avisos_alta_sistemas
 *    (origen_tipo + origen_id únicos) es la marca persistente; un reintento
 *    de la cola o repetir la acción nunca lo duplica;
 *  - se encola DESPUÉS del commit (EnviarAvisoAltaSistemasJob::afterCommit);
 *  - un fallo al encolar o al enviar NUNCA revierte la contratación:
 *    try/catch + Log::warning + auditoría del fallo;
 *  - RH (candidatos.contratar) puede reenviarlo a mano (reenviar()).
 */
class AvisoAltaSistemasService
{
    public const PERMISO_REENVIAR = ContratacionCandidatoService::PERMISO_CONTRATAR;

    private const SIN_CAPTURAR = 'Sin capturar';

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function activo(): bool
    {
        return (bool) config('notificaciones.sistemas.activo') && $this->para() !== [];
    }

    /**
     * Programa el aviso de una contratación. Nunca lanza: lo llaman flujos
     * que ya confirmaron su acción principal.
     */
    public function programar(Colaborador $colaborador, OrigenAvisoAltaSistemas $origen, int $origenId, ?User $actor = null): ?AvisoAltaSistemas
    {
        if (! $this->activo()) {
            return null;
        }

        try {
            $aviso = AvisoAltaSistemas::query()->firstOrCreate(
                ['origen_tipo' => $origen->value, 'origen_id' => $origenId],
                ['colaborador_id' => $colaborador->id, 'estado' => EstadoAvisoAltaSistemas::Pendiente, 'solicitado_por' => $actor?->id],
            );

            // Ya existía: esta contratación ya tiene su aviso (enviado o en
            // cola). Repetir la acción no lo vuelve a mandar.
            if (! $aviso->wasRecentlyCreated) {
                return $aviso;
            }

            EnviarAvisoAltaSistemasJob::dispatch($aviso->id);

            return $aviso;
        } catch (Throwable $e) {
            Log::warning('AvisoAltaSistemasService: no se pudo programar el aviso de alta a Sistemas.', [
                'colaborador_id' => $colaborador->id,
                'origen' => $origen->value,
                'origen_id' => $origenId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Manda el correo de un aviso. Idempotente: si ya se envió (enviado_en
     * con valor) no hace nada — un reintento de la cola nunca duplica. El
     * reenvío manual limpia enviado_en antes de encolar (reencolar()).
     * Nunca lanza; devuelve false solo si el envío falló (para reintentar).
     */
    public function enviar(int $avisoId, ?int $actorId = null): bool
    {
        $aviso = AvisoAltaSistemas::query()->where('id', $avisoId)->first();

        if ($aviso === null || ! $this->activo() || $aviso->enviado_en !== null) {
            return true;
        }

        $reenvioManual = $aviso->origen_tipo === OrigenAvisoAltaSistemas::Colaborador || $aviso->intentos > 0;

        $actor = $actorId !== null ? User::query()->where('id', $actorId)->first() : null;
        $colaborador = Colaborador::withTrashed()->where('id', $aviso->colaborador_id)->first();
        $para = $this->para();
        $cc = $this->cc();

        try {
            if ($colaborador === null) {
                throw new RuntimeException('El colaborador del aviso ya no existe.');
            }

            Mail::to($para)->cc($cc)->send($this->mailable($colaborador, $aviso->origen_tipo));

            $aviso->update([
                'estado' => EstadoAvisoAltaSistemas::Enviado,
                'enviado_en' => now(),
                'intentos' => $aviso->intentos + 1,
                'ultimo_error' => null,
                'destinatarios' => $this->describirDestinatarios($para, $cc),
            ]);

            $this->auditoria->registrar('aviso_sistemas_alta_enviado', $colaborador, $actor, [
                'aviso_id' => $aviso->id,
                'origen_aviso' => $aviso->origen_tipo->value,
                'para' => $para,
                'cc' => $cc,
                'reenvio' => $reenvioManual,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('AvisoAltaSistemasService: no se pudo enviar el correo de alta a Sistemas.', [
                'aviso_id' => $aviso->id,
                'colaborador_id' => $aviso->colaborador_id,
                'error' => $e->getMessage(),
            ]);

            try {
                $aviso->update([
                    'estado' => EstadoAvisoAltaSistemas::Fallido,
                    'intentos' => $aviso->intentos + 1,
                    'ultimo_error' => Str::limit($e->getMessage(), 1000),
                ]);

                $this->auditoria->registrar('aviso_sistemas_alta_fallido', $colaborador, $actor, [
                    'aviso_id' => $aviso->id,
                    'colaborador_id' => $aviso->colaborador_id,
                    'origen_aviso' => $aviso->origen_tipo->value,
                    'error' => Str::limit($e->getMessage(), 300),
                ]);
            } catch (Throwable $registro) {
                Log::warning('AvisoAltaSistemasService: no se pudo registrar el fallo del aviso.', ['aviso_id' => $aviso->id, 'error' => $registro->getMessage()]);
            }

            return false;
        }
    }

    /**
     * Reenvío manual (RH / super_admin): vuelve a encolar el último aviso del
     * colaborador; si nunca tuvo uno (contratado antes de existir este
     * aviso), crea uno de origen manual.
     */
    public function reenviar(Colaborador $colaborador, User $actor): AvisoAltaSistemas
    {
        if (! $actor->can(self::PERMISO_REENVIAR) || ! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
            throw new AuthorizationException('No tienes permiso para reenviar el aviso de alta a Sistemas.');
        }

        if (! $this->activo()) {
            throw ValidationException::withMessages([
                'aviso_sistemas' => 'El aviso de alta a Sistemas está desactivado (SISTEMAS_ALTA_CORREO_ACTIVO / SISTEMAS_ALTA_CORREO_PARA en el .env).',
            ]);
        }

        return $this->reencolar($colaborador, $actor);
    }

    /**
     * Vuelve a encolar el aviso (sin revisar permisos: lo usan reenviar() y
     * el comando sistemas:reenviar-aviso-alta).
     */
    public function reencolar(Colaborador $colaborador, ?User $actor = null): AvisoAltaSistemas
    {
        $aviso = $this->ultimoDe($colaborador) ?? AvisoAltaSistemas::query()->firstOrCreate(
            ['origen_tipo' => OrigenAvisoAltaSistemas::Colaborador->value, 'origen_id' => $colaborador->id],
            ['colaborador_id' => $colaborador->id, 'estado' => EstadoAvisoAltaSistemas::Pendiente, 'solicitado_por' => $actor?->id],
        );

        $this->auditoria->registrar('aviso_sistemas_alta_reenvio_solicitado', $colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'enviado_antes_en' => $aviso->enviado_en?->toIso8601String(),
        ]);

        // Limpiar la marca es lo que habilita el reenvío; sus reintentos de
        // cola vuelven a ser idempotentes en cuanto se envía.
        $aviso->update(['estado' => EstadoAvisoAltaSistemas::Pendiente, 'enviado_en' => null, 'solicitado_por' => $actor?->id]);

        EnviarAvisoAltaSistemasJob::dispatch($aviso->id, $actor?->id);

        return $aviso->refresh();
    }

    public function ultimoDe(Colaborador $colaborador): ?AvisoAltaSistemas
    {
        return AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->latest('id')->first();
    }

    /**
     * Resumen para la ficha (estado del último aviso y si se puede reenviar).
     *
     * @return array{estado: string|null, estado_etiqueta: string|null, enviado_en: string|null, ultimo_error: string|null, puede_reenviar: bool}
     */
    public function resumen(Colaborador $colaborador, User $viewer): array
    {
        $aviso = $this->ultimoDe($colaborador);

        return [
            'estado' => $aviso?->estado->value,
            'estado_etiqueta' => $aviso?->estado->etiqueta(),
            'enviado_en' => $aviso?->enviado_en?->toIso8601String(),
            'ultimo_error' => $aviso !== null && $aviso->estado === EstadoAvisoAltaSistemas::Fallido ? 'No se pudo enviar el correo.' : null,
            'puede_reenviar' => $this->activo() && $viewer->can(self::PERMISO_REENVIAR),
        ];
    }

    public function mailable(Colaborador $colaborador, OrigenAvisoAltaSistemas $origen): AltaColaboradorSistemasMail
    {
        $colaborador->loadMissing([
            'puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal.empresa',
            'jefe.puesto:id,nombre', 'user', 'candidatoOrigen',
        ]);

        $fechaNacimiento = $colaborador->fecha_nacimiento;
        $edad = $fechaNacimiento !== null ? sprintf('%s (%d años)', $fechaNacimiento->format('d/m/Y'), (int) $fechaNacimiento->diffInYears(now())) : null;
        $empresa = $colaborador->sucursalPrincipal?->empresa;
        $jefe = $colaborador->jefe;
        $usuario = $colaborador->user;

        $personales = [
            'Nombre completo' => $colaborador->nombreCompleto(),
            'Fecha de nacimiento (edad)' => $edad,
            'Teléfono' => $colaborador->telefono,
            'Correo personal' => $colaborador->correo_personal ?? $colaborador->candidatoOrigen?->correo,
            'CURP' => $colaborador->curp,
            'RFC' => $colaborador->rfc,
            'NSS' => $colaborador->nss,
        ];

        $laborales = [
            'Número de empleado' => $colaborador->numero_empleado,
            'Puesto' => $colaborador->puesto?->nombre,
            'Departamento' => $colaborador->departamento?->nombre,
            'Sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'Empresa (razón social)' => $empresa !== null ? ($empresa->razon_social ?? $empresa->nombre) : null,
            'Jefe directo (organigrama)' => $jefe !== null ? trim(sprintf('%s %s', $jefe->nombreCompleto(), $jefe->puesto !== null ? sprintf('(%s)', $jefe->puesto->nombre) : '')) : null,
            'Fecha de ingreso' => $colaborador->fecha_ingreso?->format('d/m/Y'),
            'Tipo de contratación' => $colaborador->tipo_contratacion?->etiqueta(),
            'Usuario de PEOPLE' => $usuario !== null ? $usuario->username : 'Aún sin cuenta (la crea al registrarse con su QR)',
        ];

        return new AltaColaboradorSistemasMail(
            nombreCompleto: $colaborador->nombreCompleto(),
            puesto: $colaborador->puesto !== null ? $colaborador->puesto->nombre : self::SIN_CAPTURAR,
            sucursal: $colaborador->sucursalPrincipal !== null ? $colaborador->sucursalPrincipal->nombre : self::SIN_CAPTURAR,
            origen: mb_strtolower($origen->etiqueta()),
            personales: $this->rellenar($personales),
            laborales: $this->rellenar($laborales),
            urlExpediente: route('rh.expedientes.show', $colaborador->id),
        );
    }

    /**
     * @return list<string>
     */
    public function para(): array
    {
        return $this->direcciones(config('notificaciones.sistemas.para'));
    }

    /**
     * @return list<string>
     */
    public function cc(): array
    {
        return $this->direcciones(config('notificaciones.sistemas.cc'));
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
            $resultado[$etiqueta] = $texto !== '' ? $texto : self::SIN_CAPTURAR;
        }

        return $resultado;
    }

    /**
     * @return list<string>
     */
    private function direcciones(mixed $valor): array
    {
        $lista = is_array($valor) ? $valor : explode(',', (string) $valor);
        $correos = [];

        foreach ($lista as $correo) {
            $correo = trim((string) $correo);

            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
                $correos[] = $correo;
            }
        }

        return array_values(array_unique($correos));
    }

    /**
     * @param  list<string>  $para
     * @param  list<string>  $cc
     */
    private function describirDestinatarios(array $para, array $cc): string
    {
        return sprintf('para: %s; cc: %s', implode(', ', $para), $cc !== [] ? implode(', ', $cc) : '—');
    }
}
