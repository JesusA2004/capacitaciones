<?php

namespace App\Services\Reportes\Propios;

use App\Models\ReporteGuardado;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reglas de «Mis reportes»: valida una definición contra el catálogo cerrado y
 * la ejecuta con datos actuales. Permiso de fuente, permiso por campo y alcance
 * se revisan en CADA ejecución (vista previa, Excel y PDF), no al guardar: si a
 * un usuario le retiran un permiso, su reporte deja de traer ese dato. Un campo
 * sin permiso tampoco filtra, ordena ni grafica, porque esos usos también
 * revelarían su valor.
 *
 * @phpstan-type Filtro array{campo: string, operador: string, valor: mixed}
 * @phpstan-type Orden array{campo: string, direccion: string}
 * @phpstan-type Definicion array{fuente: string, columnas: list<string>, filtros: list<Filtro>, orden: list<Orden>, diseno: array<string, mixed>}
 * @phpstan-type Celda string|int|float|null
 * @phpstan-type Resultado array{titulo: string, fuente: string, columnas: list<array{clave: string, etiqueta: string, tipo: string}>, filas: list<list<Celda>>, total: int, totales: list<Celda>|null, grafica: array{tipo: string, titulo: string, nombre: string, categorias: list<string>, valores: list<float>}|null, omitidas: list<string>, avisos: list<string>, filtros_texto: list<string>, diseno: array<string, mixed>}
 */
class ReportePropioService
{
    public const LIMITE_VISTA_PREVIA = 50;

    private const MAX_COLUMNAS = 45;

    private const MAX_FILTROS = 15;

    private const MAX_CATEGORIAS_GRAFICA = 12;

    /** Operadores permitidos por tipo de campo. */
    private const OPERADORES = [
        'texto' => ['es', 'no_es', 'contiene', 'en', 'vacio', 'no_vacio'],
        'numero' => ['igual', 'mayor', 'menor', 'entre', 'vacio', 'no_vacio'],
        'moneda' => ['igual', 'mayor', 'menor', 'entre', 'vacio', 'no_vacio'],
        'fecha' => ['entre', 'desde', 'hasta', 'vacio', 'no_vacio'],
    ];

    private const ETIQUETAS_OPERADOR = [
        'es' => 'es', 'no_es' => 'no es', 'contiene' => 'contiene', 'en' => 'es', 'vacio' => 'está vacío', 'no_vacio' => 'tiene dato',
        'igual' => 'es igual a', 'mayor' => 'mayor que', 'menor' => 'menor que', 'entre' => 'entre', 'desde' => 'desde', 'hasta' => 'hasta',
    ];

    public function __construct(private readonly CatalogoCamposReporte $catalogo) {}

    /**
     * Diseño por defecto de un reporte nuevo.
     *
     * @return array<string, mixed>
     */
    public function disenoPorDefecto(): array
    {
        return [
            'titulo' => '',
            'subtitulo' => '',
            'orientacion' => 'landscape',
            'tamano' => 'letter',
            'color' => 'petroleo',
            'encabezado' => true,
            'mostrar_filtros' => true,
            'totales' => false,
            'ajuste' => 'normal',
            'grafica' => ['activa' => false, 'tipo' => 'barras', 'campo' => '', 'valor' => 'conteo'],
        ];
    }

