<?php

namespace App\Services\Solicitudes;

use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Decisiones (vistos buenos) por nivel sobre solicitudes internas. QUÉ
 * niveles aplican y QUIÉN responde cada uno lo decide el motor central
 * AprobacionOrganizacionalService (responsable de aprobación explícito,
 * gerente de sucursal → regional Q1/Q3, gerencia de sucursal, jefe
 * organizacional, regional, Dirección Comercial según el tipo); RH autoriza
 * al final (SolicitudesService::aprobar).
 *
 * Mientras falte algún visto bueno la solicitud se queda como "Recibida";
 * con todos, pasa sola a "Pendiente de autorizar". Un nivel sin persona que
 * lo ocupe no se exige; un nivel BLOQUEADO (sin región Q1/Q3, regional
 * vacante sin cobertura) detiene el flujo hasta corregir la estructura.
 * Qué tipos lo exigen: config/solicitudes.php (`visto_bueno_jefe`).
 */
class AprobacionJerarquicaService
{
    public function __construct(private readonly AprobacionOrganizacionalService $motor) {}

    /**
     * Niveles de visto bueno que aplican a ESTA solicitud, en orden, con
     * las cuentas que pueden darlo. Los resuelve el motor central
     * (AprobacionOrganizacionalService::pasos): responsable explícito >
     * regla por puesto (gerente de sucursal → regional Q1/Q3) > gerencia de
     * sucursal > jefe organizacional; regional; Dirección Comercial solo en
     * los tipos que lo exigen. Un nivel BLOQUEADO (p. ej. «Pendiente de
     * asignar región de aprobación») aparece sin cuentas: nadie lo puede
     * dar y RH no puede autorizar hasta que se corrija la estructura.
     *
     * @return array<string, Collection<int, User>>
     */
    public function niveles(SolicitudInterna $solicitud): array
    {
        $niveles = [];

        foreach ($this->motor->pasos($solicitud) as $paso) {
            $niveles[$paso['nivel']] = $paso['usuarios'];
        }

        return $niveles;
    }

    /**
     * true si el nivel pendiente está bloqueado por la estructura (sin
     * región Q1/Q3, plaza regional vacante sin cobertura).
     */
    public function pendienteBloqueado(SolicitudInterna $solicitud): ?string
    {
        $nivel = $this->nivelPendiente($solicitud);

        foreach ($this->motor->pasos($solicitud) as $paso) {
            if ($paso['nivel'] === $nivel && $paso['bloqueado']) {
                return $paso['motivo_bloqueo'] ?? AprobacionOrganizacionalService::MENSAJE_SIN_RESPONSABLE;
            }
        }

        return null;
    }

    public function requiereVistoBuenoJefe(SolicitudInterna $solicitud): bool
    {
        return $this->niveles($solicitud) !== [];
    }

    /**
     * Primer nivel que todavía no da su visto bueno (null = completo o no aplica).
     */
    public function nivelPendiente(SolicitudInterna $solicitud): ?string
    {
        $decididos = $this->decisiones($solicitud)->keyBy('nivel');

        foreach (array_keys($this->niveles($solicitud)) as $nivel) {
            if ($decididos->get($nivel)?->decision !== SolicitudAprobacion::DECISION_APROBADO) {
                return $nivel;
            }
        }

        return null;
    }

    /**
     * true cuando ya están TODOS los vistos buenos que aplican (o ninguno aplica).
     */
    public function tieneVistoBuenoJefe(SolicitudInterna $solicitud): bool
    {
        return $this->nivelPendiente($solicitud) === null;
    }

    /**
     * @return Collection<int, SolicitudAprobacion>
     */
    public function decisiones(SolicitudInterna $solicitud): Collection
    {
        return SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->with('usuario')->orderBy('id')->get()->toBase();
    }

    public function decisionJefe(SolicitudInterna $solicitud): ?SolicitudAprobacion
    {
        return $this->decisiones($solicitud)->firstWhere('nivel', SolicitudAprobacion::NIVEL_JEFE_INMEDIATO);
    }

