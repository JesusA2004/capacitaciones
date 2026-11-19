<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\ModoCalculoRecepcion;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalRenglon;
use App\Services\RecepcionDocumental\Reglas\ReglaBajas;
use App\Services\RecepcionDocumental\Reglas\ReglaPersonalizada;
use App\Services\RecepcionDocumental\Reglas\ReglaRecepcion;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Catálogo configurable de «lo que recibo» (tabla recepcion_documental_catalogo).
 * Es la FUENTE DE VERDAD de la materialización. Dos clases de entrada:
 *
 *  - Del SISTEMA (modo «regla»): una por regla (altas, bajas, nómina…) con
 *    sus documentos, copias, activación, orden y alcance opcional por
 *    sucursal/empresa. config/recepcion_documental.php solo aporta la clase
 *    de cada regla y los valores por defecto con que se siembra una entrada
 *    que falta (sembrar(), idempotente: nunca pisa lo que RH configuró).
 *  - AGREGADAS por RH (ModoCalculoRecepcion::personalizables()): lo que debe
 *    llegar cada mes sin evento propio (cantidad fija, por sucursal, por
 *    colaborador activo) o por cada evento de una regla del sistema; las
 *    calcula ReglaPersonalizada.
 *
 * Agregar una regla del SISTEMA = una clase que implemente ReglaRecepcion,
 * un caso en ReglaRecepcionDocumental y su clase en config → reglas; la
 * entrada del catálogo se siembra sola.
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

        return $this->configuradas(RecepcionDocumentalCatalogo::query()->with('actualizadoPor:id,name')->orderBy('orden')->orderBy('id')->get()->all());
    }

    /**
     * Arma las entradas dadas (guardadas o en BORRADOR, para la simulación
     * del recálculo). Una regla del sistema sin clase configurada se omite;
     * una entrada agregada usa ReglaPersonalizada (con su regla base si
     * cuenta por evento).
     *
     * @param  iterable<RecepcionDocumentalCatalogo>  $entradas
     * @return list<ReglaConfigurada>
     */
    public function configuradas(iterable $entradas): array
    {
        $resultado = [];

        foreach ($entradas as $entrada) {
            if ($entrada->esPersonalizada()) {
                $base = $entrada->regla_base !== null ? $this->regla($entrada->regla_base) : null;
                $resultado[] = new ReglaConfigurada(new ReglaPersonalizada($entrada, $base), $entrada);

                continue;
            }

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
        return $this->configuradas([$entrada])[0] ?? throw new LogicException(sprintf('La regla %s no tiene clase configurada.', $entrada->regla->value));
    }

    /**
     * Posición de cada BLOQUE según el catálogo (para ordenar el corte). Las
     * reglas del sistema fijan la posición de su bloque; un bloque sin regla
     * propia («Otros documentos») toma la de su primera entrada.
     *
     * @param  list<ReglaConfigurada>|null  $entradas
     * @return array<string, int>
     */
    public function ordenPorRegla(?array $entradas = null): array
    {
        $orden = [];
        $entradas ??= $this->entradas();

        foreach ($entradas as $i => $configurada) {
            if (! $configurada->entrada->esPersonalizada()) {
                $orden[$configurada->entrada->regla->value] = $i + 1;
            }
        }

        foreach ($entradas as $i => $configurada) {
            $orden[$configurada->bloque()->value] ??= $i + 1;
        }

        foreach (ReglaRecepcionDocumental::cases() as $caso) {
            $orden[$caso->value] ??= 100 + $caso->orden();
        }

        return $orden;
    }

    /**
     * Resumen para las pantallas: cada entrada con sus documentos elegidos
     * (y copias), los que podría elegir y, si la agregó RH, cómo se calcula.
     *
     * @return list<array<string, mixed>>
     */
    public function resumen(): array
    {
        $resumen = [];
        $entradas = $this->entradas();
        $uso = $this->usoDeAgregadas(array_map(fn (ReglaConfigurada $c): RecepcionDocumentalCatalogo => $c->entrada, $entradas));
        $nombresBloque = $this->nombresDeBloque($entradas);

        foreach ($entradas as $configurada) {
            $entrada = $configurada->entrada;
            $posibles = $configurada->regla->documentosPosibles();
            $elegidos = $configurada->documentos();
            $documentos = [];

            foreach ($posibles as $clave => $etiqueta) {
                $documentos[] = ['clave' => (string) $clave, 'etiqueta' => $etiqueta, 'elegido' => in_array((string) $clave, $elegidos, true), 'copias' => $entrada->copiasDe((string) $clave)];
            }

            // Una clave elegida que la regla ya no ofrece (p. ej. se quitó del
            // paquete) se muestra para que RH la vea y la quite: nunca se oculta.
            foreach (array_diff($elegidos, array_map('strval', array_keys($posibles))) as $huerfana) {
                $documentos[] = ['clave' => $huerfana, 'etiqueta' => sprintf('%s (ya no la genera el sistema)', EsperadoRecepcion::etiquetaTipo($huerfana)), 'elegido' => true, 'copias' => $entrada->copiasDe($huerfana)];
            }

            $usos = $uso[$entrada->id] ?? 0;
            $bloque = $configurada->bloque();
            $agregada = $entrada->esPersonalizada();

            $resumen[] = [
                'id' => $entrada->id,
                'clave' => $agregada ? (string) $entrada->clave : $entrada->regla->value,
                'etiqueta' => $agregada ? $entrada->modo_calculo->etiqueta() : $entrada->regla->etiqueta(),
                'nombre' => $entrada->nombre,
                'descripcion' => $entrada->descripcion,
                'evento' => $agregada ? $this->explicacion($entrada) : $entrada->regla->evento(),
                'es_personalizada' => $agregada,
                'modo_calculo' => $entrada->modo_calculo->value,
                'modo_etiqueta' => $entrada->modo_calculo->etiqueta(),
                'cantidad' => $entrada->cantidadUnitaria(),
                'regla_base' => $entrada->regla_base?->value,
                'bloque' => $bloque->value,
                'bloque_etiqueta' => $nombresBloque[$bloque->value] ?? $bloque->etiqueta(),
                'activa' => $configurada->activa(),
                'orden' => $entrada->orden,
                'documentos' => $documentos,
                'sucursal_ids' => $entrada->sucursales(),
                'empresa_ids' => $entrada->empresas(),
                'departamento_ids' => $entrada->departamentos(),
                'renglones_usados' => $usos,
                'puede_eliminar' => $agregada && $usos === 0,
                'actualizado_en' => $entrada->updated_at?->toIso8601String(),
                'actualizado_por' => $entrada->actualizadoPor?->name,
            ];
        }

        return $resumen;
    }

    /**
     * Nombre visible de cada bloque: el de la entrada del sistema de esa
     * regla (RH lo puede renombrar); la etiqueta de la regla para el resto.
     *
     * @param  list<ReglaConfigurada>|null  $entradas
     * @return array<string, string>
     */
    public function nombresDeBloque(?array $entradas = null): array
    {
        $nombres = [];

        foreach ($entradas ?? $this->entradas() as $configurada) {
            if (! $configurada->entrada->esPersonalizada()) {
                $nombres[$configurada->entrada->regla->value] = $configurada->entrada->nombre;
            }
        }

        foreach (ReglaRecepcionDocumental::cases() as $caso) {
            $nombres[$caso->value] ??= $caso->etiqueta();
        }

        return $nombres;
    }

    /**
     * Opciones para agregar una entrada: modos de cálculo, bloques donde
     * puede aparecer y reglas del sistema cuyos eventos puede contar.
     *
     * @return array{bloques: list<array{value: string, label: string}>, reglas_base: list<array{value: string, label: string, descripcion: string}>, modos: list<array{value: string, label: string, descripcion: string, alcance: bool, areas: bool}>}
     */
    public function opciones(): array
    {
        $nombres = $this->nombresDeBloque();
        $bloques = [['value' => ReglaRecepcionDocumental::Personalizada->value, 'label' => $nombres[ReglaRecepcionDocumental::Personalizada->value]]];
        $reglasBase = [];

        foreach (ReglaRecepcionDocumental::cases() as $caso) {
            if ($caso === ReglaRecepcionDocumental::Personalizada || $this->regla($caso) === null) {
                continue;
            }

            $bloques[] = ['value' => $caso->value, 'label' => $nombres[$caso->value]];
            $reglasBase[] = ['value' => $caso->value, 'label' => $nombres[$caso->value], 'descripcion' => $caso->evento()];
        }

        return [
            'bloques' => $bloques,
            'reglas_base' => $reglasBase,
            'modos' => array_map(fn (ModoCalculoRecepcion $m): array => [
                'value' => $m->value,
                'label' => $m->etiqueta(),
                'descripcion' => $m->descripcion(),
                'alcance' => $m->admiteAlcance(),
                'areas' => $m->admiteAreas(),
            ], ModoCalculoRecepcion::personalizables()),
        ];
    }

    /**
     * Cuántos renglones (de cualquier corte, abierto o cerrado) tiene cada
     * entrada agregada. Con uso > 0 ya no se puede borrar: solo desactivar.
     *
     * @param  list<RecepcionDocumentalCatalogo>  $entradas
     * @return array<int, int> id de la entrada => renglones
     */
    public function usoDeAgregadas(array $entradas): array
    {
        $agregadas = array_values(array_filter($entradas, fn (RecepcionDocumentalCatalogo $e): bool => $e->esPersonalizada() && $e->clave !== null));

        if ($agregadas === []) {
            return [];
        }

        $porTipo = RecepcionDocumentalRenglon::query()
            ->where('tipo_documento', 'like', 'personalizado.%')
            ->selectRaw('tipo_documento, COUNT(*) AS total')
            ->groupBy('tipo_documento')
            ->toBase()
            ->get();
        $uso = [];

        foreach ($porTipo as $fila) {
            foreach ($agregadas as $entrada) {
                if ($entrada->esDuenaDeTipo((string) $fila->tipo_documento)) {
                    $uso[$entrada->id] = ($uso[$entrada->id] ?? 0) + (int) $fila->total;
                }
            }
        }

        return $uso;
    }

    /** Texto de cómo se calcula una entrada agregada. */
    public function explicacion(RecepcionDocumentalCatalogo $entrada): string
    {
        $n = $entrada->cantidadUnitaria();
        $papeles = sprintf('%d papel%s', $n, $n === 1 ? '' : 'es');
        $base = $entrada->regla_base;

        return match ($entrada->modo_calculo) {
            ModoCalculoRecepcion::FijoMensual => sprintf('%s cada mes (general, sin sucursal).', ucfirst($papeles)),
            ModoCalculoRecepcion::PorSucursal => sprintf('%s por cada sucursal cada mes.', ucfirst($papeles)),
            ModoCalculoRecepcion::PorColaboradorActivo => sprintf('%s por cada colaborador activo de la sucursal en el mes.', ucfirst($papeles)),
            ModoCalculoRecepcion::PorEvento => $base !== null
                ? sprintf('%s por cada evento de «%s»: %s', ucfirst($papeles), $base->etiqueta(), lcfirst($base->evento()))
                : sprintf('%s por evento (falta elegir la regla).', ucfirst($papeles)),
            ModoCalculoRecepcion::Regla => $entrada->regla->evento(),
        };
    }

    /**
     * Crea las entradas DEL SISTEMA que falten con los valores por defecto
     * de la configuración. Idempotente; nunca modifica una entrada existente
     * ni toca las agregadas por RH.
     */
    public function sembrar(): int
    {
        $existentes = RecepcionDocumentalCatalogo::query()->where('modo_calculo', ModoCalculoRecepcion::Regla->value)->pluck('regla')
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

            RecepcionDocumentalCatalogo::query()->firstOrCreate(['clave' => $caso->value], [
                'regla' => $caso->value,
                'modo_calculo' => ModoCalculoRecepcion::Regla,
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