    /**
     * Valida una definición recibida del navegador. Rechaza cualquier clave
     * fuera del catálogo, operador inválido o campo sin permiso.
     *
     * @param  array<string, mixed>  $datos
     * @return Definicion
     *
     * @throws ValidationException
     */
    public function normalizar(array $datos, User $usuario): array
    {
        $fuentes = $this->catalogo->fuentes();
        $clave = is_string($datos['fuente'] ?? null) ? $datos['fuente'] : '';

        if (! isset($fuentes[$clave])) {
            throw ValidationException::withMessages(['fuente' => 'Elige una fuente de datos válida.']);
        }

        if (! $usuario->can($fuentes[$clave]['permiso'])) {
            throw ValidationException::withMessages(['fuente' => 'No tienes permiso para consultar esta fuente.']);
        }

        $campos = $fuentes[$clave]['campos'];
        $exigir = function (mixed $campo, string $error) use ($campos, $usuario): string {
            if (! is_string($campo) || ! isset($campos[$campo])) {
                throw ValidationException::withMessages([$error => 'Hay un campo que no existe en esta fuente.']);
            }

            if (! $this->catalogo->puedeCampo($usuario, $campos[$campo])) {
                throw ValidationException::withMessages([$error => sprintf('No tienes permiso para usar el campo «%s».', $campos[$campo]['etiqueta'])]);
            }

            return $campo;
        };

        $columnas = [];

        foreach ($this->lista($datos['columnas'] ?? []) as $columna) {
            $columnas[] = $exigir($columna, 'columnas');
        }

        $columnas = array_values(array_unique($columnas));

        if ($columnas === []) {
            throw ValidationException::withMessages(['columnas' => 'Agrega al menos una columna al reporte.']);
        }

        if (count($columnas) > self::MAX_COLUMNAS) {
            throw ValidationException::withMessages(['columnas' => 'El reporte admite hasta 45 columnas.']);
        }

        $filtros = [];
        $entradaFiltros = $this->lista($datos['filtros'] ?? []);

        if (count($entradaFiltros) > self::MAX_FILTROS) {
            throw ValidationException::withMessages(['filtros' => 'El reporte admite hasta 15 filtros.']);
        }

        foreach ($entradaFiltros as $filtro) {
            $filtro = is_array($filtro) ? $filtro : [];
            $campo = $exigir($filtro['campo'] ?? null, 'filtros');
            $operador = $filtro['operador'] ?? null;

            if (! is_string($operador) || ! in_array($operador, self::OPERADORES[$campos[$campo]['tipo']], true)) {
                throw ValidationException::withMessages(['filtros' => sprintf('El filtro de «%s» usa una condición no permitida.', $campos[$campo]['etiqueta'])]);
            }

            $filtros[] = ['campo' => $campo, 'operador' => $operador, 'valor' => $this->valorFiltro($operador, $filtro['valor'] ?? null, $campos[$campo]['tipo'], $campos[$campo]['etiqueta'])];
        }

        $orden = [];

        foreach ($this->lista($datos['orden'] ?? []) as $criterio) {
            $criterio = is_array($criterio) ? $criterio : [];
            $orden[] = ['campo' => $exigir($criterio['campo'] ?? null, 'orden'), 'direccion' => ($criterio['direccion'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
        }

        return [
            'fuente' => $clave,
            'columnas' => $columnas,
            'filtros' => $filtros,
            'orden' => array_slice($orden, 0, 3),
            'diseno' => $this->normalizarDiseno(is_array($datos['diseno'] ?? null) ? $datos['diseno'] : [], $campos, $usuario, $exigir),
        ];
    }

    /**
     * Ejecuta una definición con datos actuales. Lo que el usuario ya no puede
     * ver se omite y se avisa; nunca se devuelve ni se usa para filtrar.
     *
     * @param  array<string, mixed>  $definicion
     * @return Resultado
     *
     * @throws AuthorizationException
     */
    public function ejecutar(array $definicion, User $usuario): array
    {
        $fuentes = $this->catalogo->fuentes();
        $clave = is_string($definicion['fuente'] ?? null) ? $definicion['fuente'] : '';
        $fuente = $fuentes[$clave] ?? null;

        if ($fuente === null || ! $usuario->can('reportes_rh.mis_reportes') || ! $usuario->can($fuente['permiso'])) {
            throw new AuthorizationException('No tienes permiso para consultar esta fuente.');
        }

        $campos = $fuente['campos'];
        $disponible = fn (mixed $campo): bool => is_string($campo) && isset($campos[$campo]) && $this->catalogo->puedeCampo($usuario, $campos[$campo]);

        $columnas = [];
        $omitidas = [];

        foreach ($this->lista($definicion['columnas'] ?? []) as $columna) {
            if ($disponible($columna)) {
                $columnas[] = (string) $columna;
            } elseif (is_string($columna) && isset($campos[$columna])) {
                $omitidas[] = $campos[$columna]['etiqueta'];
            }
        }

        $avisos = [];

        if ($omitidas !== []) {
            $avisos[] = sprintf('No tienes permiso para ver: %s. Esas columnas se omitieron.', implode(', ', $omitidas));
        }

        $filtros = [];

        foreach ($this->lista($definicion['filtros'] ?? []) as $filtro) {
            if (is_array($filtro) && $disponible($filtro['campo'] ?? null)) {
                $filtros[] = $filtro;
            } elseif (is_array($filtro)) {
                $avisos[] = 'Un filtro usa un campo que ya no puedes consultar y no se aplicó.';
            }
        }

        $orden = array_values(array_filter($this->lista($definicion['orden'] ?? []), fn (mixed $o): bool => is_array($o) && $disponible($o['campo'] ?? null)));
        $diseno = array_replace($this->disenoPorDefecto(), is_array($definicion['diseno'] ?? null) ? $definicion['diseno'] : []);
        $grafica = is_array($diseno['grafica'] ?? null) ? $diseno['grafica'] : [];
        $conGrafica = (bool) ($grafica['activa'] ?? false);
        $campoGrafica = $grafica['campo'] ?? null;
        $valorGrafica = $grafica['valor'] ?? 'conteo';

        if ($conGrafica && (! $disponible($campoGrafica) || ($valorGrafica !== 'conteo' && ! $disponible($valorGrafica)))) {
            $conGrafica = false;
            $avisos[] = 'La gráfica usa un campo que ya no puedes consultar y no se incluyó.';
        }

        // Solo se calculan los campos permitidos que el reporte necesita.
        $necesarios = array_unique([...$columnas, ...array_map(fn ($f) => (string) $f['campo'], $filtros), ...array_map(fn ($o) => (string) $o['campo'], $orden), ...($conGrafica ? array_filter([(string) $campoGrafica, $valorGrafica === 'conteo' ? '' : (string) $valorGrafica]) : [])]);

        $registros = [];

        foreach (($fuente['consulta'])($usuario) as $modelo) {
            $registro = [];

            foreach ($necesarios as $campo) {
                $registro[$campo] = $this->valorSeguro($campos[$campo]['valor'], $modelo, $campo, $clave);
            }

            if ($this->cumple($registro, $filtros)) {
                $registros[] = $registro;
            }
        }

        $this->ordenar($registros, $orden);

        $filas = array_map(fn (array $r): array => array_map(fn (string $c) => $r[$c] ?? null, $columnas), $registros);
        $columnasSalida = array_map(fn (string $c): array => ['clave' => $c, 'etiqueta' => $campos[$c]['etiqueta'], 'tipo' => $campos[$c]['tipo']], $columnas);

        return [
            'titulo' => is_string($diseno['titulo']) && trim($diseno['titulo']) !== '' ? trim($diseno['titulo']) : sprintf('Reporte de %s', mb_strtolower($fuente['etiqueta'])),
            'fuente' => $clave,
            'columnas' => $columnasSalida,
            'filas' => $filas,
            'total' => count($filas),
            'totales' => ($diseno['totales'] ?? false) ? $this->totales($columnasSalida, $filas) : null,
            'grafica' => $conGrafica ? $this->grafica($registros, (string) $campoGrafica, $valorGrafica === 'conteo' ? null : (string) $valorGrafica, $campos, (string) ($grafica['tipo'] ?? 'barras')) : null,
            'omitidas' => $omitidas,
            'avisos' => $avisos,
            'filtros_texto' => array_map(fn (array $f): string => $this->filtroATexto($f, $campos[(string) $f['campo']]['etiqueta']), $filtros),
            'diseno' => $diseno,
        ];
    }

    /**
     * @param  array<string, mixed>  $definicion
     *
     * @throws ValidationException
     */
    public function guardar(User $usuario, string $nombre, array $definicion, ?ReporteGuardado $reporte = null): ReporteGuardado
    {
        $normalizada = $this->normalizar($definicion, $usuario);
        $nombre = trim($nombre);

        $repetido = ReporteGuardado::query()
            ->where('user_id', $usuario->id)
            ->where('nombre', $nombre)
            ->when($reporte !== null, fn ($q) => $q->whereKeyNot($reporte?->id))
            ->exists();

        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => 'Ya tienes un reporte con ese nombre.']);
        }

        $reporte ??= new ReporteGuardado(['user_id' => $usuario->id]);
        $reporte->fill(['nombre' => $nombre, 'fuente' => $normalizada['fuente'], 'definicion' => $normalizada])->save();

        return $reporte;
    }

