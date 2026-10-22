<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Enums\ModoDestinatariosOnboarding;
use App\Models\Colaborador;
use App\Models\OnboardingProceso;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Asignar a» de todo Onboarding (presentación de la inducción, material de
 * explicación y descriptivo de puesto): resuelve A QUIÉN se le asigna —
 * todos, una persona, una lista de personas o una o varias sucursales —
 * siempre dentro del alcance del módulo «onboarding» de quien asigna
 * (AlcanceOrganizacionalService::limitarPorModulo), nunca a sí mismo y solo
 * a colaboradores activos o en incorporación. Un solo criterio para las tres
 * asignaciones (AsignacionInduccionService y AcuseDescriptivoPuestoService).
 */
class DestinatariosOnboardingService
{
    public const MODULO = SesionInduccionService::MODULO;

    /** Tope de una sola asignación (protege la petición web). */
    public const MAXIMO = 2000;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Colaboradores elegibles (activos o en incorporación) dentro del
     * alcance de quien asigna.
     *
     * @return Builder<Colaborador>
     */
    public function consultaElegibles(User $actor): Builder
    {
        $consulta = Colaborador::query()
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->where(fn (Builder $q) => $q->whereNull('estado_alta')->orWhere('estado_alta', '!=', EstadoAltaColaborador::Baja->value))
            ->when($actor->colaborador_id !== null, fn (Builder $q) => $q->where('id', '!=', $actor->colaborador_id));

        return $this->alcance->limitarPorModulo($consulta, $actor, self::MODULO, 'id');
    }

