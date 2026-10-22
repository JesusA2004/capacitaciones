<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Criterio ÚNICO para buscar colaboradores en todo el sistema (directorio,
 * expedientes, buscadores de «asignar a», testigos, avisos, bajas, nómina…).
 *
 * Cada palabra del término debe aparecer (AND) en alguno de estos campos
 * (OR): nombre, apellidos, número de empleado, puesto, sucursal principal
 * (nombre o clave) o departamento. Los correos NO entran: buscar por ellos
 * dejaría inferir datos de contacto que no todos pueden ver (directorio);
 * quien sí puede (expedientes) los agrega en su propia consulta. Así
 * «Cuernavaca» trae a todos los de esa sucursal, «juan cuerna» a los Juan
 * de Cuernavaca y «perez 1023» a Pérez con número 1023.
 *
 * Sin acentos ni mayúsculas: «jose» encuentra a «José». En MariaDB lo
 * resuelve la colación `utf8mb4_unicode_ci`; en SQLite (pruebas) se
 * normaliza la columna con REPLACE.
 *
 * Solo AGREGA condiciones: nunca quita ni ensancha el alcance que el
 * llamador ya aplicó (AlcanceOrganizacionalService / limitarPorModulo).
 * Un término vacío no filtra nada — el llamador decide la lista por
 * defecto (y su límite).
 */
final class BuscadorColaboradoresService
{
    /** Tamaño sugerido de la lista por defecto de un buscador (sin texto). */
    public const LIMITE_LISTA = 50;

    /** Más palabras no agregan precisión y sí costo. */
    private const MAX_PALABRAS = 6;

    /**
     * SQLite no tiene colación sin acentos: LIKE sobre la columna con
     * á é í ó ú ü ñ (y sus mayúsculas) reemplazadas y en minúsculas.
     */
    private const SQLITE_PREFIJO = 'LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(';

    private const SQLITE_SUFIJO = ", 'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'), 'ü', 'u'), 'ñ', 'n'), 'Á', 'a'), 'É', 'e'), 'Í', 'i'), 'Ó', 'o'), 'Ú', 'u'), 'Ü', 'u'), 'Ñ', 'n')) LIKE ?";

    /**
     * Palabras normalizadas (minúsculas, sin acentos) del término. Los
     * comodines de LIKE (`%`, `_`) separan palabras en vez de usarse como
     * comodín: «juan_perez» busca «juan» y «perez».
     *
     * @return list<string>
     */
    public function palabras(?string $termino): array
    {
        $normalizado = Str::lower(Str::ascii(trim((string) $termino)));
        $palabras = preg_split('/[\s%_,;]+/u', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_unique($palabras), 0, self::MAX_PALABRAS);
    }

    /**
     * Aplica la búsqueda a una consulta de Colaborador.
     *
     * @param  Builder<Colaborador>  $query
     * @return Builder<Colaborador>
     */
    public function aplicar(Builder $query, ?string $termino): Builder
    {
        $modelo = $query->getModel();

        foreach ($this->palabras($termino) as $palabra) {
            $patron = sprintf('%%%s%%', $palabra);

            $query->where(function (Builder $sub) use ($modelo, $patron): void {
                foreach (['colaboradores.name', 'colaboradores.apellidos', 'colaboradores.numero_empleado'] as $columna) {
                    $sub->orWhereRaw($this->como($columna), [$patron]);
                }

                $sub->orWhereIn($modelo->qualifyColumn('puesto_id'), Puesto::query()->select('id')
                    ->whereRaw($this->como('puestos.nombre'), [$patron]));

                $sub->orWhereIn($modelo->qualifyColumn('sucursal_principal_id'), Sucursal::query()->select('id')
                    ->where(fn (Builder $s) => $s
                        ->whereRaw($this->como('sucursales.nombre'), [$patron])
                        ->orWhereRaw($this->como('sucursales.clave'), [$patron])));

                $sub->orWhereIn($modelo->qualifyColumn('departamento_id'), Departamento::query()->select('id')
                    ->whereRaw($this->como('departamentos.nombre'), [$patron]));
            });
        }

        return $query;
    }

    /**
     * Misma búsqueda sobre otra tabla que apunta a un colaborador (recibos
     * de nómina, documentos, solicitudes…): filtra la columna con el id del
     * colaborador contra una subconsulta de Colaborador.
     *
     * @template TModelo of Model
     *
     * @param  Builder<TModelo>  $query
     * @return Builder<TModelo>
     */
    public function aplicarPorColumna(Builder $query, ?string $termino, string $columnaColaborador = 'colaborador_id', string $boolean = 'and'): Builder
    {
        if ($this->palabras($termino) === []) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn($columnaColaborador), $this->idsCoincidentes($termino), $boolean);
    }

    /**
     * Igual que aplicarPorColumna, para tablas que guardan la CUENTA (users)
     * en vez del colaborador: busca las cuentas cuyo colaborador coincide.
     *
     * @template TModelo of Model
     *
     * @param  Builder<TModelo>  $query
     * @return Builder<TModelo>
     */
    public function aplicarPorUsuario(Builder $query, ?string $termino, string $columnaUsuario = 'user_id', string $boolean = 'and'): Builder
    {
        if ($this->palabras($termino) === []) {
            return $query;
        }

        $cuentas = User::withTrashed()->select('id')->whereIn('colaborador_id', $this->idsCoincidentes($termino));

        return $query->whereIn($query->getModel()->qualifyColumn($columnaUsuario), $cuentas, $boolean);
    }

    /**
     * Subconsulta con los ids de colaborador (incluye bajas) que coinciden.
     *
     * @return Builder<Colaborador>
     */
    private function idsCoincidentes(?string $termino): Builder
    {
        return $this->aplicar(Colaborador::withTrashed()->select('id'), $termino);
    }

    /**
     * Texto secundario que explica por qué salió un resultado: número de
     * empleado · puesto · sucursal (lo que se muestra bajo el nombre).
     */
    public function descripcion(Colaborador $colaborador): string
    {
        return collect([
            $colaborador->numero_empleado,
            $colaborador->puesto?->nombre,
            $colaborador->sucursalPrincipal?->nombre,
        ])->filter(fn (?string $v): bool => $v !== null && $v !== '')->implode(' · ');
    }

    /**
     * `<columna> LIKE ?` sin acentos ni mayúsculas. En MariaDB/MySQL la
     * colación `_unicode_ci` ya lo hace (y así se aprovechan los índices);
     * en SQLite (pruebas) la columna se normaliza con REPLACE + LOWER. El
     * término siempre va como binding, nunca en el SQL; la columna es
     * siempre una constante de esta clase (tabla.columna sin palabras
     * reservadas), así el SQL completo es literal.
     *
     * @param  literal-string  $columna
     * @return literal-string
     */
    private function como(string $columna): string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return $columna.' LIKE ?';
        }

        return self::SQLITE_PREFIJO.$columna.self::SQLITE_SUFIJO;
    }
}
