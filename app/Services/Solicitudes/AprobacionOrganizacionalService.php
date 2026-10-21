<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Enums\TipoSolicitudInterna;
use App\Models\ClasificacionRegion;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\ResponsableAprobacion;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Organigrama\OrganigramaPersonasService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MOTOR CENTRAL DE APROBACIONES (docs/SOLICITUDES_UNIFICADAS.md →
 * «Motor de aprobaciones»). Única fuente de:
 *
 *  - quién es el RESPONSABLE DE APROBACIÓN de una persona (≠ su jefe
 *    organizacional del organigrama);
 *  - qué PASOS lleva una solicitud (vistos buenos → RH), quién responde cada
 *    paso, en qué estado va y si el usuario actual puede actuar o por qué
 *    todavía no (texto humano);
 *  - la ELEGIBILIDAD de préstamo (6 meses completos de antigüedad, fecha del
 *    servidor).
 *
 * Web (Inertia) y API móvil consumen este resultado; ningún controlador
 * decide por su cuenta. AprobacionJerarquicaService (decisiones por nivel)
 * resuelve sus niveles aquí.
 *
 * Regla de resolución del responsable (primer paso):
 *   1. responsable EXPLÍCITO de la persona (Organización → Responsables de
 *      aprobación);
 *   2. responsable explícito de su PUESTO;
 *   3. regla por puesto:
 *      a) Gerente de Sucursal (puesto que reporta a un regional) → Gerente
 *         Regional de SU clasificación Q1/Q3 (clasificaciones_region): el
 *         titular o, si la plaza está vacante, quien la cubre temporalmente.
 *         Sin clasificación → paso BLOQUEADO «Pendiente de asignar región de
 *         aprobación» (nunca se adivina ni sube a Dirección Comercial);
 *      b) personal de sucursal → gerencia de su sucursal (titular o
 *         cobertura vigente);
 *   4. jefe organizacional (organigrama; ya contempla coberturas).
 * Después: el regional de la región (personal de sucursal), Dirección
 * Comercial SOLO en los tipos de config `solicitudes.direccion_comercial_gerencia`
 * cuando la persona es de gerencia o superior y depende de Dirección
 * Comercial, y al final Recursos Humanos.
 */
class AprobacionOrganizacionalService
{
    public const ORIGEN_EXPLICITO = 'explicito';

    public const ORIGEN_EXPLICITO_PUESTO = 'explicito_puesto';

    public const ORIGEN_COBERTURA = 'cobertura';

    public const ORIGEN_REGIONAL = 'regional_clasificacion';

    public const ORIGEN_GERENCIA_SUCURSAL = 'gerencia_sucursal';

    public const ORIGEN_JEFE = 'jefe_organizacional';

    public const ORIGEN_PENDIENTE_REGION = 'pendiente_region';

    public const ORIGEN_REGIONAL_VACANTE = 'regional_vacante';

    public const ORIGEN_SIN_RESPONSABLE = 'sin_responsable';

    /** Estados de cada paso del flujo (contrato API). */
    public const PASO_APROBADO = 'aprobado';

    public const PASO_PENDIENTE = 'pendiente';

    public const PASO_ESPERANDO = 'esperando';

    public const PASO_NO_APLICA = 'no_aplica';

    public const PASO_RECHAZADO = 'rechazado';

    public const MENSAJE_PENDIENTE_REGION = 'Pendiente de asignar región de aprobación';

    public function __construct(
        private readonly OrganizacionJerarquiaService $organizacion,
        private readonly OrganigramaPersonasService $organigrama,
    ) {}

    // ------------------------------------------------------------------
    // Responsable de aprobación (expediente, organigrama, ficha)
    // ------------------------------------------------------------------

    /**
     * Jefe ORGANIZACIONAL (a quién reporta en el organigrama).
     */
    public function jefeOrganizacional(Colaborador $persona): ?Colaborador
    {
        return $this->organizacion->supervisorDirectoDe($persona);
    }

    /**
     * Responsable de APROBACIÓN de la persona (primer visto bueno de sus
     * solicitudes), con el origen de la regla que lo decidió.
     *
     * @return array{origen: string, origen_etiqueta: string, nivel: string, etiqueta_paso: string, colaboradores: Collection<int, Colaborador>, usuarios: Collection<int, User>, bloqueado: bool, mensaje: string|null, regla_id: int|null}
     */
    public function responsableDeAprobacion(Colaborador $persona): array
    {
        // 1 y 2. Explícito (persona, luego puesto).
        foreach ($this->reglasExplicitas($persona) as $regla) {
            $responsable = $regla->responsable;

            if ($responsable === null || $responsable->id === $persona->id) {
                continue;
            }

            $usuario = $this->organizacion->usuarioActivoDe($responsable);

            if ($usuario === null) {
                // Responsable sin cuenta activa (baja, acceso bloqueado): no
                // se queda atorado; sigue la regla organizacional.
                continue;
            }

            $porPuesto = $regla->colaborador_id === null;

            return $this->resultado(
                $porPuesto ? self::ORIGEN_EXPLICITO_PUESTO : self::ORIGEN_EXPLICITO,
                $porPuesto ? sprintf('Asignado por RH al puesto «%s»', (string) $persona->puesto?->nombre) : 'Asignado por RH',
                SolicitudAprobacion::NIVEL_JEFE_INMEDIATO,
                'Responsable de aprobación',
                collect([$responsable]),
                collect([$usuario]),
                reglaId: $regla->id,
            );
        }

        // 3a. Gerente de Sucursal → regional de SU clasificación Q1/Q3.
        if ($this->esClasificable($persona)) {
            return $this->regionalDeClasificacion($persona);
        }

        // 3b. Personal de sucursal → gerencia de su sucursal.
        if ($this->esDeSucursalOperativa($persona)) {
            $usuarios = $this->organizacion->gerenciaSucursalDe($persona->sucursal_principal_id, $persona);

            if ($usuarios->isNotEmpty()) {
                $porCobertura = ! $this->tieneGerenteTitular($persona);

                return $this->resultado(
                    $porCobertura ? self::ORIGEN_COBERTURA : self::ORIGEN_GERENCIA_SUCURSAL,
                    $porCobertura ? 'Cubre temporalmente la gerencia de su sucursal' : 'Gerente de su sucursal',
                    SolicitudAprobacion::NIVEL_JEFE_INMEDIATO,
                    'Gerente de sucursal',
                    $this->colaboradoresDe($usuarios),
                    $usuarios,
                );
            }
        }

        // 4. Jefe organizacional.
        $jefe = $this->jefeOrganizacional($persona);
        $usuarioJefe = $this->organizacion->usuarioActivoDe($jefe);

        if ($jefe !== null && $usuarioJefe !== null) {
            return $this->resultado(
                self::ORIGEN_JEFE,
                'Jefe organizacional (organigrama)',
                SolicitudAprobacion::NIVEL_JEFE_INMEDIATO,
                'Jefe directo',
                collect([$jefe]),
                collect([$usuarioJefe]),
            );
        }

        return $this->resultado(
            self::ORIGEN_SIN_RESPONSABLE,
            $jefe !== null ? 'Su jefe no tiene cuenta activa: va directo a RH' : 'Sin jefe en el organigrama: va directo a RH',
            SolicitudAprobacion::NIVEL_JEFE_INMEDIATO,
            'Jefe directo',
            collect(),
            collect(),
            mensaje: 'No tiene jefe ni responsable de aprobación con cuenta activa: sus solicitudes van directo a Recursos Humanos.',
        );
    }