    /**
     * Buscador del diálogo: sin mínimo de letras (lista completa al
     * enfocar), filtra mientras se escribe por nombre o número de empleado.
     * Primero quienes están en su proceso de ingreso.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, en_ingreso: bool, etapa: string}>
     */
    public function buscar(User $actor, string $termino): array
    {
        $this->exigirPermiso($actor);

        // Buscador único: nombre, número, puesto, sucursal o departamento.
        $candidatos = $this->consultaElegibles($actor)
            ->select(['id', 'name', 'apellidos', 'numero_empleado', 'puesto_id', 'sucursal_principal_id', 'estado_alta'])
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->buscar($termino)
            ->orderBy('name')
            ->orderBy('apellidos')
            ->limit(50)
            ->get();

        $enIngreso = $this->idsEnIngreso($candidatos->map(fn (Colaborador $c): int => $c->id)->values()->all());

        return array_values($candidatos
            ->sortBy(fn (Colaborador $c): string => sprintf('%d %s', in_array($c->id, $enIngreso, true) ? 0 : 1, Str::lower(Str::ascii($c->nombreCompleto()))))
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'puesto' => $c->puesto?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'en_ingreso' => in_array($c->id, $enIngreso, true),
                'etapa' => in_array($c->id, $enIngreso, true) ? 'En proceso de ingreso' : ($c->estado_alta?->etiqueta() ?? 'Activo'),
            ])
            ->values()
            ->all());
    }

    /**
     * Sucursales con al menos un colaborador elegible dentro del alcance,
     * con cuántos tiene cada una.
     *
     * @return list<array{id: int, nombre: string, total: int}>
     */
    public function sucursales(User $actor): array
    {
        $this->exigirPermiso($actor);

        $conteos = $this->consultaElegibles($actor)
            ->whereNotNull('sucursal_principal_id')
            ->selectRaw('sucursal_principal_id, count(*) as total')
            ->groupBy('sucursal_principal_id')
            ->pluck('total', 'sucursal_principal_id');

        return array_values(Sucursal::query()
            ->whereIn('id', $conteos->keys()->all())
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => (string) $s->nombre, 'total' => (int) $conteos->get($s->id, 0)])
            ->all());
    }

    /**
     * Resuelve los destinatarios. Una persona o lista fuera de alcance, dada
     * de baja o inexistente se rechaza con su nombre (nunca se ignora en
     * silencio).
     *
     * @param  array<int, int|string>  $colaboradorIds
     * @param  array<int, int|string>  $sucursalIds
     * @return Collection<int, Colaborador>
     *
     * @throws AuthorizationException sin permiso
     * @throws ValidationException selección vacía, fuera de alcance o demasiado grande
     */
    public function resolver(User $actor, ModoDestinatariosOnboarding $modo, array $colaboradorIds = [], array $sucursalIds = []): Collection
    {
        $this->exigirPermiso($actor);

        $consulta = $this->consultaElegibles($actor)->with(['user', 'puesto:id,nombre', 'sucursalPrincipal:id,nombre']);

        if ($modo === ModoDestinatariosOnboarding::Personas) {
            $ids = $this->enteros($colaboradorIds);

            if ($ids === []) {
                throw ValidationException::withMessages(['colaborador_ids' => 'Elige al menos a una persona.']);
            }

            $encontrados = (clone $consulta)->whereIn('id', $ids)->get();
            $rechazados = array_values(array_diff($ids, $encontrados->modelKeys()));

            if ($rechazados !== []) {
                $nombres = Colaborador::query()->withTrashed()->whereIn('id', $rechazados)->get()
                    ->map(fn (Colaborador $c): string => $c->nombreCompleto())->implode(', ');

                throw ValidationException::withMessages([
                    'colaborador_ids' => sprintf('No puedes asignarle a: %s (fuera de tu alcance, dado de baja o inexistente).', $nombres !== '' ? $nombres : 'colaborador no encontrado'),
                ]);
            }

            return $this->limitar($encontrados);
        }

        if ($modo === ModoDestinatariosOnboarding::Sucursales) {
            $ids = $this->enteros($sucursalIds);

            if ($ids === []) {
                throw ValidationException::withMessages(['sucursal_ids' => 'Elige al menos una sucursal.']);
            }

            return $this->limitar($consulta->whereIn('sucursal_principal_id', $ids)->orderBy('name')->get());
        }

        return $this->limitar($consulta->orderBy('name')->get());
    }

    /**
     * Texto del resumen de confirmación: «37 personas de Sucursal Centro».
     *
     * @param  Collection<int, Colaborador>  $destinatarios
     * @param  array<int, int|string>  $sucursalIds
     */
    public function descripcion(ModoDestinatariosOnboarding $modo, Collection $destinatarios, array $sucursalIds = []): string
    {
        $total = $destinatarios->count();
        $personas = $total === 1 ? '1 persona' : sprintf('%d personas', $total);

        return match ($modo) {
            ModoDestinatariosOnboarding::Todos => sprintf('%s (todo tu alcance)', $personas),
            ModoDestinatariosOnboarding::Personas => $total === 1 ? (string) $destinatarios->first()?->nombreCompleto() : $personas,
            ModoDestinatariosOnboarding::Sucursales => sprintf('%s de %s', $personas, Sucursal::query()->whereIn('id', $this->enteros($sucursalIds))->orderBy('nombre')->pluck('nombre')->implode(', ')),
        };
    }

    /**
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    public function idsEnIngreso(array $ids): array
    {
        return array_values(array_map('intval', OnboardingProceso::query()
            ->deIngreso()
            ->whereIn('colaborador_id', $ids)
            ->whereNotNull('colaborador_abierto_id')
            ->pluck('colaborador_id')
            ->all()));
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return Collection<int, Colaborador>
     */
    private function limitar(Collection $colaboradores): Collection
    {
        if ($colaboradores->count() > self::MAXIMO) {
            throw ValidationException::withMessages(['modo' => sprintf('Son %d personas: el máximo por asignación es %d. Asigna por sucursal.', $colaboradores->count(), self::MAXIMO)]);
        }

        return $colaboradores;
    }

    /**
     * @param  array<int, int|string>  $valores
     * @return list<int>
     */
    private function enteros(array $valores): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($v): int => (int) $v, $valores), fn (int $v): bool => $v > 0)));
    }

    /**
     * @throws AuthorizationException
     */
    private function exigirPermiso(User $actor): void
    {
        if (! $actor->can(OnboardingService::PERMISO_GESTIONAR)) {
            throw new AuthorizationException('No tienes permiso para asignar en Onboarding.');
        }
    }
}
