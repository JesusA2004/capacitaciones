<?php

namespace App\Services\Administracion;

/**
 * Resultado del inventario de LimpiezaRegistrosPruebaService: qué filas se
 * borran, cuáles se desvinculan (NULL), qué vacantes se liberan, qué archivos
 * y carpetas del NAS se retiran y qué impide la limpieza. Solo datos: no
 * ejecuta nada.
 */
final class PlanLimpiezaPrueba
{
    /** @var array<string, array<string, int|string>> tabla → [clave → id] */
    public array $ids = [];

    /** @var array<string, list<array<string, list<int|string>>>> tabla → criterios (columna → valores, unidos con AND) para tablas sin `id` */
    public array $criterios = [];

    /** @var list<array{tabla: string, columnas: list<string>, donde: array<string, list<int|string>>}> */
    public array $anular = [];

    /** @var list<array{tabla: string, columna: string, valores: list<int|string>}> */
    public array $bloqueos = [];

    /** @var list<int> */
    public array $vacantes = [];

    /** @var list<array{disco: string, ruta: string, origen: string, existe: bool, tamano: int|null}> */
    public array $archivos = [];

    /** @var list<array{disco: string, carpeta: string, origen: string, existe: bool}> */
    public array $carpetas = [];

    /** @var list<string> Lo que impide ejecutar la limpieza (aborta). */
    public array $errores = [];

    /** @var list<string> Lo que conviene revisar pero no impide ejecutar. */
    public array $advertencias = [];

    /** @var list<array{persona: string, dato: string, esperado: string, encontrado: string, ok: bool}> */
    public array $validaciones = [];

    /** @var list<array{0: int, 1: int}> Pares (sucursal, puesto) cuya plantilla cambia al borrar a las personas. */
    public array $paresPlantilla = [];

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string> Los que no estaban ya.
     */
    public function agregarIds(string $tabla, array $ids): array
    {
        $nuevos = [];

        foreach ($ids as $id) {
            $clave = (string) $id;

            if (! isset($this->ids[$tabla][$clave])) {
                $this->ids[$tabla][$clave] = $id;
                $nuevos[] = $id;
            }
        }

        return $nuevos;
    }

    /** @param  array<string, list<int|string>>  $criterio */
    public function agregarCriterio(string $tabla, array $criterio): void
    {
        foreach ($this->criterios[$tabla] ?? [] as $existente) {
            if ($existente == $criterio) {
                return;
            }
        }

        $this->criterios[$tabla][] = $criterio;
    }

    public function seBorra(string $tabla, int|string $id): bool
    {
        return isset($this->ids[$tabla][(string) $id]);
    }

    /** @return list<int|string> */
    public function idsDe(string $tabla): array
    {
        return array_values($this->ids[$tabla] ?? []);
    }

    /** @return list<string> */
    public function tablasABorrar(): array
    {
        return array_values(array_unique([...array_keys($this->ids), ...array_keys($this->criterios)]));
    }

    public function error(string $mensaje): void
    {
        if (! in_array($mensaje, $this->errores, true)) {
            $this->errores[] = $mensaje;
        }
    }

    public function advertencia(string $mensaje): void
    {
        if (! in_array($mensaje, $this->advertencias, true)) {
            $this->advertencias[] = $mensaje;
        }
    }

    public function puedeEjecutarse(): bool
    {
        return $this->errores === [];
    }
}