    /**
     * Resumen para el expediente / ficha / organigrama: «Jefe organizacional:
     * X» y «Responsable de aprobaciones: Y».
     *
     * @return array{jefe_organizacional: array{id: int, nombre: string, puesto: string|null}|null, responsable_aprobaciones: array{id: int, nombre: string, puesto: string|null}|null, origen: string, origen_etiqueta: string, mensaje: string|null, bloqueado: bool, distinto_del_jefe: bool, regla_id: int|null}
     */
    public function resumenResponsables(Colaborador $persona): array
    {
        $jefe = $this->jefeOrganizacional($persona);
        $responsable = $this->responsableDeAprobacion($persona);
        $principal = $responsable['colaboradores']->first();

        return [
            'jefe_organizacional' => $jefe !== null ? $this->personaCorta($jefe) : null,
            'responsable_aprobaciones' => $principal instanceof Colaborador ? $this->personaCorta($principal) : null,
            'origen' => $responsable['origen'],
            'origen_etiqueta' => $responsable['origen_etiqueta'],
            'mensaje' => $responsable['mensaje'],
            'bloqueado' => $responsable['bloqueado'],
            'distinto_del_jefe' => $principal instanceof Colaborador && $principal->id !== $jefe?->id,
            'regla_id' => $responsable['regla_id'],
        ];
    }

    // ------------------------------------------------------------------
    // Pasos de una solicitud
    // ------------------------------------------------------------------

    /**
     * Pasos de VISTO BUENO (antes de RH) que aplican a la solicitud, en
     * orden. Un paso bloqueado no tiene usuarios y detiene el flujo hasta
     * que se corrija la estructura (nunca se salta ni se inventa).
     *
     * @return list<array{nivel: string, etiqueta: string, origen: string, usuarios: Collection<int, User>, colaboradores: Collection<int, Colaborador>, bloqueado: bool, motivo_bloqueo: string|null}>
     */
    public function pasos(SolicitudInterna $solicitud): array
    {
        $persona = $this->personaCompleta($solicitud);

        if ($persona === null || ! $this->requiereVistosBuenos($solicitud->tipo)) {
            return [];
        }

        $pasos = [];
        $responsable = $this->responsableDeAprobacion($persona);

        if ($responsable['bloqueado'] || $responsable['usuarios']->isNotEmpty()) {
            $pasos[] = [
                'nivel' => $responsable['nivel'],
                'etiqueta' => $responsable['etiqueta_paso'],
                'origen' => $responsable['origen'],
                'usuarios' => $responsable['usuarios'],
                'colaboradores' => $responsable['colaboradores'],
                'bloqueado' => $responsable['bloqueado'],
                'motivo_bloqueo' => $responsable['bloqueado'] ? $responsable['mensaje'] : null,
            ];
        }

        // Regional (personal de sucursal que no es el propio gerente): solo
        // cuando el primer paso no fue ya el regional.
        if ($responsable['nivel'] !== SolicitudAprobacion::NIVEL_REGIONAL && ! $this->esClasificable($persona) && $this->esDeSucursalOperativa($persona)) {
            $yaIncluidos = $responsable['usuarios']->pluck('id');
            $regionales = $this->regionalesParaPersonal($persona)
                ->reject(fn (User $u) => $yaIncluidos->contains($u->id) || $u->colaborador_id === $persona->id)
                ->values();

            if ($regionales->isNotEmpty()) {
                $pasos[] = [
                    'nivel' => SolicitudAprobacion::NIVEL_REGIONAL,
                    'etiqueta' => 'Gerente Regional',
                    'origen' => self::ORIGEN_REGIONAL,
                    'usuarios' => $regionales,
                    'colaboradores' => $this->colaboradoresDe($regionales),
                    'bloqueado' => false,
                    'motivo_bloqueo' => null,
                ];
            }
        }

        // Dirección Comercial: solo los tipos que lo exigen, para gerencia o
        // superior que depende de Dirección Comercial.
        if ($this->requiereDireccionComercial($solicitud->tipo, $persona)) {
            $yaIncluidos = collect($pasos)->flatMap(fn (array $p) => $p['usuarios']->pluck('id'));
            $direccion = $this->organizacion->direccionComercialDe($persona)
                ->reject(fn (User $u) => $yaIncluidos->contains($u->id) || $u->colaborador_id === $persona->id)
                ->values();

            if ($direccion->isNotEmpty()) {
                $pasos[] = [
                    'nivel' => SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL,
                    'etiqueta' => 'Dirección Comercial',
                    'origen' => 'direccion_comercial',
                    'usuarios' => $direccion,
                    'colaboradores' => $this->colaboradoresDe($direccion),
                    'bloqueado' => false,
                    'motivo_bloqueo' => null,
                ];
            }
        }

        return $pasos;
    }

