<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Selector de testigos para los instrumentos legales de la baja: el acta de
 * negativa a firmar/recibir y la firma física de los documentos laborales
 * (renuncia, aviso, finiquito, responsiva). Mismo estándar que las actas
 * circunstanciadas (ActaService): un testigo es un colaborador ACTIVO dentro
 * del alcance de quien opera (AlcanceOrganizacionalService) —NUNCA texto
 * libre de una persona que ya existe en PEOPLE—; su nombre, puesto y
 * sucursal se CONGELAN desde el expediente (snapshot inmutable), no desde lo
 * que mande el cliente. Solo un testigo EXTERNO (ajeno a la empresa) puede
 * capturarse a mano, y queda marcado como tal para que sea auditable.
 */
class TestigoDocumentalService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Candidatos a testigo: colaboradores ACTIVOS dentro del alcance del
     * actor, los del centro de trabajo del afectado primero, excluyendo al
     * propio afectado y a quienes ya se eligieron. Sin texto, solo la
     * sucursal del afectado (no se carga a toda la empresa); al escribir se
     * busca por nombre, número de empleado, puesto o sucursal.
     *
     * @param  list<int>  $excluir
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}>
     */
    public function buscar(User $actor, ?Colaborador $afectado, string $termino, array $excluir = []): array
    {
        $palabras = array_slice(preg_split('/\s+/u', Str::lower(Str::ascii(trim($termino))), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $sucursalAfectado = $afectado?->sucursal_principal_id;
        $excluirIds = array_values(array_unique(array_filter(
            [...array_map('intval', $excluir), $afectado?->id],
            fn (?int $id): bool => $id !== null,
        )));

        $consulta = $this->alcance
            ->limitarColaboradoresPorAlcance(Colaborador::query()->where('estatus', EstadoUsuario::Activo->value), $actor)
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->when($excluirIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excluirIds));

        if ($palabras === [] && $sucursalAfectado !== null) {
            $consulta->where('sucursal_principal_id', $sucursalAfectado);
        }

        $candidatos = $consulta->orderBy('name')->orderBy('apellidos')->limit(3000)->get();

        return array_values($candidatos
            ->filter(function (Colaborador $c) use ($palabras): bool {
                $texto = Str::lower(Str::ascii(sprintf('%s %s %s %s %s', $c->name, $c->apellidos, $c->numero_empleado, $c->puesto?->nombre, $c->sucursalPrincipal?->nombre)));

                return collect($palabras)->every(fn (string $p): bool => str_contains($texto, $p));
            })
            ->sortBy(fn (Colaborador $c): int => $c->sucursal_principal_id === $sucursalAfectado ? 0 : 1)
            ->take(30)
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'puesto' => $c->puesto?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
            ])
            ->values()
            ->all());
    }

    /**
     * Resuelve y CONGELA los testigos capturados en un snapshot inmutable
     * —nombre, puesto y sucursal reales de PEOPLE, nunca lo que mande el
     * cliente—. Valida: el afectado jamás es su propio testigo; sin
     * duplicados; un colaborador elegido debe estar ACTIVO y dentro del
     * alcance del actor (si no, se rechaza, igual que el buscador ya no lo
     * mostraría). Un testigo EXTERNO se captura a mano (nombre + cargo) y se
     * marca como tal. Las entradas totalmente vacías se ignoran (la captura
     * puede completarse en dos pasos): quien exige un mínimo de testigos lo
     * valida sobre el resultado.
     *
     * @param  list<array{colaborador_id?: int|string|null, nombre?: string|null, cargo?: string|null, puesto?: string|null, externo?: bool|int|string|null}>  $testigos
     * @return list<array{colaborador_id: int|null, nombre: string, puesto: string, sucursal: string|null, externo: bool}>
     */
    public function resolver(User $actor, ?Colaborador $afectado, array $testigos, int $maximo = 2, string $campo = 'testigos'): array
    {
        $resueltos = [];
        $vistos = [];

        foreach (array_slice($testigos, 0, max(1, $maximo)) as $i => $testigo) {
            $colaboradorId = is_numeric($testigo['colaborador_id'] ?? null) ? (int) $testigo['colaborador_id'] : null;
            $nombreManual = trim((string) ($testigo['nombre'] ?? ''));
            $cargoManual = trim((string) ($testigo['cargo'] ?? $testigo['puesto'] ?? ''));
            $clave = sprintf('%s.%d', $campo, $i);

            // Entrada vacía: se ignora (se podrá capturar después).
            if ($colaboradorId === null && $nombreManual === '' && $cargoManual === '') {
                continue;
            }

            // Sin colaborador elegido: solo se admite como testigo EXTERNO
            // (ajeno a la empresa), capturado a mano con nombre y cargo.
            if ($colaboradorId === null) {
                if ($nombreManual === '' || $cargoManual === '') {
                    throw ValidationException::withMessages([$clave => 'Elige al testigo del buscador, o captura el nombre y el cargo del testigo externo.']);
                }

                $resueltos[] = ['colaborador_id' => null, 'nombre' => $nombreManual, 'puesto' => $cargoManual, 'sucursal' => null, 'externo' => true];

                continue;
            }

            if ($afectado !== null && $colaboradorId === $afectado->id) {
                throw ValidationException::withMessages([$clave => 'La persona de la baja no puede ser su propio testigo.']);
            }

            if (in_array($colaboradorId, $vistos, true)) {
                throw ValidationException::withMessages([$clave => 'Ese testigo ya está en la lista.']);
            }

            $colaborador = Colaborador::query()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])->where('id', $colaboradorId)->first();

            if ($colaborador === null || $colaborador->estatus !== EstadoUsuario::Activo || ! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
                throw ValidationException::withMessages([$clave => 'Ese testigo está fuera de tu alcance o no está activo: elígelo del buscador.']);
            }

            $vistos[] = $colaboradorId;
            $resueltos[] = [
                'colaborador_id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'puesto' => $colaborador->puesto->nombre ?? '',
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'externo' => false,
            ];
        }

        return $resueltos;
    }
}