    /**
     * Quién puede dar el visto bueno AHORA: solo las cuentas del nivel pendiente.
     *
     * @return Collection<int, User>
     */
    public function aprobadoresPendientes(SolicitudInterna $solicitud): Collection
    {
        $nivel = $this->nivelPendiente($solicitud);

        return $nivel === null ? collect() : ($this->niveles($solicitud)[$nivel] ?? collect());
    }

    /**
     * true si la cuenta es aprobadora de ALGÚN nivel de esta solicitud (para
     * distinguir "no te corresponde" (403) de "ya decidiste" (422)).
     */
    public function esAprobadorDe(User $usuario, SolicitudInterna $solicitud): bool
    {
        foreach ($this->niveles($solicitud) as $usuarios) {
            if ($usuarios->contains('id', $usuario->id)) {
                return true;
            }
        }

        return false;
    }

    public function puedeDarVistoBueno(User $usuario, SolicitudInterna $solicitud): bool
    {
        return ! $solicitud->estado->esFinal()
            && $this->aprobadoresPendientes($solicitud)->contains('id', $usuario->id);
    }

    /**
     * Resumen para pantallas (web y app): cada nivel con su estado.
     *
     * Claves históricas conservadas (la app instalada las lee); `bloqueado` y
     * `motivo_bloqueo` son aditivas. El flujo completo (pasos + quién puede
     * actuar) está en AprobacionOrganizacionalService::flujo().
     *
     * @return list<array{nivel: string, etiqueta: string, estado: string, aprobadores: list<string>, decidio: string|null, comentario: string|null, fecha: string|null, bloqueado: bool, motivo_bloqueo: string|null}>
     */
    public function resumen(SolicitudInterna $solicitud): array
    {
        $decididos = $this->decisiones($solicitud)->keyBy('nivel');
        $resumen = [];

        foreach ($this->motor->pasos($solicitud) as $paso) {
            $decision = $decididos->get($paso['nivel']);
            $resumen[] = [
                'nivel' => $paso['nivel'],
                'etiqueta' => $paso['etiqueta'],
                'estado' => $decision !== null ? $decision->decision : 'pendiente',
                'aprobadores' => array_values($paso['usuarios']->map(fn (User $u) => $u->nombreCompleto())->all()),
                'decidio' => $decision?->usuario?->nombreCompleto(),
                'comentario' => $decision?->comentario,
                'fecha' => $decision?->created_at?->toIso8601String(),
                'bloqueado' => $decision === null && $paso['bloqueado'],
                'motivo_bloqueo' => $decision === null ? $paso['motivo_bloqueo'] : null,
            ];
        }

        return $resumen;
    }

    /**
     * Registra la decisión del nivel pendiente (una sola por nivel: índice único).
     *
     * @throws ValidationException Si no le toca, ya decidió, o la solicitud ya no está en trámite.
     */
    public function registrarDecisionJefe(SolicitudInterna $solicitud, User $usuario, bool $aprobado, ?string $comentario): SolicitudAprobacion
    {
        if ($solicitud->estado->esFinal() || $solicitud->estado->value === 'aprobada') {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud ya no está en trámite.']);
        }

        $nivel = $this->nivelPendiente($solicitud);

        if ($nivel === null || ! $this->puedeDarVistoBueno($usuario, $solicitud)) {
            throw ValidationException::withMessages(['solicitud' => $this->motor->flujo($solicitud, $usuario)['motivo_no_puede'] ?? 'Este visto bueno todavía no te corresponde.']);
        }

        if (! $aprobado && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => 'Indica el motivo por el que no das el visto bueno.']);
        }

        try {
            return SolicitudAprobacion::query()->create([
                'solicitud_interna_id' => $solicitud->id,
                'nivel' => $nivel,
                'decision' => $aprobado ? SolicitudAprobacion::DECISION_APROBADO : SolicitudAprobacion::DECISION_RECHAZADO,
                'user_id' => $usuario->id,
                'comentario' => $comentario,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['solicitud' => 'Este visto bueno ya fue registrado.']);
        }
    }
}
