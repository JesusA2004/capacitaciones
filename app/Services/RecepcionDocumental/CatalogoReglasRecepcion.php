<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\ReglaRecepcionDocumental;
use App\Services\RecepcionDocumental\Reglas\ReglaPendienteDeDefinir;
use App\Services\RecepcionDocumental\Reglas\ReglaRecepcion;
use Illuminate\Contracts\Container\Container;

/**
 * Reglas registradas en config/recepcion_documental.php → reglas, con su
 * activación. Agregar una regla = una clase que implemente ReglaRecepcion,
 * un caso en ReglaRecepcionDocumental y su entrada en la configuración.
 */
class CatalogoReglasRecepcion
{
    public function __construct(private readonly Container $contenedor) {}

    /**
     * Reglas encendidas que sí calculan esperados.
     *
     * @return list<ReglaRecepcion>
     */
    public function activas(): array
    {
        $activas = [];

        foreach ($this->registradas() as ['regla' => $regla, 'activa' => $activa]) {
            if ($activa && ! $regla instanceof ReglaPendienteDeDefinir) {
                $activas[] = $regla;
            }
        }

        return $activas;
    }

    /**
     * Todas las reglas registradas (para la pantalla y la documentación).
     *
     * @return list<array{clave: string, etiqueta: string, activa: bool, pendiente: string|null}>
     */
    public function resumen(): array
    {
        $resumen = [];

        foreach ($this->registradas() as ['regla' => $regla, 'activa' => $activa]) {
            $pendiente = $regla instanceof ReglaPendienteDeDefinir ? $regla->pendiente() : null;
            $resumen[] = [
                'clave' => $regla->regla()->value,
                'etiqueta' => $regla->regla()->etiqueta(),
                'activa' => $activa && $pendiente === null,
                'pendiente' => $pendiente,
            ];
        }

        usort($resumen, fn (array $a, array $b): int => ReglaRecepcionDocumental::from($a['clave'])->orden() <=> ReglaRecepcionDocumental::from($b['clave'])->orden());

        return $resumen;
    }

    /**
     * @return list<array{regla: ReglaRecepcion, activa: bool}>
     */
    private function registradas(): array
    {
        $registradas = [];

        foreach ((array) config('recepcion_documental.reglas', []) as $definicion) {
            if (! is_array($definicion) || ! is_string($definicion['clase'] ?? null) || ! class_exists($definicion['clase'])) {
                continue;
            }

            $regla = $this->contenedor->make($definicion['clase']);

            if ($regla instanceof ReglaRecepcion) {
                $registradas[] = ['regla' => $regla, 'activa' => (bool) ($definicion['activa'] ?? false)];
            }
        }

        return $registradas;
    }
}
