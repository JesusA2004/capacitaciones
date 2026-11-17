<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\ReglaRecepcionDocumental;
use App\Models\RecepcionDocumentalCatalogo;
use App\Services\RecepcionDocumental\Reglas\ReglaBajas;
use App\Services\RecepcionDocumental\Reglas\ReglaRecepcion;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Catálogo configurable de «lo que recibo» (tabla recepcion_documental_catalogo):
 * una entrada por regla con sus documentos, activación, orden y alcance
 * opcional por sucursal/empresa. Es la FUENTE DE VERDAD de la
 * materialización; config/recepcion_documental.php solo aporta la clase de
 * cada regla y los valores por defecto con que se siembra una entrada que
 * falta (sembrar(), idempotente: nunca pisa lo que RH ya configuró).
 *
 * Agregar una regla = una clase que implemente ReglaRecepcion, un caso en
 * ReglaRecepcionDocumental y su clase en config → reglas; la entrada del
 * catálogo se siembra sola.
 */
class CatalogoReglasRecepcion
{
    /** @var array<string, ReglaRecepcion> */
    private array $instancias = [];

    public function __construct(private readonly Container $contenedor) {}

    /**
     * Todas las entradas (sembrando las que falten), en el orden del catálogo.
     *
     * @return list<ReglaConfigurada>
     */
    public function entradas(): array
    {
        $this->sembrar();
        $resultado = [];

        $entradas = RecepcionDocumentalCatalogo::query()->with('actualizadoPor:id,name')->orderBy('orden')->orderBy('id')->get();

        foreach ($entradas as $entrada) {
            $regla = $this->regla($entrada->regla);

            if ($regla !== null) {
                $resultado[] = new ReglaConfigurada($regla, $entrada);
            }
        }

        return $resultado;
    }

    /**
     * Entradas encendidas (con al menos un documento elegido).
     *
     * @return list<ReglaConfigurada>
     */
    public function activas(): array
    {
        return array_values(array_filter($this->entradas(), fn (ReglaConfigurada $c): bool => $c->activa()));
    }

    public function deEntrada(RecepcionDocumentalCatalogo $entrada): ReglaConfigurada
    {
        $regla = $this->regla($entrada->regla) ?? throw new LogicException(sprintf('La regla %s no tiene clase configurada.', $entrada->regla->value));

        return new ReglaConfigurada($regla, $entrada);
    }

    /**
     * Posición de cada regla según el catálogo (para ordenar bloques).
     *
     * @return array<string, int>
     */
    public function ordenPorRegla(): array
    {
        $orden = [];

        foreach ($this->entradas() as $i => $configurada) {
            $orden[$configurada->entrada->regla->value] = $i + 1;
        }

        foreach (ReglaRecepcionDocumental::cases() as $caso) {
            $orden[$caso->value] ??= 100 + $caso->orden();
        }

        return $orden;
    }

    /**
     * Resumen para las pantallas: cada entrada con sus documentos elegidos y
     * los que podría elegir. Nada «pendiente de definir»: solo existen
     * reglas completamente definidas.
     *
     * @return list<array<string, mixed>>
     */
    public function resumen(): array
    {
        $resumen = [];

        foreach ($this->entradas() as $configurada) {
            $entrada = $configurada->entrada;
            $posibles = $configurada->regla->documentosPosibles();
            $elegidos = $entrada->claves();
            $documentos = [];

            foreach ($posibles as $clave => $etiqueta) {
                $documentos[] = ['clave' => (string) $clave, 'etiqueta' => $etiqueta, 'elegido' => in_array((string) $clave, $elegidos, true)];
            }

            // Una clave elegida que la regla ya no ofrece (p. ej. se quitó del
            // paquete) se muestra para que RH la vea y la quite: nunca se oculta.
            foreach (array_diff($elegidos, array_map('strval', array_keys($posibles))) as $huerfana) {
                $documentos[] = ['clave' => $huerfana, 'etiqueta' => sprintf('%s (ya no la genera el sistema)', EsperadoRecepcion::etiquetaTipo($huerfana)), 'elegido' => true];
            }

            $resumen[] = [
                'id' => $entrada->id,
                'clave' => $entrada->regla->value,
                'etiqueta' => $entrada->regla->etiqueta(),
                'nombre' => $entrada->nombre,
                'descripcion' => $entrada->descripcion,
                'evento' => $entrada->regla->evento(),
                'activa' => $configurada->activa(),
                'orden' => $entrada->orden,
                'documentos' => $documentos,
                'sucursal_ids' => $entrada->sucursales(),
                'empresa_ids' => $entrada->empresas(),
                'actualizado_en' => $entrada->updated_at?->toIso8601String(),
                'actualizado_por' => $entrada->actualizadoPor?->name,
            ];
        }

        return $resumen;
    }

    /**
     * Crea las entradas que falten con los valores por defecto de la
     * configuración. Idempotente; nunca modifica una entrada existente.
     */
    public function sembrar(): int
    {
        $existentes = RecepcionDocumentalCatalogo::query()->pluck('regla')
            ->map(fn (mixed $r): string => $r instanceof ReglaRecepcionDocumental ? $r->value : (string) $r)
            ->all();
        $creadas = 0;

        foreach (ReglaRecepcionDocumental::cases() as $caso) {
            if (in_array($caso->value, $existentes, true)) {
                continue;
            }

            $regla = $this->regla($caso);

            if ($regla === null) {
                continue;
            }

            $documentos = $caso === ReglaRecepcionDocumental::Bajas
                ? ReglaBajas::documentosPorDefecto()
                : array_map('strval', array_keys($regla->documentosPosibles()));

            RecepcionDocumentalCatalogo::query()->firstOrCreate(['regla' => $caso->value], [
                'nombre' => $caso->etiqueta(),
                'documentos' => $documentos,
                'activa' => (bool) config(sprintf('recepcion_documental.reglas.%s.activa', $caso->value), true),
                'orden' => $caso->orden(),
            ]);
            $creadas++;
        }

        return $creadas;
    }

    public function regla(ReglaRecepcionDocumental $caso): ?ReglaRecepcion
    {
        if (isset($this->instancias[$caso->value])) {
            return $this->instancias[$caso->value];
        }

        $clase = config(sprintf('recepcion_documental.reglas.%s.clase', $caso->value));

        if (! is_string($clase) || ! class_exists($clase)) {
            return null;
        }

        $regla = $this->contenedor->make($clase);

        return $regla instanceof ReglaRecepcion ? $this->instancias[$caso->value] = $regla : null;
    }
}