    public function requiereVistosBuenos(TipoSolicitudInterna $tipo): bool
    {
        return in_array($tipo->value, (array) config('solicitudes.visto_bueno_jefe', []), true);
    }

    /**
     * true si la persona ocupa un puesto de gerencia o superior: el mismo
     * puesto que normalmente APRUEBA a otros (gerente de sucursal, regional)
     * o un nivel jerárquico de gerencia (1-3) capturado en su puesto.
     */
    public function esGerenciaOSuperior(Colaborador $persona): bool
    {
        $puesto = $persona->puesto;

        if ($puesto === null) {
            return false;
        }

        $nombresGerencia = [...(array) config('ciclo_laboral.organizacion.puestos_gerencia_sucursal', []), ...(array) config('organigrama.puestos_de_region', [])];

        if (in_array($puesto->nombre, $nombresGerencia, true)) {
            return true;
        }

        return $puesto->nivel_jerarquico !== null && $puesto->nivel_jerarquico <= 3;
    }

    /**
     * Dirección Comercial solo cuando el TIPO lo exige (config) y la persona
     * es de gerencia o superior DENTRO de Dirección Comercial: quien está al
     * nivel de Dirección Comercial (áreas de Dirección General: RH,
     * Contraloría, Sistemas) no pasa por ella.
     */
    public function requiereDireccionComercial(TipoSolicitudInterna $tipo, Colaborador $persona): bool
    {
        if (! in_array($tipo->value, (array) config('solicitudes.direccion_comercial_gerencia', []), true)) {
            return false;
        }

        return $this->esGerenciaOSuperior($persona) && $this->dependeDeDireccionComercial($persona);
    }

    // ------------------------------------------------------------------
    // Flujo (contrato web/API)
    // ------------------------------------------------------------------

