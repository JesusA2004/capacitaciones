<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\GrupoPuestoIndicador;
use App\Enums\NivelPuestoEntrevista;
use App\Enums\RutaIntervencionCandidato;
use App\Enums\TipoReapertura;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Puesto;
use App\Models\Reapertura;
use App\Models\User;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use App\Services\Permisos\PermisoAccionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quién aprueba la entrevista de un candidato, por su puesto objetivo (regla de la
 * dueña del negocio; web, API y agenda pasan por aquí):
 *
 *   - Gestor → Gerente de Sucursal; sin gerente vigente, los regionales.
 *   - Subgerente o Gerente de Sucursal → Gerente Regional, Subgerente Regional o
 *     Dirección Comercial. Un gerente no aprueba a su propio reemplazo.
 *   - Puesto regional → Dirección Comercial.
 *   - Fuera de la línea → sin regla: permiso del paso + alcance, como siempre.
 *
 * RH conserva el resto del recorrido, no esta aprobación. Si no hay ninguna persona
 * vigente, la atiende RH con autorización final para que el proceso no se detenga.
 * Psicométricas y socioeconómico no pasan por aquí.
 */
class AprobadorEntrevistaService
{
    public const MENSAJE_NO_CORRESPONDE = 'La aprobación de esta entrevista no te corresponde.';

    public const PERMISO_RESPALDO = OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH;

    public function __construct(
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly AutoridadJerarquicaService $autoridad,
        private readonly PermisoAccionService $permisos,
    ) {}

    public function nivel(Candidato $candidato): NivelPuestoEntrevista
    {
        $puestoId = $candidato->vacante->puesto_id ?? $candidato->puesto_objetivo_id;
        $puesto = $puestoId === null ? null : Puesto::withTrashed()->where('id', $puestoId)->first();

        return $puesto === null ? NivelPuestoEntrevista::Otro : $this->nivelDePuesto($puesto);
    }

    /**
     * Personas que hoy pueden aprobar. Vacío en nivel Otro (no hay regla) o si no hay
     * nadie vigente.
     *
     * @return Collection<int, User>
     */
    public function aprobadores(Candidato $candidato): Collection
    {
        $candidato->loadMissing(['vacante', 'gerenteInvolucrado.colaborador']);
        $sucursalId = $candidato->vacante->sucursal_id ?? $candidato->sucursal_id;

        $personas = match ($this->nivel($candidato)) {
            NivelPuestoEntrevista::Gestor => $this->deGestor($candidato, $sucursalId),
            NivelPuestoEntrevista::Gerencial => $this->sinOcupantesDeLaGerencia($this->jerarquia->regionalesDe($sucursalId), $sucursalId)->concat($this->direccionComercial()),
            NivelPuestoEntrevista::Regional => $this->direccionComercial(),
            NivelPuestoEntrevista::Otro => collect(),
        };

        return collect($personas)
            ->concat($this->quienReabrio($candidato))
            ->filter(fn (User $usuario): bool => $this->permisos->puede($usuario, CandidatoWorkflowService::PERMISO_ENTREVISTA))
            ->unique('id')
            ->values();
    }

    public function puedeAprobar(User $usuario, Candidato $candidato): bool
    {
        if ($usuario->hasRole('super_admin')) {
            return true;
        }

        if (! $this->permisos->puede($usuario, CandidatoWorkflowService::PERMISO_ENTREVISTA)) {
            return false;
        }

        if ($this->nivel($candidato) === NivelPuestoEntrevista::Otro) {
            return true;
        }

        $aprobadores = $this->aprobadores($candidato);

        return $aprobadores->isEmpty()
            ? $this->permisos->puede($usuario, self::PERMISO_RESPALDO)
            : $aprobadores->contains('id', $usuario->id);
    }

    /**
     * Cuentas de Dirección Comercial: por puesto en el organigrama o por rol.
     *
     * @return Collection<int, User>
     */
    public function direccionComercial(): Collection
    {
        return $this->jerarquia->direccionComercialDe()
            ->concat(User::query()->role('director_comercial')->whereNull('acceso_bloqueado_en')->get())
            ->unique('id')
            ->values();
    }

    /**
     * Dirección Comercial revisa solo Subgerentes y Gerentes; con otro rol que ya
     * da una vista más amplia, conserva esa vista.
     */
    public function veSoloSuNivel(User $usuario): bool
    {
        return $usuario->hasRole('director_comercial')
            && ! $usuario->hasAnyRole(['super_admin', 'rh_admin', 'rh_auxiliar', 'direccion', 'sistemas', 'auditor']);
    }

