<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoCorteRecepcion;
use App\Enums\ModoCalculoRecepcion;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuración del catálogo de «lo que recibo» (permiso
 * recepcion_documental.configurar). RH puede:
 *
 *  - AGREGAR lo que debe llegar cada mes (crear()): cantidad fija, por
 *    sucursal, por colaborador activo o por cada evento de una regla.
 *  - CAMBIAR cualquier entrada (actualizar()): nombre, nota, documentos y
 *    copias de las reglas del sistema, cantidad / cálculo / bloque / alcance
 *    de las agregadas, encender o apagar.
 *  - QUITAR: apagar (lo esperado deja de contar en los cortes abiertos; lo
 *    recibido se conserva) o, si la entrada agregada NUNCA generó un
 *    renglón, borrarla (eliminar()).
 *
 * Antes de aplicar, simular() muestra por corte abierto y por sucursal
 * cuántos papeles se esperan hoy y cuántos con el cambio, más lo recibido
 * que ya no cuadraría. Al aplicar, el cambio y el recálculo de TODOS los
 * cortes no cerrados van en una sola transacción (todo o nada) con su
 * bitácora; un corte cerrado nunca cambia.
 *
 * @phpstan-type Recalculo array{periodo: string, recalculado: bool, cerrado: bool, nuevos: int, no_aplica: int, reactivados: int}
 * @phpstan-type Aplicado array{entrada: RecepcionDocumentalCatalogo|null, recalculo: Recalculo|null, cortes: list<array<string, mixed>>, conflictos: list<array<string, mixed>>}
 */