    /**
     * Flujo completo de la solicitud para pantallas (web y app): pasos
     * ordenados con su responsable y estado, si el usuario actual puede
     * actuar y, si no, por qué (texto humano). Contrato documentado en
     * docs/SOLICITUDES_UNIFICADAS.md.
     *
     * @return array{pasos: list<array<string, mixed>>, puede_actuar_usuario_actual: bool, accion_usuario_actual: string|null, motivo_no_puede: string|null, pendiente_de: array{clave: string, etiqueta: string, nombres: list<string>, texto: string}|null, estado_texto: string, bloqueado: bool}
     */
    public function flujo(SolicitudInterna $solicitud, ?User $actor = null): array
    {
        $persona = $this->personaCompleta($solicitud);
        $estado = $solicitud->estado;
        $vistos = $this->pasos($solicitud);
        $decisiones = SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->with('usuario.colaborador')->orderBy('id')->get()->keyBy('nivel');
        $esBaja = $solicitud->tipo === TipoSolicitudInterna::BajaColaborador;

        $pasos = [[
            'orden' => 1,
            'clave' => 'creada',
            'rol' => 'Solicitante',
            'etiqueta' => 'Solicitud creada',
            'responsable' => $persona !== null ? ['id' => $persona->id, 'nombre' => $persona->nombreCompleto()] : null,
            'responsables' => [],
            'estado' => self::PASO_APROBADO,
            'estado_etiqueta' => 'Enviada',
            'fecha' => $solicitud->created_at?->toIso8601String(),
            'comentario' => null,
            'decidio' => null,
            'bloqueado' => false,
            'puede_actuar' => false,
            'motivo_no_puede' => null,
        ]];

        $finalCerrada = in_array($estado, [EstadoSolicitudInterna::Rechazada, EstadoSolicitudInterna::Cancelada], true);
        $pendiente = null;
        $rechazadaEnVisto = false;
        $actorPuede = false;
        $accion = null;

        foreach ($vistos as $i => $paso) {
            $decision = $decisiones->get($paso['nivel']);
            $responsables = $paso['colaboradores']->map(fn (Colaborador $c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto()])->values()->all();
            $decidio = $decision?->usuario;
            $puede = false;
            $motivo = null;

            if ($decision !== null) {
                $estadoPaso = $decision->decision === SolicitudAprobacion::DECISION_APROBADO ? self::PASO_APROBADO : self::PASO_RECHAZADO;
                $rechazadaEnVisto = $rechazadaEnVisto || $estadoPaso === self::PASO_RECHAZADO;
            } elseif ($finalCerrada || $estado === EstadoSolicitudInterna::Aprobada || $rechazadaEnVisto) {
                $estadoPaso = self::PASO_NO_APLICA;
            } elseif ($pendiente === null) {
                $estadoPaso = self::PASO_PENDIENTE;
                $pendiente = $paso;
                $puede = $actor !== null && ! $paso['bloqueado'] && $paso['usuarios']->contains('id', $actor->id) && ! $estado->esFinal();
                $motivo = $puede ? null : ($paso['bloqueado'] ? $paso['motivo_bloqueo'] : sprintf('Este paso le corresponde a %s.', $this->listaNombres($responsables)));
            } else {
                $estadoPaso = self::PASO_ESPERANDO;
                $motivo = sprintf('%s podrá dar su visto bueno cuando %s dé el suyo.', $paso['etiqueta'], $this->nombrePaso($pendiente));
            }

            if ($puede) {
                $actorPuede = true;
                $accion = 'visto_bueno';
            }

            $pasos[] = [
                'orden' => $i + 2,
                'clave' => $paso['nivel'],
                'rol' => $paso['etiqueta'],
                'etiqueta' => $paso['etiqueta'],
                'responsable' => $decidio !== null ? ['id' => $decidio->colaborador_id ?? $decidio->id, 'nombre' => $decidio->nombreCompleto()] : ($responsables[0] ?? null),
                'responsables' => $responsables,
                'estado' => $estadoPaso,
                'estado_etiqueta' => $this->etiquetaEstadoPaso($estadoPaso, $paso['bloqueado']),
                'fecha' => $decision?->created_at?->toIso8601String(),
                'comentario' => $decision?->comentario,
                'decidio' => $decidio !== null ? ['id' => $decidio->colaborador_id ?? $decidio->id, 'nombre' => $decidio->nombreCompleto()] : null,
                'bloqueado' => $estadoPaso === self::PASO_PENDIENTE && $paso['bloqueado'],
                'puede_actuar' => $puede,
                'motivo_no_puede' => $motivo,
            ];
        }

        // Paso final: Recursos Humanos.
        $esPrestamo = $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno;
        $revisor = $solicitud->revisadoPor;
        $rhPuede = false;
        $rhMotivo = null;

        if ($esBaja) {
            $rhEstado = self::PASO_NO_APLICA;
            $rhMotivo = SolicitudesService::MENSAJE_BAJA_EN_CIERRE;
        } elseif ($estado === EstadoSolicitudInterna::Aprobada) {
            $rhEstado = self::PASO_APROBADO;
        } elseif ($estado === EstadoSolicitudInterna::Rechazada) {
            $rhEstado = $rechazadaEnVisto ? self::PASO_NO_APLICA : self::PASO_RECHAZADO;
        } elseif ($estado === EstadoSolicitudInterna::Cancelada) {
            $rhEstado = self::PASO_NO_APLICA;
        } elseif ($pendiente !== null) {
            $rhEstado = self::PASO_ESPERANDO;
            $rhMotivo = $pendiente['bloqueado']
                ? sprintf('RH podrá aprobar cuando se resuelva: %s.', mb_strtolower((string) $pendiente['motivo_bloqueo']))
                : sprintf('RH podrá aprobar cuando %s dé su visto bueno.', $this->nombrePaso($pendiente));
        } else {
            $rhEstado = self::PASO_PENDIENTE;
            $rhPuede = $actor !== null && $this->actorPuedeAutorizar($actor, $solicitud);

            if (! $rhPuede) {
                $rhMotivo = $estado === EstadoSolicitudInterna::RequiereCorreccion
                    ? sprintf('Espera la corrección de %s.', $persona?->nombreCompleto() ?? 'quien la envió')
                    : 'Este paso le corresponde a Recursos Humanos.';
            }
        }

        if ($rhPuede) {
            $actorPuede = true;
            $accion = $esPrestamo ? 'autorizar_prestamo' : 'aprobar';
        }

        $pasos[] = [
            'orden' => count($pasos) + 1,
            'clave' => 'rh',
            'rol' => 'Recursos Humanos',
            'etiqueta' => $esPrestamo ? 'RH autoriza el préstamo' : 'Recursos Humanos autoriza',
            'responsable' => $revisor !== null && in_array($rhEstado, [self::PASO_APROBADO, self::PASO_RECHAZADO], true) ? ['id' => $revisor->colaborador_id ?? $revisor->id, 'nombre' => $revisor->nombreCompleto()] : null,
            'responsables' => [],
            'estado' => $rhEstado,
            'estado_etiqueta' => $this->etiquetaEstadoPaso($rhEstado, false),
            'fecha' => in_array($rhEstado, [self::PASO_APROBADO, self::PASO_RECHAZADO], true) ? $solicitud->revisado_en?->toIso8601String() : null,
            'comentario' => $rhEstado === self::PASO_RECHAZADO ? $solicitud->motivo_rechazo : null,
            'decidio' => $revisor !== null && in_array($rhEstado, [self::PASO_APROBADO, self::PASO_RECHAZADO], true) ? ['id' => $revisor->colaborador_id ?? $revisor->id, 'nombre' => $revisor->nombreCompleto()] : null,
            'bloqueado' => false,
            'puede_actuar' => $rhPuede,
            'motivo_no_puede' => $rhMotivo,
        ];

        $pendienteDe = $this->pendienteDe($solicitud, $pendiente, $rhEstado);

        return [
            'pasos' => $pasos,
            'puede_actuar_usuario_actual' => $actorPuede,
            'accion_usuario_actual' => $accion,
            'motivo_no_puede' => $actorPuede || $actor === null ? null : $this->motivoParaActor($actor, $solicitud, $vistos, $pendiente, $rhMotivo),
            'pendiente_de' => $pendienteDe,
            'estado_texto' => $pendienteDe['texto'] ?? $estado->etiqueta(),
            'bloqueado' => $pendiente !== null && $pendiente['bloqueado'],
        ];
    }