    public function esVisiblePara(User $usuario, Candidato $candidato): bool
    {
        return ! $this->veSoloSuNivel($usuario)
            || $this->nivel($candidato)->loRevisaDireccionComercial()
            || $this->tieneIntervencionDeDireccion($candidato);
    }

    /**
     * Mismo criterio que esVisiblePara() sobre una consulta de candidatos.
     *
     * @param  Builder<Candidato>  $query
     * @return Builder<Candidato>
     */
    public function limitarCandidatos(Builder $query, User $usuario): Builder
    {
        if (! $this->veSoloSuNivel($usuario)) {
            return $query;
        }

        $puestos = $this->puestosDeSuNivel();

        return $query->where(function (Builder $q) use ($puestos): void {
            $q->whereHas('vacante', fn (Builder $v) => $v->whereIn('puesto_id', $puestos))
                ->orWhere(fn (Builder $sinPuestoEnVacante) => $sinPuestoEnVacante->whereDoesntHave('vacante')->whereIn('puesto_objetivo_id', $puestos))
                ->orWhereHas('intervenciones', fn (Builder $i) => $i->where('ruta', RutaIntervencionCandidato::DireccionComercial->value));
        });
    }

    /**
     * @param  Builder<AgendaEvento>  $query
     * @return Builder<AgendaEvento>
     */
    public function limitarAgenda(Builder $query, User $usuario): Builder
    {
        if (! $this->veSoloSuNivel($usuario)) {
            return $query;
        }

        return $query->whereIn('candidato_id', $this->limitarCandidatos(Candidato::query(), $usuario)->select('id'));
    }

    private function tieneIntervencionDeDireccion(Candidato $candidato): bool
    {
        return $candidato->intervenciones()->where('ruta', RutaIntervencionCandidato::DireccionComercial->value)->exists();
    }

    /**
     * Quien reabrió la entrevista (un puesto superior) también puede volver a decidirla.
     *
     * @return Collection<int, User>
     */
    private function quienReabrio(Candidato $candidato): Collection
    {
        if ($candidato->estado !== EstadoCandidato::EntrevistaPendiente) {
            return collect();
        }

        $reapertura = Reapertura::query()->where('tipo', TipoReapertura::EntrevistaCandidato->value)
            ->where('reabrible_type', $candidato->getMorphClass())
            ->where('reabrible_id', $candidato->id)
            ->latest('id')
            ->first();

        return collect([$reapertura?->reabiertoPor])->filter()->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function deGestor(Candidato $candidato, ?int $sucursalId): Collection
    {
        $gerencia = $this->jerarquia->gerenciaSucursalDe($sucursalId);
        $involucrado = $candidato->gerenteInvolucrado;

        // El gerente asignado a mano vale solo si es gerente de sucursal, por puesto o por rol.
        if ($involucrado !== null && ($this->esGerenteDeSucursal($involucrado) || $involucrado->hasAnyRole(['gerente_sucursal', 'gerente']))) {
            $gerencia = $gerencia->push($involucrado);
        }

        return $gerencia->isNotEmpty() ? $gerencia : $this->jerarquia->regionalesDe($sucursalId);
    }

    private function esGerenteDeSucursal(User $usuario): bool
    {
        $puesto = $usuario->colaborador?->puesto;

        return $puesto !== null && $this->jerarquia->esPuestoDeGerenciaSucursal($puesto->id);
    }

    /**
     * Un gerente que además cubre una plaza regional no aprueba el reemplazo de
     * su propia sucursal.
     *
     * @param  Collection<int, User>  $usuarios
     * @return Collection<int, User>
     */
    private function sinOcupantesDeLaGerencia(Collection $usuarios, ?int $sucursalId): Collection
    {
        return $usuarios->reject(fn (User $usuario): bool => $this->esGerenteDeSucursal($usuario)
            && $usuario->colaborador !== null
            && $usuario->colaborador->sucursal_principal_id === $sucursalId)->values();
    }

    /**
     * @return list<int>
     */
    private function puestosDeSuNivel(): array
    {
        return array_values(Puesto::withTrashed()->get()
            ->filter(fn (Puesto $puesto): bool => $this->nivelDePuesto($puesto)->loRevisaDireccionComercial())
            ->map(fn (Puesto $puesto): int => $puesto->id)
            ->all());
    }

    private function nivelDePuesto(Puesto $puesto): NivelPuestoEntrevista
    {
        if ($puesto->grupo_indicador === GrupoPuestoIndicador::Gestores) {
            return NivelPuestoEntrevista::Gestor;
        }

        $rango = $this->autoridad->rangoDeNombreDePuesto($puesto->nombre);

        return $rango === null
            ? NivelPuestoEntrevista::Otro
            : NivelPuestoEntrevista::from((string) config("organigrama.linea_comercial.{$rango}.nivel"));
    }
}