class ConfiguracionCatalogoRecepcionService
{
    /** Tope de copias / cantidad que se acepta por unidad (evita errores de dedo). */
    public const MAXIMO_CANTIDAD = 500;

    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly RecepcionDocumentalService $recepcion,
        private readonly AuditoriaService $auditoria,
        private readonly SimulacionRecalculoRecepcionService $simulacion,
    ) {}

    // ───────────────────────── Aplicar ─────────────────────────

    /**
     * Agrega una entrada al catálogo y recalcula los cortes abiertos.
     *
     * @param  array<string, mixed>  $datos
     * @return Aplicado
     */
    public function crear(array $datos, User $actor): array
    {
        $nueva = $this->borrador(null, $datos, true);

        return DB::transaction(function () use ($nueva, $actor): array {
            $nueva->actualizado_por = $actor->id;
            $nueva->clave = $this->claveUnica($nueva->nombre);
            $nueva->save();

            $this->auditoria->registrar('recepcion_documental_catalogo_agregado', $nueva, $actor, [
                'clave' => $nueva->clave,
                'despues' => $this->instantanea($nueva),
            ]);

            return ['entrada' => $nueva, ...$this->recalcularCortesAbiertos($actor, $nueva)];
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return Aplicado
     */
    public function actualizar(RecepcionDocumentalCatalogo $entrada, array $datos, User $actor): array
    {
        $cambios = $this->normalizar($entrada, $datos, true);

        return DB::transaction(function () use ($entrada, $cambios, $actor): array {
            $actual = RecepcionDocumentalCatalogo::query()->lockForUpdate()->findOrFail($entrada->id);
            $antes = $this->instantanea($actual);
            $bloqueAnterior = $actual->bloqueEfectivo();
            $actual->fill([...$cambios, 'actualizado_por' => $actor->id]);

            if (! $actual->isDirty(array_keys($cambios))) {
                return ['entrada' => $actual, 'recalculo' => null, 'cortes' => [], 'conflictos' => []];
            }

            $actual->save();

            // Una entrada agregada que cambia de bloque lleva consigo sus
            // renglones de los cortes abiertos (con lo ya recibido).
            $movidos = $actual->esPersonalizada() && $actual->bloqueEfectivo() !== $bloqueAnterior
                ? $this->moverDeBloque($actual, $bloqueAnterior)
                : 0;

            $this->auditoria->registrar('recepcion_documental_catalogo_actualizado', $actual, $actor, [
                'regla' => $actual->regla->value,
                'clave' => $actual->clave,
                'antes' => $antes,
                'despues' => $this->instantanea($actual),
                'renglones_movidos_de_bloque' => $movidos,
            ]);

            return ['entrada' => $actual, ...$this->recalcularCortesAbiertos($actor, $actual)];
        });
    }

    /**
     * Borra una entrada AGREGADA que nunca generó un renglón (en ningún
     * corte). Si ya se usó, solo se puede desactivar: su historial se queda.
     */
    public function eliminar(RecepcionDocumentalCatalogo $entrada, User $actor): void
    {
        if (! $entrada->esPersonalizada()) {
            throw ValidationException::withMessages(['entrada' => 'Las entradas del sistema no se borran: apágala para que deje de esperarse.']);
        }

        DB::transaction(function () use ($entrada, $actor): void {
            $actual = RecepcionDocumentalCatalogo::query()->lockForUpdate()->findOrFail($entrada->id);
            $usos = $this->catalogo->usoDeAgregadas([$actual])[$actual->id] ?? 0;

            if ($usos > 0) {
                throw ValidationException::withMessages(['entrada' => sprintf('«%s» ya tiene %d renglón(es) en los cortes: no se borra para no perder su historial. Desactívala para que deje de esperarse.', $actual->nombre, $usos)]);
            }

            $this->auditoria->registrar('recepcion_documental_catalogo_eliminado', $actual, $actor, [
                'clave' => $actual->clave,
                'antes' => $this->instantanea($actual),
            ]);
            $actual->delete();
        });
    }

    /**
     * Nuevo orden de las entradas (ids en el orden deseado). Solo cambia el
     * orden de los bloques: no cambia cuántos papeles se esperan.
     *
     * @param  list<int>  $ids
     */
    public function reordenar(array $ids, User $actor): void
    {
        $entradas = RecepcionDocumentalCatalogo::query()->get(['id', 'orden', 'regla', 'clave', 'modo_calculo'])->keyBy('id');
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $entradas->has($id))));

        if (count($ids) !== $entradas->count()) {
            throw ValidationException::withMessages(['ids' => 'Manda todas las entradas del catálogo en el nuevo orden.']);
        }

        DB::transaction(function () use ($ids, $entradas, $actor): void {
            $antes = $entradas->sortBy('orden')->map(fn (RecepcionDocumentalCatalogo $e): string => (string) ($e->clave ?? $e->regla->value))->values()->all();

            foreach ($ids as $posicion => $id) {
                RecepcionDocumentalCatalogo::query()->whereKey($id)->update(['orden' => $posicion + 1, 'actualizado_por' => $actor->id]);
            }

            $primera = RecepcionDocumentalCatalogo::query()->findOrFail($ids[0]);
            $this->auditoria->registrar('recepcion_documental_catalogo_reordenado', $primera, $actor, [
                'antes' => $antes,
                'despues' => array_map(fn (int $id): string => (string) ($entradas[$id]->clave ?? $entradas[$id]->regla->value), $ids),
            ]);
        });
    }

    // ───────────────────────── Simular / vista previa ─────────────────────────

    /**
     * Antes y después del «No. de papeles» en cada corte abierto si se
     * aplicara el cambio (no guarda nada).
     *
     * @param  'crear'|'actualizar'|'eliminar'  $accion
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function simular(string $accion, ?RecepcionDocumentalCatalogo $entrada, array $datos, User $usuario): array
    {
        $guardadas = array_values(RecepcionDocumentalCatalogo::query()->orderBy('orden')->orderBy('id')->get()->all());
        $borrador = null;

        if ($accion === 'crear') {
            $borrador = $this->borrador(null, $datos, true);
            $borrador->clave = 'personalizado.nuevo';
            $catalogo = [...$guardadas, $borrador];
        } elseif ($entrada === null) {
            throw ValidationException::withMessages(['entrada_id' => 'Elige la entrada del catálogo.']);
        } elseif ($accion === 'eliminar') {
            $catalogo = array_values(array_filter($guardadas, fn (RecepcionDocumentalCatalogo $e): bool => $e->id !== $entrada->id));
        } else {
            $borrador = $this->borrador($entrada, $datos, true);
            $catalogo = array_map(fn (RecepcionDocumentalCatalogo $e): RecepcionDocumentalCatalogo => $e->id === $entrada->id ? $borrador : $e, $guardadas);
        }

        $periodo = PeriodoRecepcion::actual();

        return [
            ...$this->simulacion->simular($catalogo, $usuario),
            'entrada' => $borrador !== null ? $this->previaDe($borrador, $periodo) : null,
        ];
    }

    /**
     * Cuánto se esperaría en el periodo con una configuración en BORRADOR
     * de una entrada (no guarda nada). Sin entrada = una entrada nueva.
     *
     * @param  array<string, mixed>  $borrador
     * @return array{periodo: string, periodo_etiqueta: string, activa: bool, eventos: int, documentos: int, por_documento: list<array{clave: string, etiqueta: string, cantidad: int}>}
     */
    public function vistaPrevia(?RecepcionDocumentalCatalogo $entrada, PeriodoRecepcion $periodo, array $borrador = []): array
    {
        return $this->previaDe($this->borrador($entrada, $borrador, false), $periodo);
    }

    /**
     * Vista previa de TODAS las entradas guardadas en el periodo.
     *
     * @return array<int, array{periodo: string, periodo_etiqueta: string, activa: bool, eventos: int, documentos: int, por_documento: list<array{clave: string, etiqueta: string, cantidad: int}>}>
     */
    public function vistaPreviaGeneral(PeriodoRecepcion $periodo): array
    {
        $resultado = [];

        foreach ($this->catalogo->entradas() as $configurada) {
            $resultado[$configurada->entrada->id] = $this->previaDe($configurada->entrada, $periodo);
        }

        return $resultado;
    }

    // ───────────────────────── Recálculo ─────────────────────────

    /**
     * Recalcula cada corte NO cerrado (y el del mes en curso, que se crea si
     * falta). Va dentro de la transacción del cambio: si un corte falla, no
     * se guarda nada. Deja en la bitácora antes/después y los conflictos.
     *
     * @return array{recalculo: Recalculo, cortes: list<array<string, mixed>>, conflictos: list<array<string, mixed>>}
     */
    public function recalcularCortesAbiertos(?User $actor, ?RecepcionDocumentalCatalogo $motivo = null): array
    {
        $actual = PeriodoRecepcion::actual();
        $recalculo = ['periodo' => ucfirst($actual->etiqueta()), 'recalculado' => false, 'cerrado' => false, 'nuevos' => 0, 'no_aplica' => 0, 'reactivados' => 0];
        $cortes = [];
        $conflictos = [];
        $vistos = false;

        foreach ($this->simulacion->periodosAbiertos() as ['periodo' => $periodo, 'corte' => $corte]) {
            $antes = $corte !== null ? $this->recepcion->totalEsperado($corte) : 0;
            ['corte' => $recalculado, 'conteo' => $conteo] = $this->recepcion->generar($periodo, $actor, true);

            if ($conteo === null) {
                continue;
            }

            $propios = $this->recepcion->ultimosConflictos();
            $despues = $this->recepcion->totalEsperado($recalculado);
            $cortes[] = ['periodo' => $periodo->clave(), 'etiqueta' => ucfirst($periodo->etiqueta()), 'antes' => $antes, 'despues' => $despues, ...$conteo, 'conflictos' => count($propios)];

            foreach ($propios as $c) {
                $conflictos[] = ['periodo' => ucfirst($periodo->etiqueta()), ...$c];
            }

            if ($periodo->clave() === $actual->clave()) {
                $vistos = true;
                $recalculo = [...$recalculo, 'recalculado' => true, 'nuevos' => $conteo['nuevos'], 'no_aplica' => $conteo['no_aplica'], 'reactivados' => $conteo['reactivados']];
            }
        }

        if (! $vistos) {
            $recalculo['cerrado'] = true;
        }

        $this->auditoria->registrar('recepcion_documental_catalogo_recalculado', $motivo, $actor, [
            'clave' => $motivo?->clave,
            'cortes' => $cortes,
            'conflictos' => array_slice($conflictos, 0, 200),
        ]);

        return ['recalculo' => $recalculo, 'cortes' => $cortes, 'conflictos' => $conflictos];
    }

    // ───────────────────────── Internos ─────────────────────────

    /**
     * La entrada con los cambios aplicados SIN guardar (para simular y para
     * crear). $estricto = validación completa (al guardar o simular);
     * sin él, solo se rechaza lo imposible (vista previa en vivo).
     *
     * @param  array<string, mixed>  $datos
     */
    private function borrador(?RecepcionDocumentalCatalogo $entrada, array $datos, bool $estricto): RecepcionDocumentalCatalogo
    {
        if ($entrada !== null) {
            $copia = clone $entrada;
            $copia->fill($this->normalizar($entrada, $datos, $estricto));

            return $copia;
        }

        $modo = ModoCalculoRecepcion::tryFrom(is_string($datos['modo_calculo'] ?? null) ? $datos['modo_calculo'] : '');

        if ($modo === null || ! $modo->esPersonalizado()) {
            throw ValidationException::withMessages(['modo_calculo' => 'Elige cómo se calcula: cantidad fija, por sucursal, por colaborador activo o por evento.']);
        }

        $nueva = new RecepcionDocumentalCatalogo([
            'regla' => ReglaRecepcionDocumental::Personalizada,
            'modo_calculo' => $modo,
            'cantidad' => 1,
            'activa' => true,
            'nombre' => '',
            'orden' => (int) RecepcionDocumentalCatalogo::query()->max('orden') + 1,
        ]);

        if ($estricto && ! array_key_exists('nombre', $datos)) {
            $datos['nombre'] = '';
        }

        $nueva->fill($this->normalizar($nueva, $datos, $estricto));

        if (! array_key_exists('bloque', $datos) || $datos['bloque'] === null) {
            $nueva->bloque = $nueva->modo_calculo === ModoCalculoRecepcion::PorEvento && $nueva->regla_base !== null ? $nueva->regla_base : ReglaRecepcionDocumental::Personalizada;
        }

        return $nueva;
    }

    /**
     * @return array{periodo: string, periodo_etiqueta: string, activa: bool, eventos: int, documentos: int, por_documento: list<array{clave: string, etiqueta: string, cantidad: int}>}
     */
    private function previaDe(RecepcionDocumentalCatalogo $entrada, PeriodoRecepcion $periodo): array
    {
        $configurada = $this->catalogo->deEntrada($entrada);
        $posibles = $configurada->regla->documentosPosibles();
        $activa = $configurada->activa();
        $esperados = $activa && ! $periodo->esFuturo() ? $configurada->esperados($periodo) : [];

        $porDocumento = [];
        $eventos = [];

        foreach ($esperados as $esperado) {
            $clave = $esperado->documento();
            $porDocumento[$clave] ??= ['clave' => $clave, 'etiqueta' => $posibles[$clave] ?? $esperado->tipoEtiqueta, 'cantidad' => 0];
            $porDocumento[$clave]['cantidad'] += max(1, $esperado->cantidad);
            $eventos[sprintf('%s|%d|%s', $esperado->origen->getMorphClass(), (int) $esperado->origen->getKey(), $esperado->fechaEvento)] = true;
        }

        return [
            'periodo' => $periodo->clave(),
            'periodo_etiqueta' => ucfirst($periodo->etiqueta()),
            'activa' => $activa,
            'eventos' => count($eventos),
            'documentos' => array_sum(array_column($porDocumento, 'cantidad')),
            'por_documento' => array_values($porDocumento),
        ];
    }

    /**
     * Mueve los renglones de una entrada agregada de los cortes NO cerrados
     * a su nuevo bloque (conservan estado, recibido y evidencia). Los cortes
     * cerrados se quedan como estaban.
     */
    private function moverDeBloque(RecepcionDocumentalCatalogo $entrada, ReglaRecepcionDocumental $anterior): int
    {
        $clave = (string) $entrada->clave;

        return RecepcionDocumentalRenglon::query()
            ->where('regla', $anterior->value)
            ->where(fn ($q) => $q->where('tipo_documento', $clave)->orWhere('tipo_documento', 'like', sprintf('%s.%%', str_replace(['%', '_'], ['\%', '\_'], $clave))))
            ->whereHas('corte', fn ($q) => $q->where('estado', '!=', EstadoCorteRecepcion::Cerrado->value))
            ->update(['regla' => $entrada->bloqueEfectivo()->value]);
    }

    private function claveUnica(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre), 24, '');
        $base = $base !== '' ? rtrim($base, '-') : 'entrada';

        do {
            $clave = sprintf('personalizado.%s-%s', $base, Str::lower(Str::random(6)));
        } while (RecepcionDocumentalCatalogo::query()->where('clave', $clave)->exists());

        return $clave;
    }

    /**
     * Valida y normaliza lo que se puede cambiar de una entrada.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizar(RecepcionDocumentalCatalogo $entrada, array $datos, bool $estricto): array
    {
        $cambios = [];
        $agregada = $entrada->esPersonalizada();

        if (array_key_exists('nombre', $datos)) {
            $nombre = trim((string) $datos['nombre']);

            if ($nombre === '' && $estricto) {
                throw ValidationException::withMessages(['nombre' => 'Escribe el nombre de lo que se recibe.']);
            }

            $cambios['nombre'] = mb_substr($nombre !== '' ? $nombre : 'Nueva entrada', 0, 160);

            if ($agregada && $estricto && RecepcionDocumentalCatalogo::query()
                ->where('modo_calculo', '!=', ModoCalculoRecepcion::Regla->value)
                ->when($entrada->exists, fn ($q) => $q->whereKeyNot($entrada->id))
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($cambios['nombre'])])
                ->exists()) {
                throw ValidationException::withMessages(['nombre' => 'Ya hay otra entrada agregada con ese nombre.']);
            }
        }

        if (array_key_exists('descripcion', $datos)) {
            $descripcion = trim((string) $datos['descripcion']);
            $cambios['descripcion'] = $descripcion !== '' ? mb_substr($descripcion, 0, 500) : null;
        }

        if ($agregada) {
            $cambios += $this->normalizarAgregada($entrada, $datos, $estricto);
        } else {
            $cambios += $this->normalizarDelSistema($entrada, $datos);
        }

        if (array_key_exists('activa', $datos) && $datos['activa'] !== null) {
            $cambios['activa'] = (bool) $datos['activa'];
        }

        if (! $agregada && $estricto) {
            $documentosFinales = $cambios['documentos'] ?? $entrada->claves();

            if (($cambios['activa'] ?? $entrada->activa) && $documentosFinales === []) {
                throw ValidationException::withMessages(['documentos' => 'Elige al menos un documento para dejar la entrada encendida.']);
            }
        }

        if (array_key_exists('sucursal_ids', $datos)) {
            $ids = array_values(array_unique(array_map('intval', (array) $datos['sucursal_ids'])));

            if (count($ids) !== Sucursal::query()->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['sucursal_ids' => 'Alguna sucursal elegida no existe.']);
            }

            sort($ids);
            $cambios['sucursal_ids'] = $ids !== [] ? $ids : null;
        }

        if (array_key_exists('empresa_ids', $datos)) {
            $ids = array_values(array_unique(array_map('intval', (array) $datos['empresa_ids'])));

            if (count($ids) !== Empresa::query()->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['empresa_ids' => 'Alguna empresa elegida no existe.']);
            }

            sort($ids);
            $cambios['empresa_ids'] = $ids !== [] ? $ids : null;
        }

        return $cambios;
    }

    /**
     * Documentos y copias de una regla del sistema (su cálculo no cambia).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizarDelSistema(RecepcionDocumentalCatalogo $entrada, array $datos): array
    {
        foreach (['modo_calculo', 'cantidad', 'regla_base', 'bloque', 'departamento_ids'] as $campo) {
            if (array_key_exists($campo, $datos) && $datos[$campo] !== null && $datos[$campo] !== []) {
                throw ValidationException::withMessages([$campo => 'Las entradas del sistema calculan solas sus eventos: aquí solo se eligen documentos, copias, sucursales y empresas.']);
            }
        }

        $cambios = [];
        $posibles = array_map('strval', array_keys($this->catalogo->deEntrada($entrada)->regla->documentosPosibles()));

        if (array_key_exists('documentos', $datos) && is_array($datos['documentos'])) {
            $elegidos = array_values(array_unique(array_map('strval', $datos['documentos'])));
            $ajenos = array_diff($elegidos, $posibles, $entrada->claves());

            if ($ajenos !== []) {
                throw ValidationException::withMessages(['documentos' => sprintf('Esta regla no genera: %s.', implode(', ', $ajenos))]);
            }

            $cambios['documentos'] = $elegidos;
        }

        if (array_key_exists('copias', $datos) && is_array($datos['copias'])) {
            $copias = [];

            foreach ($datos['copias'] as $clave => $n) {
                $clave = (string) $clave;

                if (! in_array($clave, [...$posibles, ...$entrada->claves()], true)) {
                    throw ValidationException::withMessages(['copias' => sprintf('Esta regla no genera: %s.', $clave)]);
                }

                $n = is_numeric($n) ? (int) $n : 0;

                if ($n < 1 || $n > self::MAXIMO_CANTIDAD) {
                    throw ValidationException::withMessages(['copias' => sprintf('Las copias van de 1 a %d.', self::MAXIMO_CANTIDAD)]);
                }

                if ($n > 1) {
                    $copias[$clave] = $n;
                }
            }

            ksort($copias);
            $cambios['copias'] = $copias !== [] ? $copias : null;
        }

        return $cambios;
    }

    /**
     * Cálculo, cantidad, regla base, bloque y áreas de una entrada agregada.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizarAgregada(RecepcionDocumentalCatalogo $entrada, array $datos, bool $estricto): array
    {
        foreach (['documentos', 'copias'] as $campo) {
            if (array_key_exists($campo, $datos) && $datos[$campo] !== null && $datos[$campo] !== []) {
                throw ValidationException::withMessages([$campo => 'Una entrada agregada es un solo documento: usa «Cantidad» para decir cuántos papeles llegan.']);
            }
        }

        $cambios = [];

        if (array_key_exists('modo_calculo', $datos) && $datos['modo_calculo'] !== null) {
            $modo = ModoCalculoRecepcion::tryFrom((string) $datos['modo_calculo']);

            if ($modo === null || ! $modo->esPersonalizado()) {
                throw ValidationException::withMessages(['modo_calculo' => 'Elige cómo se calcula: cantidad fija, por sucursal, por colaborador activo o por evento.']);
            }

            $cambios['modo_calculo'] = $modo;
        }

        $modo = $cambios['modo_calculo'] ?? $entrada->modo_calculo;

        if (array_key_exists('cantidad', $datos) && $datos['cantidad'] !== null) {
            $n = is_numeric($datos['cantidad']) ? (int) $datos['cantidad'] : 0;

            if ($n < 1 || $n > self::MAXIMO_CANTIDAD) {
                throw ValidationException::withMessages(['cantidad' => sprintf('La cantidad va de 1 a %d papeles.', self::MAXIMO_CANTIDAD)]);
            }

            $cambios['cantidad'] = $n;
        }

        if (array_key_exists('regla_base', $datos)) {
            $cambios['regla_base'] = $this->reglaDelSistema($datos['regla_base'], 'regla_base');
        }

        if ($modo !== ModoCalculoRecepcion::PorEvento) {
            $cambios['regla_base'] = null;
        } elseif ($estricto && ($cambios['regla_base'] ?? $entrada->regla_base) === null) {
            throw ValidationException::withMessages(['regla_base' => 'Elige de qué evento del sistema depende (altas, bajas, nómina…).']);
        }

        if (array_key_exists('bloque', $datos) && $datos['bloque'] !== null && $datos['bloque'] !== '') {
            $bloque = ReglaRecepcionDocumental::tryFrom((string) $datos['bloque']);

            if ($bloque === null || ($bloque !== ReglaRecepcionDocumental::Personalizada && $this->catalogo->regla($bloque) === null)) {
                throw ValidationException::withMessages(['bloque' => 'Ese bloque no existe en el corte.']);
            }

            $cambios['bloque'] = $bloque;
        }

        if (array_key_exists('departamento_ids', $datos)) {
            $ids = array_values(array_unique(array_map('intval', (array) $datos['departamento_ids'])));

            if (count($ids) !== Departamento::query()->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['departamento_ids' => 'Alguna área elegida no existe.']);
            }

            sort($ids);
            $cambios['departamento_ids'] = $ids !== [] && $modo->admiteAreas() ? $ids : null;
        } elseif (! $modo->admiteAreas()) {
            $cambios['departamento_ids'] = null;
        }

        if (! $modo->admiteAlcance()) {
            $cambios['sucursal_ids'] = null;
            $cambios['empresa_ids'] = null;
        }

        return $cambios;
    }

    private function reglaDelSistema(mixed $valor, string $campo): ?ReglaRecepcionDocumental
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $regla = ReglaRecepcionDocumental::tryFrom((string) $valor);

        if ($regla === null || $regla === ReglaRecepcionDocumental::Personalizada || $this->catalogo->regla($regla) === null) {
            throw ValidationException::withMessages([$campo => 'Esa regla del sistema no existe.']);
        }

        return $regla;
    }

    /**
     * @return array<string, mixed>
     */
    private function instantanea(RecepcionDocumentalCatalogo $e): array
    {
        return [
            'nombre' => $e->nombre,
            'descripcion' => $e->descripcion,
            'modo_calculo' => $e->modo_calculo->value,
            'cantidad' => $e->cantidadUnitaria(),
            'regla_base' => $e->regla_base?->value,
            'bloque' => $e->bloqueEfectivo()->value,
            'documentos' => $e->claves(),
            'copias' => (array) $e->copias,
            'activa' => $e->activa,
            'sucursal_ids' => $e->sucursales(),
            'empresa_ids' => $e->empresas(),
            'departamento_ids' => $e->departamentos(),
        ];
    }
}
