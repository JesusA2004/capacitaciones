<?php

namespace App\Services\Capacitacion;

use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Logica del constructor de cursos: creacion/edicion de modulos y lecciones,
 * y su reordenamiento. Centralizado aqui para que los controladores se
 * mantengan delgados y para no duplicar el calculo de "orden" en cada accion.
 */
class CursoBuilderService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearModulo(Curso $curso, array $datos): CursoModulo
    {
        $siguienteOrden = $curso->modulos()->max('orden') + 1;

        return $curso->modulos()->create([...$datos, 'orden' => $siguienteOrden]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarModulo(CursoModulo $modulo, array $datos): CursoModulo
    {
        $modulo->update($datos);

        return $modulo->fresh();
    }

    public function eliminarModulo(CursoModulo $modulo): void
    {
        $modulo->delete();
    }

    public function moverModulo(Curso $curso, CursoModulo $modulo, string $direccion): void
    {
        $modulos = $curso->modulos()->orderBy('orden')->get();
        $this->intercambiarOrdenModulo($modulos, $modulo, $direccion);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearLeccion(CursoModulo $modulo, array $datos): Leccion
    {
        $siguienteOrden = $modulo->lecciones()->max('orden') + 1;
        $requisitos = $datos['requisitos_previos'] ?? [];
        unset($datos['requisitos_previos']);

        $leccion = $modulo->lecciones()->create([...$datos, 'orden' => $siguienteOrden]);
        $leccion->requisitos()->sync($requisitos);

        return $leccion;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarLeccion(Leccion $leccion, array $datos): Leccion
    {
        $requisitos = $datos['requisitos_previos'] ?? [];
        unset($datos['requisitos_previos']);

        $leccion->update($datos);
        $leccion->requisitos()->sync($requisitos);

        return $leccion->fresh();
    }

    public function eliminarLeccion(Leccion $leccion): void
    {
        $leccion->delete();
    }

    public function moverLeccion(CursoModulo $modulo, Leccion $leccion, string $direccion): void
    {
        $lecciones = $modulo->lecciones()->orderBy('orden')->get();
        $this->intercambiarOrdenLeccion($lecciones, $leccion, $direccion);
    }

    /**
     * @param  Collection<int, CursoModulo>  $coleccion
     */
    private function intercambiarOrdenModulo(Collection $coleccion, CursoModulo $elemento, string $direccion): void
    {
        $vecino = $this->buscarVecino($coleccion, $elemento, $direccion);

        if (! $vecino) {
            return;
        }

        $this->intercambiar($elemento, $vecino);
    }

    /**
     * @param  Collection<int, Leccion>  $coleccion
     */
    private function intercambiarOrdenLeccion(Collection $coleccion, Leccion $elemento, string $direccion): void
    {
        $vecino = $this->buscarVecino($coleccion, $elemento, $direccion);

        if (! $vecino) {
            return;
        }

        $this->intercambiar($elemento, $vecino);
    }

    /**
     * @template TModelo of CursoModulo|Leccion
     *
     * @param  Collection<int, TModelo>  $coleccion
     * @param  TModelo  $elemento
     * @return TModelo|null
     */
    private function buscarVecino(Collection $coleccion, CursoModulo|Leccion $elemento, string $direccion): CursoModulo|Leccion|null
    {
        $indice = $coleccion->search(fn ($item) => $item->is($elemento));

        if ($indice === false) {
            return null;
        }

        $indiceVecino = $direccion === 'arriba' ? $indice - 1 : $indice + 1;

        if ($indiceVecino < 0 || $indiceVecino >= $coleccion->count()) {
            return null;
        }

        return $coleccion[$indiceVecino];
    }

    private function intercambiar(CursoModulo|Leccion $elemento, CursoModulo|Leccion $vecino): void
    {
        DB::transaction(function () use ($elemento, $vecino) {
            $ordenElemento = $elemento->orden;
            $elemento->update(['orden' => $vecino->orden]);
            $vecino->update(['orden' => $ordenElemento]);
        });
    }
}
