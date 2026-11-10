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
use App\Support\FechaTexto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Correo «Alta para asignar usuarios» a Sistemas (config/notificaciones.php →
 * sistemas) + pendiente en la bandeja «Pendientes de alta en sistemas»
 * (App\Services\Sistemas\PendientesAltaSistemasService). El pendiente se
 * registra cuando una persona entra a la fase 6 «Contratación» del tablero
 * de candidatos (ContratacionCandidatoService::iniciarContratacion), pero su
 * correo y su aviso en la campana NO salen ahí (apenas tiene su QR y llegarían
 * vacíos): quedan «en espera de sus datos» y se liberan solos cuando el nuevo
 * ingreso los captura en la app (liberarSiDatosCompletos()). También se
 * programa cuando RH autoriza un reingreso (ReingresoService::decidir) y,
 * como red de seguridad, cuando se activa un alta que no pasó por
 * contratación (asegurar(), desde AvisoAltaBajaService::alta) o con
 * `sistemas:avisos-alta-pendientes`.
 *
 * Reglas:
 *  - una sola vez por contratación: la fila de avisos_alta_sistemas
 *    (origen_tipo + origen_id únicos) es la marca persistente; un reintento
 *    de la cola o repetir la acción nunca lo duplica;
 *  - el pendiente se crea SIEMPRE (aunque el correo esté desactivado): la
 *    bandeja y el aviso en la plataforma no dependen del SMTP;
 *  - se encola DESPUÉS del commit (EnviarAvisoAltaSistemasJob::afterCommit);
 *  - un fallo al encolar, enviar o notificar NUNCA revierte la contratación:
 *    try/catch + Log::warning + auditoría del fallo;
 *  - RH (candidatos.contratar) o Sistemas (sistemas.altas.atender) pueden
 *    reenviarlo a mano.
 */
class AvisoAltaSistemasService
{
    public const PERMISO_REENVIAR = ContratacionCandidatoService::PERMISO_CONTRATAR;

    public const PERMISO_VER_BANDEJA = 'sistemas.altas.ver';

    public const PERMISO_ATENDER = 'sistemas.altas.atender';

    public const EVENTO_PENDIENTE = 'sistemas_alta_pendiente';

    public const SIN_CAPTURAR = 'Sin capturar';

    /**
     * Columnas de colaboradores sin las que el correo no sale solo (ver
     * datosCompletos()). Son también las que disparan la revisión al guardarse.
     *
     * @var list<string>
     */
    public const DATOS_IMPRESCINDIBLES = ['curp', 'rfc', 'nss', 'fecha_nacimiento', 'genero', 'telefono', 'correo_personal'];

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /** El CORREO está encendido y tiene a quién mandarse. */
    public function activo(): bool
    {
        return (bool) config('notificaciones.sistemas.activo') && $this->para() !== [];
    }

