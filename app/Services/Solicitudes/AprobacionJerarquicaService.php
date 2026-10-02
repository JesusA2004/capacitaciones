<?php

namespace App\Services\Solicitudes;

use App\Models\Colaborador;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Vistos buenos jerárquicos sobre solicitudes internas, en orden:
 *
 *   1. GERENTE de la sucursal del colaborador (o, si la sucursal no tiene
 *      gerente capturado, su jefe directo),
 *   2. REGIONAL de la región de su sucursal,
 *   3. RH autoriza (fuera de este service, SolicitudesService::aprobar).
 *
 * Mientras falte algún visto bueno la solicitud se queda como "Recibida";
 * con todos, pasa sola a "Pendiente de autorizar". Se resuelve con el
 * organigrama real de personas (OrganizacionJerarquiaService), nunca por
 * el nombre del rol. Un nivel sin persona que lo ocupe no se exige (queda
 * en el log del ruteo); si no hay ninguno, la solicitud va directo a RH.
 * Qué tipos lo exigen: config/solicitudes.php (`visto_bueno_jefe`).
 */
class AprobacionJerarquicaService
{
    public function __construct(private readonly OrganizacionJerarquiaService $organizacion) {}

    /**
     * Niveles de visto bueno que aplican a ESTA solicitud, en orden, con
     * las cuentas que pueden darlo.
     *
     * @return array<string, Collection<int, User>>
     */
    public function niveles(SolicitudInterna $solicitud): array
    {
        $tipos = (array) config('solicitudes.visto_bueno_jefe', []);
        $persona = $solicitud->personaSolicitante();

        if ($persona === null || ! in_array($solicitud->tipo->value, $tipos, true)) {
            return [];
        }

        $gerentes = $this->organizacion->gerenciaSucursalDe($persona->sucursal_principal_id, $persona);

        if ($gerentes->isEmpty()) {
            $jefe = $this->organizacion->usuarioActivoDe($this->organizacion->supervisorDirectoDe($persona));
            $gerentes = $jefe !== null ? collect([$jefe]) : collect();
        }

        $regionales = $this->organizacion->regionalesDe($persona->sucursal_principal_id, $persona)
            ->reject(fn (User $u) => $gerentes->contains('id', $u->id))
            ->values();

        return array_filter([
            SolicitudAprobacion::NIVEL_JEFE_INMEDIATO => $this->sinPersona($gerentes, $persona),
            SolicitudAprobacion::NIVEL_REGIONAL => $this->sinPersona($regionales, $persona),
        ], fn (Collection $c) => $c->isNotEmpty());
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
     * @return list<array{nivel: string, etiqueta: string, estado: string, aprobadores: list<string>, decidio: string|null, comentario: string|null, fecha: string|null}>
     */
    public function resumen(SolicitudInterna $solicitud): array
    {
        $decididos = $this->decisiones($solicitud)->keyBy('nivel');
        $resumen = [];

        foreach ($this->niveles($solicitud) as $nivel => $usuarios) {
            $decision = $decididos->get($nivel);
            $resumen[] = [
                'nivel' => $nivel,
                'etiqueta' => SolicitudAprobacion::etiquetaNivel($nivel),
                'estado' => $decision !== null ? $decision->decision : 'pendiente',
                'aprobadores' => array_values($usuarios->map(fn (User $u) => $u->nombreCompleto())->all()),
                'decidio' => $decision?->usuario?->nombreCompleto(),
                'comentario' => $decision?->comentario,
                'fecha' => $decision?->created_at?->toIso8601String(),
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
            throw ValidationException::withMessages(['solicitud' => 'Este visto bueno no te corresponde (va primero el gerente de la sucursal y después el regional).']);
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

    /**
     * @param  Collection<int, User>  $usuarios
     * @return Collection<int, User>
     */
    private function sinPersona(Collection $usuarios, Colaborador $persona): Collection
    {
        return $usuarios->reject(fn (User $u) => $u->colaborador_id === $persona->id)->values();
    }
}
