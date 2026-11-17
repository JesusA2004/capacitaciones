<?php

namespace App\Services\Seguimiento;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Directorio de Reportes a nivel PERSONA y SUCURSAL (api-reporteria.md §16),
 * guardado en la caché de PEOPLE para que ninguna pantalla espere a Reportes:
 *
 *   - Persona = todos los `employees` de la misma persona (sus rutas y
 *     periodos). PEOPLE vincula y suma por persona, nunca por employee id.
 *   - Sucursal = sucursal oficial de Reportes con TODAS sus rutas. Las rutas
 *     no se vinculan una por una: cambian (un gestor pasa de una ruta a otra)
 *     pero siempre pertenecen a la misma sucursal.
 *
 * Se refresca solo (seguimiento:sincronizar-reportes, cada hora) o con el
 * botón «Actualizar desde Reportes»; mientras tanto se usa el último
 * directorio conocido aunque Reportes esté caído.
 *
 * @phpstan-type Persona array{id: int, ids: list<int>, nombre: string, variantes: list<string>, claves: list<string>, puesto: string|null, es_persona: bool, motivo: string|null, sucursal_codigo: string|null, sucursal: string|null, rutas: list<array{id: int, nombre: string, sucursal_codigo: string|null}>}
 * @phpstan-type SucursalReportes array{codigo: string|null, nombre: string, rutas: list<array{id: int, nombre: string}>}
 * @phpstan-type Directorio array{personas: list<Persona>, sucursales: list<SucursalReportes>, actualizado_en: string}
 */
class DirectorioReportesService
{
    private const LLAVE = 'seguimiento:reportes:directorio:v1';

    /** @var Directorio|null */
    private ?array $memo = null;

    public function __construct(private readonly ReportesCatalogoService $catalogo) {}

    /**
     * El directorio guardado; si tiene más de `cache_directorio_minutos` se
     * pide de nuevo a Reportes y, si Reportes no responde, se sigue usando
     * el último conocido (se guarda una semana).
     *
     * @return Directorio
     *
     * @throws ReportesNoDisponibleException
     */
    public function directorio(bool $fresco = false): array
    {
        if (! $fresco && $this->memo !== null) {
            return $this->memo;
        }

        $llave = sprintf('%s:%s', self::LLAVE, md5((string) config('seguimiento.reportes.url')));
        $guardado = $fresco ? null : Cache::remember($llave, now()->addDays(7), fn (): array => $this->construir());

        if ($guardado !== null && ! $this->vencido($guardado['actualizado_en'])) {
            return $this->memo = $guardado;
        }

        try {
            $nuevo = $this->construir();
        } catch (ReportesNoDisponibleException $e) {
            if ($guardado === null) {
                throw $e;
            }

            return $this->memo = $guardado;
        }

        Cache::put($llave, $nuevo, now()->addDays(7));

        return $this->memo = $nuevo;
    }

    /**
     * @return Directorio
     *
     * @throws ReportesNoDisponibleException
     */
    private function construir(): array
    {
        return [
            'personas' => $this->catalogo->personas(),
            'sucursales' => $this->catalogo->sucursalesReportes(),
            'actualizado_en' => now()->toIso8601String(),
        ];
    }

    private function vencido(string $actualizadoEn): bool
    {
        $minutos = max(5, (int) config('seguimiento.reportes.cache_directorio_minutos', 360));

        return Carbon::parse($actualizadoEn)->addMinutes($minutos)->isPast();
    }

    /**
     * Igual que directorio(), pero si Reportes no responde devuelve null
     * (los cálculos siguen por employee id en vez de tronar).
     *
     * @return Directorio|null
     */
    public function directorioSiHay(): ?array
    {
        try {
            return $this->directorio();
        } catch (ReportesNoDisponibleException $e) {
            Log::warning('Seguimiento: sin directorio de personas de Reportes; se agrupa por employee id.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * employee id de Reportes => id de su persona (registro principal).
     *
     * @return array<int, int>
     */
    public function personaPorEmpleado(): array
    {
        $mapa = [];

        foreach ($this->directorioSiHay()['personas'] ?? [] as $persona) {
            foreach ($persona['ids'] as $id) {
                $mapa[$id] ??= $persona['id'];
            }
        }

        return $mapa;
    }

    /**
     * id de persona => sus employee ids.
     *
     * @return array<int, list<int>>
     */
    public function idsPorPersona(): array
    {
        $mapa = [];

        foreach ($this->directorioSiHay()['personas'] ?? [] as $persona) {
            $mapa[$persona['id']] = $persona['ids'];
        }

        return $mapa;
    }

    /**
     * ruta (branch) de Reportes => código de su sucursal oficial.
     *
     * @return array<int, string>
     */
    public function sucursalDeRuta(): array
    {
        $mapa = [];

        foreach ($this->directorioSiHay()['sucursales'] ?? [] as $sucursal) {
            if ($sucursal['codigo'] === null) {
                continue;
            }

            foreach ($sucursal['rutas'] as $ruta) {
                $mapa[$ruta['id']] = $sucursal['codigo'];
            }
        }

        return $mapa;
    }
}