    public function duplicar(ReporteGuardado $reporte): ReporteGuardado
    {
        $base = sprintf('%s (copia)', mb_substr($reporte->nombre, 0, 100));
        $nombre = $base;
        $n = 2;

        while (ReporteGuardado::query()->where('user_id', $reporte->user_id)->where('nombre', $nombre)->exists()) {
            $nombre = sprintf('%s %d', $base, $n++);
        }

        return ReporteGuardado::query()->create([
            'user_id' => $reporte->user_id,
            'nombre' => $nombre,
            'fuente' => $reporte->fuente,
            'definicion' => $reporte->definicion,
        ]);
    }

    /**
     * Texto de una celda para pantalla y PDF: Excel recibe el valor crudo.
     *
     * @param  Celda  $valor
     */
    public static function formatear(string|int|float|null $valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($tipo) {
            'fecha' => CarbonImmutable::parse((string) $valor)->format('d/m/Y'),
            'moneda' => '$'.number_format((float) $valor, 2),
            'numero' => number_format((float) $valor, floor((float) $valor) === (float) $valor ? 0 : 2),
            default => (string) $valor,
        };
    }

    /**
     * @param  array<string, mixed>  $diseno
     * @param  array<string, mixed>  $campos
     * @return array<string, mixed>
     */
    private function normalizarDiseno(array $diseno, array $campos, User $usuario, callable $exigir): array
    {
        $base = $this->disenoPorDefecto();
        $colores = (array) config('reportes.colores', []);
        $texto = fn (string $clave, int $max): string => is_string($diseno[$clave] ?? null) ? mb_substr(trim($diseno[$clave]), 0, $max) : '';
        $entradaGrafica = is_array($diseno['grafica'] ?? null) ? $diseno['grafica'] : [];
        $activa = (bool) ($entradaGrafica['activa'] ?? false);
        $grafica = ['activa' => false, 'tipo' => 'barras', 'campo' => '', 'valor' => 'conteo'];

        if ($activa) {
            $valor = ($entradaGrafica['valor'] ?? 'conteo') === 'conteo' ? 'conteo' : $exigir($entradaGrafica['valor'] ?? null, 'diseno');

            if ($valor !== 'conteo' && ! in_array($campos[$valor]['tipo'] ?? '', ['numero', 'moneda'], true)) {
                throw ValidationException::withMessages(['diseno' => 'La gráfica solo puede sumar campos numéricos.']);
            }

            $grafica = [
                'activa' => true,
                'tipo' => in_array($entradaGrafica['tipo'] ?? null, ['barras', 'pastel', 'lineas'], true) ? $entradaGrafica['tipo'] : 'barras',
                'campo' => $exigir($entradaGrafica['campo'] ?? null, 'diseno'),
                'valor' => $valor,
            ];
        }

        return [
            'titulo' => $texto('titulo', 150),
            'subtitulo' => $texto('subtitulo', 200),
            'orientacion' => in_array($diseno['orientacion'] ?? null, ['landscape', 'portrait'], true) ? $diseno['orientacion'] : $base['orientacion'],
            'tamano' => in_array($diseno['tamano'] ?? null, ['letter', 'a4', 'legal'], true) ? $diseno['tamano'] : $base['tamano'],
            'color' => is_string($diseno['color'] ?? null) && isset($colores[$diseno['color']]) ? $diseno['color'] : $base['color'],
            'encabezado' => (bool) ($diseno['encabezado'] ?? true),
            'mostrar_filtros' => (bool) ($diseno['mostrar_filtros'] ?? true),
            'totales' => (bool) ($diseno['totales'] ?? false),
            'ajuste' => in_array($diseno['ajuste'] ?? null, ['compacto', 'normal', 'amplio'], true) ? $diseno['ajuste'] : 'normal',
            'grafica' => $grafica,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function valorFiltro(string $operador, mixed $valor, string $tipo, string $etiqueta): mixed
    {
        if (in_array($operador, ['vacio', 'no_vacio'], true)) {
            return null;
        }

        $falta = ValidationException::withMessages(['filtros' => sprintf('Completa el valor del filtro de «%s».', $etiqueta)]);

        if ($operador === 'en') {
            $lista = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 150) : '', is_array($valor) ? $valor : [$valor]), fn (string $v) => $v !== ''));