    /**
     * Crea el pendiente de una contratación, encola su correo (si está
     * activo y $enviarCorreo) y avisa a Sistemas en la plataforma. Nunca
     * lanza: lo llaman flujos que ya confirmaron su acción principal.
     */
    public function programar(
        Colaborador $colaborador,
        OrigenAvisoAltaSistemas $origen,
        int $origenId,
        ?User $actor = null,
        bool $enviarCorreo = true,
        bool $notificar = true,
    ): ?AvisoAltaSistemas {
        try {
            $mandar = $enviarCorreo && $this->activo();

            // Candidato que entra a Contratación: apenas tiene su QR y sus
            // datos están vacíos. El aviso se queda «en espera de sus datos»
            // y sale solo cuando los captura (liberarSiDatosCompletos()).
            $esperar = $enviarCorreo
                && $origen === OrigenAvisoAltaSistemas::Candidato
                && ! $this->datosCompletos($colaborador);

            $aviso = AvisoAltaSistemas::query()->firstOrCreate(
                ['origen_tipo' => $origen->value, 'origen_id' => $origenId],
                [
                    'colaborador_id' => $colaborador->id,
                    'estado' => match (true) {
                        $esperar => EstadoAvisoAltaSistemas::EsperandoDatos,
                        $mandar => EstadoAvisoAltaSistemas::Pendiente,
                        default => EstadoAvisoAltaSistemas::SinEnviar,
                    },
                    'solicitado_por' => $actor?->id,
                ],
            );

            // Ya existía: esta contratación ya tiene su aviso (enviado, en
            // cola o en espera). Repetir la acción no lo vuelve a mandar.
            if (! $aviso->wasRecentlyCreated || $esperar) {
                return $aviso;
            }

            if ($mandar) {
                EnviarAvisoAltaSistemasJob::dispatch($aviso->id);
            }

            if ($notificar) {
                $this->notificarPendiente($aviso, $colaborador);
            }

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
     * ¿Ya están los datos sin los que el correo llegaría vacío? Son los que
     * imprime el correo y captura la propia persona al registrarse en la app
     * (o RH en la web): CURP, RFC, NSS, nacimiento, género, teléfono y correo
     * personal (vale el del candidato), además de puesto y sucursal. Un campo
     * que el negocio liberó del registro
     * (config incorporacion.registro_app.campos_opcionales) no detiene el aviso.
     */
    public function datosCompletos(Colaborador $colaborador): bool
    {
        if ($colaborador->puesto_id === null || $colaborador->sucursal_principal_id === null) {
            return false;
        }

        $opcionales = (array) config('incorporacion.registro_app.campos_opcionales', []);

        foreach (array_diff(self::DATOS_IMPRESCINDIBLES, $opcionales) as $campo) {
            $valor = $colaborador->getAttribute($campo);

            if ($campo === 'correo_personal' && blank($valor)) {
                $valor = $colaborador->candidatoOrigen?->correo;
            }

            if (blank($valor)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Libera el aviso que quedó «en espera de sus datos» en cuanto el
     * colaborador tiene completos sus datos imprescindibles: encola el correo
     * (si está activo) y avisa a Sistemas en la plataforma. Punto ÚNICO para
     * cualquier camino que complete los datos (registro en la app por la API,
     * captura de RH en la web, corrección aprobada): lo llama el evento
     * `saved` de Colaborador (AppServiceProvider).
     *
     * Una sola vez: el cambio de estado es condicional (solo desde
     * `esperando_datos`), así que reintentos de la app, peticiones simultáneas
     * o ediciones posteriores no lo repiten. Nunca lanza.
     */
    public function liberarSiDatosCompletos(Colaborador $colaborador, ?User $actor = null): void
    {
        try {
            $aviso = AvisoAltaSistemas::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('estado', EstadoAvisoAltaSistemas::EsperandoDatos->value)
                ->latest('id')
                ->first();

            if ($aviso === null || ! $this->datosCompletos($colaborador)) {
                return;
            }

            $mandar = $this->activo();

            $tomados = AvisoAltaSistemas::query()
                ->where('id', $aviso->id)
                ->where('estado', EstadoAvisoAltaSistemas::EsperandoDatos->value)
                ->update(['estado' => ($mandar ? EstadoAvisoAltaSistemas::Pendiente : EstadoAvisoAltaSistemas::SinEnviar)->value]);

            if ($tomados === 0) {
                return;
            }

            if ($mandar) {
                EnviarAvisoAltaSistemasJob::dispatch($aviso->id, $actor?->id);
            }

            $this->notificarPendiente($aviso, $colaborador);
        } catch (Throwable $e) {
            Log::warning('AvisoAltaSistemasService: no se pudo liberar el aviso de alta a Sistemas.', [
                'colaborador_id' => $colaborador->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Red de seguridad: si el colaborador nunca tuvo aviso (alta digital,
     * alta directa de RH, contratado antes de existir el aviso), crea uno de
     * origen «colaborador». Si ya tiene uno, no hace nada. Nunca lanza.
     */
    public function asegurar(Colaborador $colaborador, ?User $actor = null, bool $enviarCorreo = true, bool $notificar = true): ?AvisoAltaSistemas
    {
        try {
            $existente = $this->ultimoDe($colaborador);

            if ($existente !== null) {
                return $existente;
            }
        } catch (Throwable $e) {
            Log::warning('AvisoAltaSistemasService: no se pudo revisar el aviso previo.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $this->programar($colaborador, OrigenAvisoAltaSistemas::Colaborador, $colaborador->id, $actor, $enviarCorreo, $notificar);
    }

    /**
     * Manda el correo de un aviso. Idempotente: si ya se envió (enviado_en
     * con valor) no hace nada — un reintento de la cola nunca duplica. El
     * reenvío manual limpia enviado_en antes de encolar (reencolarAviso()).
     * Nunca lanza; devuelve false solo si el envío falló (para reintentar).
     */
    public function enviar(int $avisoId, ?int $actorId = null): bool
    {
        $aviso = AvisoAltaSistemas::query()->where('id', $avisoId)->first();

        // «En espera de sus datos» nunca se manda: saldría vacío. Lo libera
        // liberarSiDatosCompletos() o un reenvío manual (que lo pasa a cola).
        if ($aviso === null || ! $this->activo() || $aviso->enviado_en !== null || $aviso->estado === EstadoAvisoAltaSistemas::EsperandoDatos) {
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

            Mail::to($para)->cc($cc)->send($this->mailable($colaborador, $aviso->origen_tipo, $aviso));

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
     * Reenvío manual desde la ficha del candidato (RH / super_admin): vuelve
     * a encolar el último aviso del colaborador; si nunca tuvo uno
     * (contratado antes de existir este aviso), crea uno de origen manual.
     */
    public function reenviar(Colaborador $colaborador, User $actor): AvisoAltaSistemas
    {
        if (! $this->puedeReenviar($actor, $colaborador)) {
            throw new AuthorizationException('No tienes permiso para reenviar el aviso de alta a Sistemas.');
        }

        $this->exigirActivo();

        return $this->reencolar($colaborador, $actor);
    }

    /**
     * Reenvío de un aviso concreto desde la bandeja de Sistemas.
     */
    public function reenviarAviso(AvisoAltaSistemas $aviso, User $actor): AvisoAltaSistemas
    {
        $colaborador = Colaborador::withTrashed()->where('id', $aviso->colaborador_id)->first();

        if ($colaborador === null || ! $this->puedeReenviar($actor, $colaborador)) {
            throw new AuthorizationException('No tienes permiso para reenviar el aviso de alta a Sistemas.');
        }

        $this->exigirActivo();

        return $this->reencolarAviso($aviso, $colaborador, $actor);
    }

    /**
     * RH con candidatos.contratar (dentro de su alcance) o Sistemas con
     * sistemas.altas.atender (da de alta a toda la organización).
     */
    public function puedeReenviar(User $actor, Colaborador $colaborador): bool
    {
        if ($actor->can(self::PERMISO_ATENDER)) {
            return true;
        }

        return $actor->can(self::PERMISO_REENVIAR) && $this->alcance->alcanzaColaborador($actor, $colaborador);
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

        if ($aviso->wasRecentlyCreated) {
            $this->notificarPendiente($aviso, $colaborador);
        }

        return $this->reencolarAviso($aviso, $colaborador, $actor);
    }

    public function ultimoDe(Colaborador $colaborador): ?AvisoAltaSistemas
    {
        return AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->latest('id')->first();
    }

    /**
     * Resumen para la ficha (estado del correo, si Sistemas ya lo atendió y
     * si se puede reenviar).
     *
     * @return array{estado: string|null, estado_etiqueta: string|null, enviado_en: string|null, ultimo_error: string|null, puede_reenviar: bool, correo_activo: bool, atendido: bool, atendido_en: string|null, atendido_en_texto: string|null, atendido_por: string|null, notas_atencion: string|null, url_bandeja: string|null}
     */
    public function resumen(Colaborador $colaborador, User $viewer): array
    {
        $aviso = $this->ultimoDe($colaborador);
        $aviso?->loadMissing('atendidoPor:id,name');

        return [
            'estado' => $aviso?->estado->value,
            'estado_etiqueta' => $aviso?->estado->etiqueta(),
            'enviado_en' => $aviso?->enviado_en?->toIso8601String(),
            'ultimo_error' => $aviso !== null && $aviso->estado === EstadoAvisoAltaSistemas::Fallido ? 'No se pudo enviar el correo.' : null,
            'puede_reenviar' => $this->activo() && $this->puedeReenviar($viewer, $colaborador),
            'correo_activo' => $this->activo(),
            'atendido' => $aviso?->atendido_en !== null,
            'atendido_en' => $aviso?->atendido_en?->toIso8601String(),
            'atendido_en_texto' => FechaTexto::doble($aviso?->atendido_en),
            'atendido_por' => $aviso?->atendidoPor?->name,
            'notas_atencion' => $aviso?->notas_atencion,
            'url_bandeja' => $viewer->can(self::PERMISO_VER_BANDEJA) ? route('sistemas.pendientes-alta.index', [], false) : null,
        ];
    }

    /**
     * Datos básicos para dar de alta a la persona en otros sistemas, con
     * «Sin capturar» en lo que falte. Fuente única del correo y de la bandeja
     * (botón «Copiar datos»), agrupada en tres tarjetas: Persona, Contacto y
     * Puesto.
     *
     * @return array{persona: array{titulo: string, datos: array<string, string>}, contacto: array{titulo: string, datos: array<string, string>}, puesto: array{titulo: string, datos: array<string, string>}}
     */
    public function datosBasicos(Colaborador $colaborador): array
    {
        $colaborador->loadMissing([
            'puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal.empresa',
            'jefe.puesto:id,nombre', 'user', 'candidatoOrigen',
        ]);

        $empresa = $colaborador->sucursalPrincipal?->empresa;
        $jefe = $colaborador->jefe;
        $usuario = $colaborador->user;

        return [
            'persona' => ['titulo' => 'Persona', 'datos' => $this->rellenar([
                'Nombre completo' => $colaborador->nombreCompleto(),
                'CURP' => $colaborador->curp !== null ? mb_strtoupper($colaborador->curp) : null,
                'RFC' => $colaborador->rfc !== null ? mb_strtoupper($colaborador->rfc) : null,
                'NSS' => $colaborador->nss,
                'Fecha de nacimiento' => FechaTexto::doble($colaborador->fecha_nacimiento),
                'Género' => $colaborador->genero?->etiqueta(),
            ])],
            'contacto' => ['titulo' => 'Contacto', 'datos' => $this->rellenar([
                'Teléfono' => $colaborador->telefono,
                'Correo personal' => $colaborador->correo_personal ?? $colaborador->candidatoOrigen?->correo,
                'Correo corporativo' => $colaborador->correo_corporativo,
            ])],
            'puesto' => ['titulo' => 'Puesto', 'datos' => $this->rellenar([
                'Sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'Puesto' => $colaborador->puesto?->nombre,
                'Departamento' => $colaborador->departamento?->nombre,
                'Jefe directo' => $jefe !== null ? trim(sprintf('%s %s', $jefe->nombreCompleto(), $jefe->puesto !== null ? sprintf('(%s)', $jefe->puesto->nombre) : '')) : null,
                'Fecha de ingreso' => FechaTexto::doble($colaborador->fecha_ingreso),
                'Número de empleado' => $colaborador->numero_empleado,
                'Empresa (razón social)' => $empresa !== null ? ($empresa->razon_social ?? $empresa->nombre) : null,
                'Tipo de contratación' => $colaborador->tipo_contratacion?->etiqueta(),
                'Usuario de PEOPLE' => $usuario !== null ? $usuario->username : 'Aún sin cuenta (la crea al registrarse con su QR)',
            ])],
        ];
    }

    /**
     * Todos los datos en una sola lista etiqueta => valor (copiar y pegar).
     *
     * @param  array<string, array{titulo: string, datos: array<string, string>}>  $secciones
     * @return array<string, string>
     */
    public static function aplanar(array $secciones): array
    {
        $plano = [];

        foreach ($secciones as $seccion) {
            $plano = [...$plano, ...$seccion['datos']];
        }

        return $plano;
    }

    public function mailable(Colaborador $colaborador, OrigenAvisoAltaSistemas $origen, ?AvisoAltaSistemas $aviso = null): AltaColaboradorSistemasMail
    {
        $secciones = $this->datosBasicos($colaborador);
        $ingreso = $colaborador->fecha_ingreso;

        return new AltaColaboradorSistemasMail(
            nombreCompleto: $colaborador->nombreCompleto(),
            puesto: $secciones['puesto']['datos']['Puesto'],
            sucursal: $secciones['puesto']['datos']['Sucursal'],
            tipoIngreso: $origen === OrigenAvisoAltaSistemas::Reingreso ? 'Reingreso' : 'Nuevo ingreso',
            secciones: $secciones,
            urlExpediente: route('rh.expedientes.show', $colaborador->id),
            urlDetalle: $aviso !== null ? route('sistemas.pendientes-alta.show', $aviso->id) : route('sistemas.pendientes-alta.index'),
            urlBandeja: route('sistemas.pendientes-alta.index'),
            fechaIngresoLarga: $ingreso !== null ? FechaTexto::larga($ingreso) : self::SIN_CAPTURAR,
            fechaIngresoCorta: $ingreso !== null ? $ingreso->format('d/m/Y') : self::SIN_CAPTURAR,
            logoUrl: sprintf('%s/branding/MrLanaPeople_horizontal_color.png', rtrim((string) config('app.url'), '/')),
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

    private function exigirActivo(): void
    {
        if (! $this->activo()) {
            throw ValidationException::withMessages([
                'aviso_sistemas' => 'El aviso de alta a Sistemas está desactivado (SISTEMAS_ALTA_CORREO_ACTIVO / SISTEMAS_ALTA_CORREO_PARA en el .env).',
            ]);
        }
    }

    private function reencolarAviso(AvisoAltaSistemas $aviso, Colaborador $colaborador, ?User $actor): AvisoAltaSistemas
    {
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

    /**
     * Aviso en la plataforma (campana, tiempo real y push) a quien da de alta
     * en sistemas, por la capa única de avisos. Nunca lanza.
     */
    private function notificarPendiente(AvisoAltaSistemas $aviso, Colaborador $colaborador): void
    {
        try {
            $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
            $partes = array_filter([$colaborador->puesto?->nombre, $colaborador->sucursalPrincipal?->nombre]);

            $destinatarios = User::permission(self::PERMISO_ATENDER)
                ->whereNull('acceso_bloqueado_en')
                ->get();

            app(NotificacionEventosService::class)->crearPendiente($destinatarios, new Aviso(
                evento: self::EVENTO_PENDIENTE,
                tipo: 'sistemas_alta',
                titulo: sprintf('Alta pendiente en sistemas: %s', $colaborador->nombreCompleto()),
                mensaje: sprintf(
                    '%s%s ya está listo para su alta. Dale de alta en las demás plataformas y márcalo como atendido.',
                    $colaborador->nombreCompleto(),
                    $partes !== [] ? sprintf(' (%s)', implode(', ', $partes)) : '',
                ),
                recurso: $aviso,
                accion: 'dar_de_alta',
                pushTitulo: 'Alta pendiente en sistemas',
            ));
        } catch (Throwable $e) {
            Log::warning('AvisoAltaSistemasService: no se pudo avisar a Sistemas en la plataforma.', [
                'aviso_id' => $aviso->id,
                'colaborador_id' => $colaborador->id,
                'error' => $e->getMessage(),
            ]);
        }
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