    /**
     * true si el usuario puede actuar AHORA sobre la solicitud (visto bueno
     * de su paso o autorización final de RH).
     */
    public function puedeActuar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $this->flujo($solicitud, $usuario)['puede_actuar_usuario_actual'];
    }

    /**
     * Cuentas que pueden actuar AHORA (para avisar solo a ellas): las del
     * paso de visto bueno pendiente o, con todos los vistos, RH con alcance.
     * Vacío si el paso pendiente está bloqueado o la solicitud ya es final.
     *
     * @return Collection<int, User>
     */
    public function quienesPuedenActuar(SolicitudInterna $solicitud): Collection
    {
        if ($solicitud->estado->esFinal()) {
            return collect();
        }

        $decididos = SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->pluck('decision', 'nivel');

        foreach ($this->pasos($solicitud) as $paso) {
            if (($decididos[$paso['nivel']] ?? null) === SolicitudAprobacion::DECISION_APROBADO) {
                continue;
            }

            return $paso['bloqueado'] ? collect() : $paso['usuarios'];
        }

        $permiso = $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno ? 'prestamos.autorizar' : 'rh.solicitudes.aprobar';

        return User::query()
            ->permission($permiso)
            ->whereNull('acceso_bloqueado_en')
            ->get()
            ->filter(fn (User $u) => $this->actorPuedeAutorizar($u, $solicitud))
            ->values();
    }

    /**
     * Conteo ÚNICO de «mis aprobaciones pendientes» (web y app): solicitudes
     * donde la cuenta puede actuar AHORA — visto bueno de su paso o
     * autorización final de RH con todos los vistos dados. No cuenta las que
     * esperan un paso anterior ni las que esperan al colaborador.
     *
     * @return array{visto_bueno: int, autorizar: int, total: int}
     */
    public function conteoPendientes(User $usuario): array
    {
        // Vistos buenos: cualquier solicitud «Recibida» cuyo paso pendiente
        // le corresponde (responsable explícito, gerente, regional Q1/Q3…).
        $vistoBueno = SolicitudInterna::query()
            ->where('estado', EstadoSolicitudInterna::Enviada->value)
            ->whereIn('tipo', (array) config('solicitudes.visto_bueno_jefe', []))
            ->orderBy('created_at')
            ->limit(500)
            ->get()
            ->filter(fn (SolicitudInterna $s) => $this->quienesPuedenActuar($s)->contains('id', $usuario->id) && ! $this->todosLosVistosDados($s))
            ->count();

        // Autorización final: solo quien tiene permiso, dentro de su alcance
        // de revisión, y solo cuando ya están todos los vistos buenos.
        $autorizar = 0;

        if ($usuario->can('rh.solicitudes.aprobar') || $usuario->can('solicitudes.aprobar') || $usuario->can('prestamos.autorizar')) {
            $consulta = SolicitudInterna::query()
                ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
                ->where('tipo', '!=', TipoSolicitudInterna::BajaColaborador->value)
                ->orderBy('created_at')
                ->limit(500);

            $autorizar = app(SolicitudesService::class)->limitarRevisionPorAlcance($consulta, $usuario)
                ->get()
                ->filter(fn (SolicitudInterna $s) => $this->todosLosVistosDados($s) && $this->actorPuedeAutorizar($usuario, $s))
                ->count();
        }

        return ['visto_bueno' => $vistoBueno, 'autorizar' => $autorizar, 'total' => $vistoBueno + $autorizar];
    }

    private function todosLosVistosDados(SolicitudInterna $solicitud): bool
    {
        $aprobados = SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->where('decision', SolicitudAprobacion::DECISION_APROBADO)->pluck('nivel')->all();

        foreach ($this->pasos($solicitud) as $paso) {
            if (! in_array($paso['nivel'], $aprobados, true)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Préstamos: 6 meses completos de antigüedad
    // ------------------------------------------------------------------

    /**
     * Elegibilidad de préstamo con la fecha del SERVIDOR (nunca la del
     * dispositivo): 6 meses completos desde la fecha de ingreso
     * (config solicitudes.prestamo_meses_minimos). Ingreso 20/10/2026 →
     * disponible desde 20/04/2027.
     *
     * @return array{cumple: bool, meses_minimos: int, fecha_ingreso: string|null, antiguedad: array{anios: int, meses: int, dias: int}|null, antiguedad_texto: string|null, disponible_desde: string|null, disponible_desde_texto: string|null, fecha_servidor: string, mensaje: string}
     */
    public function elegibilidadPrestamo(?Colaborador $persona, ?CarbonInterface $hoy = null): array
    {
        $meses = max(0, (int) config('solicitudes.prestamo_meses_minimos', 6));
        $hoy = Carbon::parse(($hoy ?? now())->toDateString())->startOfDay();
        $base = ['cumple' => false, 'meses_minimos' => $meses, 'fecha_ingreso' => null, 'antiguedad' => null, 'antiguedad_texto' => null, 'disponible_desde' => null, 'disponible_desde_texto' => null, 'fecha_servidor' => $hoy->toDateString()];

        if ($persona === null) {
            return [...$base, 'mensaje' => 'Tu cuenta no está vinculada a un expediente de colaborador; pide a RH que la vincule.'];
        }

        if ($persona->fecha_ingreso === null) {
            return [...$base, 'mensaje' => 'No tenemos registrada tu fecha de ingreso. Pide a RH que la capture en tu expediente.'];
        }

        $ingreso = Carbon::parse($persona->fecha_ingreso->toDateString())->startOfDay();
        $disponible = $ingreso->copy()->addMonthsNoOverflow($meses);
        $diferencia = $ingreso->lessThanOrEqualTo($hoy) ? $ingreso->diff($hoy) : null;
        $antiguedad = $diferencia !== null ? ['anios' => (int) $diferencia->y, 'meses' => (int) $diferencia->m, 'dias' => (int) $diferencia->d] : ['anios' => 0, 'meses' => 0, 'dias' => 0];
        $cumple = $hoy->greaterThanOrEqualTo($disponible);

        return [
            ...$base,
            'cumple' => $cumple,
            'fecha_ingreso' => $ingreso->toDateString(),
            'antiguedad' => $antiguedad,
            'antiguedad_texto' => $this->textoAntiguedad($antiguedad),
            'disponible_desde' => $disponible->toDateString(),
            'disponible_desde_texto' => $disponible->format('d/m/Y'),
            'mensaje' => $cumple
                ? 'Ya puedes solicitar un préstamo.'
                : sprintf('Disponible al cumplir %d meses de antigüedad: a partir del %s.', $meses, $disponible->format('d/m/Y')),
        ];
    }

    /**
     * @throws ValidationException 422 si no cumple la antigüedad mínima.
     */
    public function validarElegibilidadPrestamo(?Colaborador $persona, string $campo = 'monto_solicitado'): void
    {
        $elegibilidad = $this->elegibilidadPrestamo($persona);

        if (! $elegibilidad['cumple']) {
            throw ValidationException::withMessages([$campo => $elegibilidad['mensaje']]);
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * @return list<ResponsableAprobacion>
     */
    private function reglasExplicitas(Colaborador $persona): array
    {
        $reglas = ResponsableAprobacion::query()
            ->with('responsable.user')
            ->where(fn ($q) => $q->where('colaborador_id', $persona->id)
                ->when($persona->puesto_id !== null, fn ($sub) => $sub->orWhere('puesto_id', $persona->puesto_id)))
            ->get();

        // Persona primero, luego puesto.
        return array_values($reglas->sortBy(fn (ResponsableAprobacion $r) => $r->colaborador_id !== null ? 0 : 1)->all());
    }

    /**
     * La persona de la solicitud con TODAS sus columnas: las pantallas cargan
     * la relación con columnas recortadas (sin jefe_id/estatus) y el motor
     * no puede resolver con datos a medias.
     */
    private function personaCompleta(SolicitudInterna $solicitud): ?Colaborador
    {
        $persona = $solicitud->personaSolicitante();

        return $persona === null ? null : Colaborador::withTrashed()->where('id', $persona->id)->first();
    }

    private function esClasificable(Colaborador $persona): bool
    {
        return $persona->puesto_id !== null && $this->organigrama->esPuestoClasificable($persona->puesto_id);
    }

    /**
     * @return array{origen: string, origen_etiqueta: string, nivel: string, etiqueta_paso: string, colaboradores: Collection<int, Colaborador>, usuarios: Collection<int, User>, bloqueado: bool, mensaje: string|null, regla_id: int|null}
     */
    private function regionalDeClasificacion(Colaborador $persona): array
    {
        $clasificacion = ClasificacionRegion::query()->where('colaborador_id', $persona->id)->with('region')->first();
        $region = $clasificacion?->region;

        if ($region === null) {
            return $this->resultado(
                self::ORIGEN_PENDIENTE_REGION,
                self::MENSAJE_PENDIENTE_REGION,
                SolicitudAprobacion::NIVEL_REGIONAL,
                'Gerente Regional',
                collect(),
                collect(),
                bloqueado: true,
                mensaje: sprintf('%s: RH debe clasificar a %s en Q1 o Q3 (Organigrama).', self::MENSAJE_PENDIENTE_REGION, $persona->nombreCompleto()),
            );
        }

        $puestoRegional = $region->puesto_id !== null ? Puesto::query()->whereKey($region->puesto_id)->first() : null;
        $etiqueta = $puestoRegional !== null ? $puestoRegional->nombre : sprintf('Gerente Regional %s', (string) ($region->region ?? $region->nombre));
        [$colaboradores, $porCobertura] = $this->ocupantesRegion($region, $persona);
        $usuarios = $colaboradores->map(fn (Colaborador $c) => $this->organizacion->usuarioActivoDe($c))->filter()->unique('id')->values();

        if ($usuarios->isEmpty()) {
            return $this->resultado(
                self::ORIGEN_REGIONAL_VACANTE,
                sprintf('%s sin titular ni cobertura', $etiqueta),
                SolicitudAprobacion::NIVEL_REGIONAL,
                $etiqueta,
                collect(),
                collect(),
                bloqueado: true,
                mensaje: sprintf('La plaza de %s está vacante y nadie la cubre: RH debe asignar una cobertura temporal o un responsable de aprobación.', $etiqueta),
            );
        }

        return $this->resultado(
            $porCobertura ? self::ORIGEN_COBERTURA : self::ORIGEN_REGIONAL,
            $porCobertura ? sprintf('Cubre temporalmente %s', $etiqueta) : sprintf('Regional de su región (%s)', (string) ($region->region ?? $region->nombre)),
            SolicitudAprobacion::NIVEL_REGIONAL,
            $etiqueta,
            $this->colaboradoresDe($usuarios),
            $usuarios,
        );
    }

    /**
     * Titulares activos del puesto regional de la región o, si no hay,
     * quienes lo cubren temporalmente.
     *
     * @return array{0: Collection<int, Colaborador>, 1: bool}
     */
    private function ocupantesRegion(NodoComercial $region, ?Colaborador $excluir): array
    {
        if ($region->puesto_id === null) {
            return [collect(), false];
        }

        $titulares = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('puesto_id', $region->puesto_id)
            ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir?->id))
            ->get()
            ->toBase();

        if ($titulares->isNotEmpty()) {
            return [$titulares, false];
        }

        $cobertura = CoberturaPuesto::query()
            ->where('activa', true)
            ->where('puesto_id', $region->puesto_id)
            ->where(fn ($q) => $q->whereNull('region_id')->orWhere('region_id', $region->id))
            ->with('colaborador')
            ->get()
            ->pluck('colaborador')
            ->filter(fn ($c) => $c instanceof Colaborador && $c->estatus === EstadoUsuario::Activo && $c->id !== $excluir?->id)
            ->values()
            ->toBase();

        return [$cobertura, true];
    }

    /**
     * Regional del personal de sucursal: el de la clasificación Q1/Q3 del
     * gerente de su sucursal; sin clasificación, la regla de siempre
     * (matriz comercial + cadena de mando).
     *
     * @return Collection<int, User>
     */
    private function regionalesParaPersonal(Colaborador $persona): Collection
    {
        $gerentes = $this->puestosGerenciaSucursal();
        $region = null;

        if ($persona->sucursal_principal_id !== null && $gerentes !== []) {
            $gerente = Colaborador::query()
                ->where('estatus', EstadoUsuario::Activo->value)
                ->where('sucursal_principal_id', $persona->sucursal_principal_id)
                ->whereIn('puesto_id', $gerentes)
                ->first();
            $region = $gerente !== null ? ClasificacionRegion::query()->where('colaborador_id', $gerente->id)->with('region')->first()?->region : null;
        }

        if ($region !== null && $region->tipo === TipoNodoComercial::Region) {
            [$colaboradores] = $this->ocupantesRegion($region, $persona);

            $usuarios = $colaboradores->map(fn (Colaborador $c) => $this->organizacion->usuarioActivoDe($c))->filter()->unique('id')->values();

            if ($usuarios->isNotEmpty()) {
                return $usuarios;
            }
        }

        return $this->organizacion->regionalesDe($persona->sucursal_principal_id, $persona);
    }

    /**
     * @return list<int>
     */
    private function puestosGerenciaSucursal(): array
    {
        /** @var list<string> $nombres */
        $nombres = (array) config('ciclo_laboral.organizacion.puestos_gerencia_sucursal', []);

        return $nombres === [] ? [] : array_values(Puesto::query()->whereIn('nombre', $nombres)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    private function tieneGerenteTitular(Colaborador $persona): bool
    {
        $puestos = $this->puestosGerenciaSucursal();

        return $puestos !== [] && Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('sucursal_principal_id', $persona->sucursal_principal_id)
            ->whereIn('puesto_id', $puestos)
            ->where('id', '!=', $persona->id)
            ->exists();
    }

    /**
     * Persona de una sucursal operativa (no Corporativo): su primer visto
     * bueno es la gerencia de su sucursal.
     */
    private function esDeSucursalOperativa(Colaborador $persona): bool
    {
        if ($persona->sucursal_principal_id === null) {
            return false;
        }

        $sucursal = Sucursal::query()->whereKey($persona->sucursal_principal_id)->first(['id', 'es_corporativo']);

        return $sucursal !== null && ! (bool) $sucursal->es_corporativo;
    }

    /**
     * ¿El puesto de la persona cuelga de Dirección Comercial? Si el catálogo
     * no tiene «reporta a» capturado, se conserva la regla anterior (sí).
     */
    private function dependeDeDireccionComercial(Colaborador $persona): bool
    {
        /** @var list<string> $nombresDireccion */
        $nombresDireccion = (array) config('ciclo_laboral.organizacion.puestos_direccion_comercial', []);
        $direccion = Puesto::query()->whereIn('nombre', $nombresDireccion)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $puestoId = $persona->puesto_id;

        if ($puestoId === null || in_array($puestoId, $direccion, true)) {
            return false;
        }

        $actual = Puesto::query()->whereKey($puestoId)->first(['id', 'puesto_superior_id']);

        if ($actual === null || $actual->puesto_superior_id === null) {
            return true;
        }

        $vistos = [];

        for ($i = 0; $i < 15 && $actual !== null && $actual->puesto_superior_id !== null; $i++) {
            if (in_array($actual->puesto_superior_id, $direccion, true)) {
                return true;
            }

            if (isset($vistos[$actual->puesto_superior_id])) {
                break;
            }

            $vistos[$actual->puesto_superior_id] = true;
            $actual = Puesto::query()->whereKey($actual->puesto_superior_id)->first(['id', 'puesto_superior_id']);
        }

        return false;
    }

    private function actorPuedeAutorizar(User $actor, SolicitudInterna $solicitud): bool
    {
        if ($solicitud->estado->esFinal() || ! $solicitud->estado->puedeTransicionarA(EstadoSolicitudInterna::Aprobada)) {
            return false;
        }

        if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
            return false;
        }

        if ($solicitud->tipo === TipoSolicitudInterna::PrestamoInterno) {
            return $solicitud->prestamo()->doesntExist() && Gate::forUser($actor)->allows('autorizarSolicitud', [Prestamo::class, $solicitud]);
        }

        return Gate::forUser($actor)->allows('aprobar', $solicitud);
    }

    /**
     * @param  list<array{nivel: string, etiqueta: string, origen: string, usuarios: Collection<int, User>, colaboradores: Collection<int, Colaborador>, bloqueado: bool, motivo_bloqueo: string|null}>  $vistos
     * @param  array{nivel: string, etiqueta: string, origen: string, usuarios: Collection<int, User>, colaboradores: Collection<int, Colaborador>, bloqueado: bool, motivo_bloqueo: string|null}|null  $pendiente
     */
    private function motivoParaActor(User $actor, SolicitudInterna $solicitud, array $vistos, ?array $pendiente, ?string $rhMotivo): ?string
    {
        if ($solicitud->estado->esFinal()) {
            return sprintf('La solicitud ya está %s.', mb_strtolower($solicitud->estado->etiqueta()));
        }

        if ($solicitud->esDe($actor)) {
            return 'Es tu propia solicitud: la revisan tus responsables.';
        }

        if ($pendiente !== null) {
            if ($pendiente['bloqueado']) {
                return (string) $pendiente['motivo_bloqueo'];
            }

            foreach ($vistos as $paso) {
                if ($paso['nivel'] !== $pendiente['nivel'] && $paso['usuarios']->contains('id', $actor->id)) {
                    return sprintf('Podrás dar tu visto bueno cuando %s dé el suyo.', $this->nombrePaso($pendiente));
                }
            }

            $actorEsRh = $actor->can('rh.solicitudes.aprobar') || $actor->can('solicitudes.aprobar') || $actor->can('prestamos.autorizar');

            return $actorEsRh
                ? sprintf('RH podrá aprobar cuando %s dé su visto bueno.', $this->nombrePaso($pendiente))
                : sprintf('Pendiente de aprobación de: %s.', $this->nombrePaso($pendiente));
        }

        return $rhMotivo;
    }

    /**
     * @param  array{nivel: string, etiqueta: string, origen: string, usuarios: Collection<int, User>, colaboradores: Collection<int, Colaborador>, bloqueado: bool, motivo_bloqueo: string|null}|null  $pendiente
     * @return array{clave: string, etiqueta: string, nombres: list<string>, texto: string}|null
     */
    private function pendienteDe(SolicitudInterna $solicitud, ?array $pendiente, string $rhEstado): ?array
    {
        if ($pendiente !== null) {
            $nombres = array_values($pendiente['colaboradores']->map(fn (Colaborador $c) => $c->nombreCompleto())->all());

            return [
                'clave' => $pendiente['nivel'],
                'etiqueta' => $pendiente['etiqueta'],
                'nombres' => $nombres,
                'texto' => $pendiente['bloqueado'] ? self::MENSAJE_PENDIENTE_REGION : sprintf('Pendiente de aprobación de: %s', $this->listaNombres(array_map(fn (string $n) => ['nombre' => $n], $nombres))),
            ];
        }

        if ($rhEstado === self::PASO_PENDIENTE) {
            return [
                'clave' => 'rh',
                'etiqueta' => 'Recursos Humanos',
                'nombres' => [],
                'texto' => $solicitud->estado === EstadoSolicitudInterna::RequiereCorreccion ? 'Esperando corrección del colaborador' : 'Pendiente de autorización de Recursos Humanos',
            ];
        }

        return null;
    }

    /**
     * @param  array{nivel: string, etiqueta: string, origen: string, usuarios: Collection<int, User>, colaboradores: Collection<int, Colaborador>, bloqueado: bool, motivo_bloqueo: string|null}  $paso
     */
    private function nombrePaso(array $paso): string
    {
        $nombres = array_values($paso['colaboradores']->map(fn (Colaborador $c) => ['nombre' => $c->nombreCompleto()])->all());

        return $nombres === [] ? sprintf('el %s', mb_strtolower($paso['etiqueta'])) : $this->listaNombres($nombres);
    }

    /**
     * @param  array<int, array<string, mixed>>  $personas  Cada una con su clave «nombre».
     */
    private function listaNombres(array $personas): string
    {
        $nombres = array_values(array_map(fn (array $p): string => (string) ($p['nombre'] ?? ''), $personas));

        if ($nombres === []) {
            return 'su responsable';
        }

        if (count($nombres) === 1) {
            return $nombres[0];
        }

        $ultimo = array_pop($nombres);

        return sprintf('%s o %s', implode(', ', $nombres), $ultimo);
    }

    private function etiquetaEstadoPaso(string $estado, bool $bloqueado): string
    {
        return match ($estado) {
            self::PASO_APROBADO => 'Aprobado',
            self::PASO_RECHAZADO => 'Rechazado',
            self::PASO_PENDIENTE => $bloqueado ? self::MENSAJE_PENDIENTE_REGION : 'Pendiente',
            self::PASO_ESPERANDO => 'Esperando paso anterior',
            default => 'No aplica',
        };
    }

    /**
     * @param  array{anios: int, meses: int, dias: int}  $antiguedad
     */
    private function textoAntiguedad(array $antiguedad): string
    {
        $partes = [];

        if ($antiguedad['anios'] > 0) {
            $partes[] = sprintf('%d %s', $antiguedad['anios'], $antiguedad['anios'] === 1 ? 'año' : 'años');
        }

        if ($antiguedad['meses'] > 0) {
            $partes[] = sprintf('%d %s', $antiguedad['meses'], $antiguedad['meses'] === 1 ? 'mes' : 'meses');
        }

        if ($antiguedad['dias'] > 0 || $partes === []) {
            $partes[] = sprintf('%d %s', $antiguedad['dias'], $antiguedad['dias'] === 1 ? 'día' : 'días');
        }

        return implode(' ', $partes);
    }

    /**
     * @param  Collection<int, User>  $usuarios
     * @return Collection<int, Colaborador>
     */
    private function colaboradoresDe(Collection $usuarios): Collection
    {
        return $usuarios->map(fn (User $u) => $u->colaborador)->filter(fn ($c) => $c instanceof Colaborador)->values();
    }

    /**
     * @return array{id: int, nombre: string, puesto: string|null}
     */
    private function personaCorta(Colaborador $colaborador): array
    {
        return ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'puesto' => $colaborador->puesto?->nombre];
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  Collection<int, User>  $usuarios
     * @return array{origen: string, origen_etiqueta: string, nivel: string, etiqueta_paso: string, colaboradores: Collection<int, Colaborador>, usuarios: Collection<int, User>, bloqueado: bool, mensaje: string|null, regla_id: int|null}
     */
    private function resultado(string $origen, string $origenEtiqueta, string $nivel, string $etiquetaPaso, Collection $colaboradores, Collection $usuarios, bool $bloqueado = false, ?string $mensaje = null, ?int $reglaId = null): array
    {
        return [
            'origen' => $origen,
            'origen_etiqueta' => $origenEtiqueta,
            'nivel' => $nivel,
            'etiqueta_paso' => $etiquetaPaso,
            'colaboradores' => $colaboradores->values(),
            'usuarios' => $usuarios->values(),
            'bloqueado' => $bloqueado,
            'mensaje' => $mensaje,
            'regla_id' => $reglaId,
        ];
    }
}
