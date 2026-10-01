<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * "Nunca duplicar a la persona": una sola regla para el alta manual, la
 * contratación de un candidato, el registro por QR y la búsqueda de
 * reingreso. CURP, RFC y NSS identifican a una persona aunque esté dada de
 * baja (withTrashed): un reingreso reactiva su expediente, nunca crea otro.
 */
class IdentidadColaboradorService
{
    private const CAMPOS = ['curp', 'rfc', 'nss'];

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function validarNoDuplicado(array $datos, ?int $excluirColaboradorId = null): void
    {
        foreach (self::CAMPOS as $campo) {
            if (empty($datos[$campo])) {
                continue;
            }

            $existente = Colaborador::withTrashed()
                ->where($campo, strtoupper((string) $datos[$campo]))
                ->when($excluirColaboradorId !== null, fn ($q) => $q->where('id', '!=', $excluirColaboradorId))
                ->first();

            if ($existente !== null) {
                throw ValidationException::withMessages([
                    $campo => sprintf('Ya existe un colaborador con ese %s (%s, #%s). Si es un reingreso, reactiva su expediente desde Reingresos en lugar de crear uno nuevo.', strtoupper($campo), $existente->nombreCompleto(), $existente->numero_empleado ?? $existente->id),
                ]);
            }
        }
    }

    /**
     * Búsqueda de una persona (incluidas las dadas de baja) por número de
     * empleado, CURP, RFC, NSS o nombre.
     *
     * @return Collection<int, Colaborador>
     */
    public function buscar(string $termino, int $limite = 20): Collection
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 3) {
            return new Collection;
        }

        $mayusculas = strtoupper($termino);

        return Colaborador::withTrashed()
            ->where(function ($q) use ($termino, $mayusculas): void {
                $q->where('numero_empleado', $termino)
                    ->orWhere('curp', $mayusculas)
                    ->orWhere('rfc', $mayusculas)
                    ->orWhere('nss', $termino)
                    ->orWhere('name', 'like', '%'.$termino.'%')
                    ->orWhere('apellidos', 'like', '%'.$termino.'%');
            })
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->limit($limite)
            ->get();
    }
}