            return $lista === [] ? throw $falta : $lista;
        }

        $normaliza = function (mixed $v) use ($tipo, $falta, $etiqueta): string|float {
            if (! is_scalar($v) || trim((string) $v) === '') {
                throw $falta;
            }

            if ($tipo === 'fecha') {
                try {
                    return CarbonImmutable::parse((string) $v)->format('Y-m-d');
                } catch (Throwable) {
                    throw ValidationException::withMessages(['filtros' => sprintf('La fecha del filtro de «%s» no es válida.', $etiqueta)]);
                }
            }

            if ($tipo === 'numero' || $tipo === 'moneda') {
                if (! is_numeric($v)) {
                    throw ValidationException::withMessages(['filtros' => sprintf('El filtro de «%s» requiere un número.', $etiqueta)]);
                }

                return (float) $v;
            }

            return mb_substr(trim((string) $v), 0, 150);
        };

        if ($operador === 'entre') {
            $valores = is_array($valor) ? array_values($valor) : [];

            return [$normaliza($valores[0] ?? null), $normaliza($valores[1] ?? null)];
        }

        return $normaliza($valor);
    }

    /**
     * @param  array<string, mixed>  $registro
     * @param  list<mixed>  $filtros
     */
    private function cumple(array $registro, array $filtros): bool
    {
        foreach ($filtros as $filtro) {
            if (! is_array($filtro)) {
                continue;
            }

            $valor = $registro[(string) $filtro['campo']] ?? null;
            $vacio = $valor === null || $valor === '';
            $objetivo = $filtro['valor'] ?? null;
            $textoValor = mb_strtolower(is_scalar($valor) ? (string) $valor : '');

            $pasa = match ($filtro['operador'] ?? null) {
                'vacio' => $vacio,
                'no_vacio' => ! $vacio,
                'es' => $textoValor === mb_strtolower((string) $objetivo),
                'no_es' => $textoValor !== mb_strtolower((string) $objetivo),
                'contiene' => ! $vacio && str_contains($textoValor, mb_strtolower((string) $objetivo)),
                'en' => in_array($textoValor, array_map(fn ($v) => mb_strtolower((string) $v), (array) $objetivo), true),
                'igual' => ! $vacio && (float) $valor === (float) $objetivo,
                'mayor' => ! $vacio && (float) $valor > (float) $objetivo,
                'menor' => ! $vacio && (float) $valor < (float) $objetivo,
                'desde' => ! $vacio && (string) $valor >= (string) $objetivo,
                'hasta' => ! $vacio && (string) $valor <= (string) $objetivo,
                'entre' => ! $vacio && $this->dentroDeRango($valor, (array) $objetivo),
                default => false,
            };

            if (! $pasa) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $rango
     */
    private function dentroDeRango(mixed $valor, array $rango): bool
    {
        [$desde, $hasta] = [$rango[0] ?? null, $rango[1] ?? null];

        return is_numeric($desde)
            ? (float) $valor >= (float) $desde && (float) $valor <= (float) $hasta
            : (string) $valor >= (string) $desde && (string) $valor <= (string) $hasta;
    }

    /**
     * @param  list<array<string, mixed>>  $registros
     * @param  list<mixed>  $orden
     */
    private function ordenar(array &$registros, array $orden): void
    {
        if ($orden === []) {
            return;
        }

        usort($registros, function (array $a, array $b) use ($orden): int {
            foreach ($orden as $criterio) {
                $criterio = (array) $criterio;
                $campo = (string) ($criterio['campo'] ?? '');
                $x = $a[$campo] ?? null;
                $y = $b[$campo] ?? null;
                // Los vacíos siempre al final, sin importar la dirección.
                $comparacion = match (true) {
                    $x === $y => 0,
                    $x === null || $x === '' => 1,
                    $y === null || $y === '' => -1,
                    is_string($x) && is_string($y) => ($criterio['direccion'] ?? 'asc') === 'desc' ? strcoll($y, $x) : strcoll($x, $y),
                    default => ($criterio['direccion'] ?? 'asc') === 'desc' ? $y <=> $x : $x <=> $y,
                };

                if ($comparacion !== 0) {
                    return $comparacion;
                }
            }

            return 0;
        });
    }

    /**
     * @param  list<array{clave: string, etiqueta: string, tipo: string}>  $columnas
     * @param  array<int, array<int, mixed>>  $filas
     * @return list<string|int|float|null>
     */
    private function totales(array $columnas, array $filas): array
    {
        $totales = [];

        foreach ($columnas as $i => $columna) {
            $totales[] = in_array($columna['tipo'], ['numero', 'moneda'], true)
                ? array_sum(array_map(fn (array $f): float => (float) ($f[$i] ?? 0), $filas))
                : null;
        }

        if ($totales !== [] && $totales[0] === null) {
            $totales[0] = sprintf('Total: %d', count($filas));
        }

        return $totales;
    }

    /**
     * @param  list<array<string, mixed>>  $registros
     * @param  array<string, mixed>  $campos
     * @return array{tipo: string, titulo: string, nombre: string, categorias: list<string>, valores: list<float>}
     */
    private function grafica(array $registros, string $campo, ?string $campoValor, array $campos, string $tipo): array
    {
        $tipoCampo = (string) ($campos[$campo]['tipo'] ?? 'texto');
        $acumulado = [];

        foreach ($registros as $registro) {
            $categoria = self::formatear($registro[$campo] ?? null, $tipoCampo);
            $categoria = $categoria === '—' ? 'Sin dato' : $categoria;
            $acumulado[$categoria] = ($acumulado[$categoria] ?? 0.0) + ($campoValor === null ? 1.0 : (float) ($registro[$campoValor] ?? 0));
        }

        if ($tipo === 'lineas') {
            ksort($acumulado);
        } else {
            arsort($acumulado);
        }

        if (count($acumulado) > self::MAX_CATEGORIAS_GRAFICA && $tipo !== 'lineas') {
            $principales = array_slice($acumulado, 0, self::MAX_CATEGORIAS_GRAFICA - 1, true);
            $principales['Otros'] = array_sum(array_slice($acumulado, self::MAX_CATEGORIAS_GRAFICA - 1));
            $acumulado = $principales;
        }

        $etiquetaCampo = (string) ($campos[$campo]['etiqueta'] ?? '');
        $nombre = $campoValor === null ? 'Registros' : (string) ($campos[$campoValor]['etiqueta'] ?? '');

        return [
            'tipo' => $tipo,
            'titulo' => sprintf('%s por %s', $nombre, mb_strtolower($etiquetaCampo)),
            'nombre' => $nombre,
            'categorias' => array_map('strval', array_keys($acumulado)),
            'valores' => array_values($acumulado),
        ];
    }

    /**
     * @param  array<mixed>  $filtro
     */
    private function filtroATexto(array $filtro, string $etiqueta): string
    {
        $operador = (string) ($filtro['operador'] ?? '');
        $texto = sprintf('%s %s', $etiqueta, self::ETIQUETAS_OPERADOR[$operador] ?? '');
        $valor = $filtro['valor'] ?? null;

        if ($valor === null) {
            return $texto;
        }

        $formatea = fn (mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? CarbonImmutable::parse($v)->format('d/m/Y') : (is_scalar($v) ? (string) $v : '');

        if ($operador === 'entre') {
            $rango = array_values((array) $valor);

            return sprintf('%s %s y %s', $texto, $formatea($rango[0] ?? null), $formatea($rango[1] ?? null));
        }

        return sprintf('%s %s', $texto, implode(', ', array_map($formatea, (array) $valor)));
    }

    /**
     * Un dato inconsistente en un renglón no tumba el reporte: queda vacío y se
     * registra para corregirlo.
     */
    private function valorSeguro(callable $valor, Model $modelo, string $campo, string $fuente): string|int|float|null
    {
        try {
            $resultado = $valor($modelo);

            return is_scalar($resultado) && ! is_bool($resultado) ? $resultado : null;
        } catch (Throwable $e) {
            Log::warning('Mis reportes: no se pudo leer un campo.', ['fuente' => $fuente, 'campo' => $campo, 'id' => $modelo->getKey(), 'error' => $e::class]);

            return null;
        }
    }

    /**
     * @return list<mixed>
     */
    private function lista(mixed $valor): array
    {
        return is_array($valor) ? array_values($valor) : [];
    }
}
