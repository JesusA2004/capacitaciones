<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\TipoPreguntaTecnica;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Estructura de un formulario técnico («Seguimiento N»), libre como Google
 * Forms: secciones → preguntas de varios tipos, con respuesta correcta y
 * puntaje por pregunta. La usa FormularioEvaluacionService para la clave
 * «tecnica» (normalizar / problemas / métricas) y EvaluacionTecnicaService
 * para calificar.
 *
 * Forma guardada (definicion JSON):
 *   calificacion_aprobatoria  0-100 (sugerida; el paso del calendario manda)
 *   mostrar_resultado         ¿quien contesta ve su calificación al enviar?
 *   secciones[]: clave, titulo, descripcion, preguntas[]:
 *     clave, tipo (TipoPreguntaTecnica), enunciado, ayuda, obligatoria,
 *     puntos (0 = no se califica), opciones[{clave, texto}], correctas[clave],
 *     escala_min, escala_max, etiqueta_min, etiqueta_max
 *
 * Las respuestas correctas NUNCA salen a quien contesta (contenidoPublico()).
 */
class DefinicionFormularioTecnicoService
{
    private const MAX_SECCIONES = 20;

    private const MAX_PREGUNTAS = 80;

    private const MAX_OPCIONES = 12;

    private const MAX_PUNTOS = 100;

    /**
     * Definición con la que nace una plantilla nueva: una sección con una
     * pregunta de ejemplo, lista para editar.
     *
     * @return array<string, mixed>
     */
    public function porDefecto(): array
    {
        return $this->normalizar([
            'calificacion_aprobatoria' => 80,
            'mostrar_resultado' => true,
            'secciones' => [[
                'clave' => 'seccion_1',
                'titulo' => 'Conocimientos del puesto',
                'descripcion' => 'Responde con lo que aprendiste en tu operación diaria.',
                'preguntas' => [[
                    'clave' => 'pregunta_1',
                    'tipo' => TipoPreguntaTecnica::OpcionMultiple->value,
                    'enunciado' => 'Escribe aquí tu primera pregunta',
                    'obligatoria' => true,
                    'puntos' => 10,
                    'opciones' => [['clave' => 'o1', 'texto' => 'Opción 1'], ['clave' => 'o2', 'texto' => 'Opción 2']],
                    'correctas' => ['o1'],
                ]],
            ]],
        ]);
    }

    /**
     * Limpia la estructura (nunca guarda tipos ni llaves inventadas) y
     * agrega los totales calculados.
     *
     * @param  array<mixed>  $d
     * @return array<string, mixed>
     */
    public function normalizar(array $d): array
    {
        $secciones = [];
        $clavesSeccion = [];
        $clavesPregunta = [];
        $totalPreguntas = 0;
        $totalPuntos = 0;

        foreach (array_slice($this->lista($d['secciones'] ?? []), 0, self::MAX_SECCIONES) as $i => $s) {
            $preguntas = [];

            foreach ($this->lista($s['preguntas'] ?? []) as $j => $p) {
                if ($totalPreguntas >= self::MAX_PREGUNTAS) {
                    break;
                }

                $pregunta = $this->normalizarPregunta($p, sprintf('pregunta_%d_%d', $i + 1, $j + 1), $clavesPregunta);
                $preguntas[] = $pregunta;
                $totalPreguntas++;
                $totalPuntos += $this->puntosMaximos($pregunta);
            }

            $secciones[] = [
                'clave' => $this->claveUnica($s['clave'] ?? '', sprintf('seccion_%d', $i + 1), $clavesSeccion),
                'titulo' => $this->texto($s['titulo'] ?? '', 200),
                'descripcion' => $this->texto($s['descripcion'] ?? '', 2000),
                'preguntas' => $preguntas,
            ];
        }

        $aprobatoria = is_numeric($d['calificacion_aprobatoria'] ?? null) ? (float) $d['calificacion_aprobatoria'] : 80.0;

        return [
            'calificacion_aprobatoria' => max(0, min(100, round($aprobatoria, 2))),
            'mostrar_resultado' => filter_var($d['mostrar_resultado'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'secciones' => $secciones,
            'total_preguntas' => $totalPreguntas,
            'total_puntos' => $totalPuntos,
        ];
    }

    /**
     * Lo que impide publicar, en lenguaje de RH. [] = listo.
     *
     * @param  array<string, mixed>  $d  definición normalizada
     * @return list<string>
     */
    public function problemas(array $d): array
    {
        $problemas = [];
        /** @var list<array<string, mixed>> $secciones */
        $secciones = $d['secciones'];

        if ($secciones === []) {
            return ['Agrega al menos una sección con preguntas.'];
        }

        foreach ($secciones as $i => $s) {
            $nombreSeccion = $s['titulo'] !== '' ? sprintf('«%s»', $s['titulo']) : sprintf('%d', $i + 1);

            if ($s['titulo'] === '') {
                $problemas[] = sprintf('La sección %d necesita título.', $i + 1);
            }

            /** @var list<array<string, mixed>> $preguntas */
            $preguntas = $s['preguntas'];

            if ($preguntas === []) {
                $problemas[] = sprintf('La sección %s no tiene preguntas.', $nombreSeccion);
            }

            foreach ($preguntas as $j => $p) {
                $ubicacion = sprintf('Sección %s, pregunta %d', $nombreSeccion, $j + 1);
                $tipo = TipoPreguntaTecnica::from((string) $p['tipo']);
                /** @var list<array{clave: string, texto: string}> $opciones */
                $opciones = $p['opciones'];
                /** @var list<string> $correctas */
                $correctas = $p['correctas'];

                if ($p['enunciado'] === '') {
                    $problemas[] = sprintf('%s: escribe la pregunta.', $ubicacion);
                }

                if ($tipo->tieneOpciones()) {
                    if (count($opciones) < 2) {
                        $problemas[] = sprintf('%s: necesita al menos dos opciones.', $ubicacion);
                    }

                    if (collect($opciones)->contains(fn (array $o): bool => $o['texto'] === '')) {
                        $problemas[] = sprintf('%s: hay una opción vacía.', $ubicacion);
                    }
                }

                if (((int) $p['puntos']) > 0 && in_array($tipo, [TipoPreguntaTecnica::OpcionMultiple, TipoPreguntaTecnica::Casillas, TipoPreguntaTecnica::VerdaderoFalso], true) && $correctas === []) {
                    $problemas[] = sprintf('%s: marca la respuesta correcta (o deja la pregunta en 0 puntos).', $ubicacion);
                }
            }
        }

        if ($problemas === [] && (int) $d['total_puntos'] === 0) {
            $problemas[] = 'Ninguna pregunta da puntos: asigna puntaje al menos a una para poder calificar.';
        }

        return $problemas;
    }

    /**
     * @param  array<string, mixed>  $d  definición normalizada
     * @return array{preguntas: int, total_puntos: int, aprobatoria: float}
     */
    public function metricas(array $d): array
    {
        return [
            'preguntas' => (int) $d['total_preguntas'],
            'total_puntos' => (int) $d['total_puntos'],
            'aprobatoria' => (float) $d['calificacion_aprobatoria'],
        ];
    }

    /**
     * Lo único que llega a quien contesta: sin respuestas correctas.
     *
     * @param  array<string, mixed>  $d  definición normalizada
     * @return array<string, mixed>
     */
    public function contenidoPublico(array $d): array
    {
        /** @var list<array<string, mixed>> $secciones */
        $secciones = $d['secciones'];

        return [
            'mostrar_resultado' => $d['mostrar_resultado'],
            'total_puntos' => $d['total_puntos'],
            'total_preguntas' => $d['total_preguntas'],
            'secciones' => array_map(fn (array $s): array => [
                ...$s,
                'preguntas' => array_map(function (array $p): array {
                    unset($p['correctas']);

                    return $p;
                }, (array) $s['preguntas']),
            ], $secciones),
        ];
    }

    /**
     * Califica las respuestas contra la definición. Exige las preguntas
     * obligatorias. Calificación 0-100 sobre las preguntas que dan puntos
     * (null si ninguna da puntos).
     *
     * @param  array<string, mixed>  $d  definición normalizada
     * @param  array<mixed>  $respuestas  pregunta => string | list<string> | int
     * @return array{respuestas: array<string, mixed>, puntos_obtenidos: float, puntos_totales: float, calificacion: float|null, detalle: array<string, array{obtenidos: float, maximos: float, correcta: bool|null}>}
     */
    public function calificar(array $d, array $respuestas): array
    {
        $limpias = [];
        $detalle = [];
        $faltantes = [];
        $obtenidos = 0.0;
        $totales = 0.0;

        /** @var list<array<string, mixed>> $secciones */
        $secciones = $d['secciones'];

        foreach ($secciones as $s) {
            foreach ((array) $s['preguntas'] as $p) {
                /** @var array<string, mixed> $p */
                $clave = (string) $p['clave'];
                $tipo = TipoPreguntaTecnica::from((string) $p['tipo']);
                $valor = $this->respuestaLimpia($tipo, $p, $respuestas[$clave] ?? null);

                if ($valor === null) {
                    if ($p['obligatoria'] === true) {
                        $faltantes[] = (string) $p['enunciado'];
                    }
                } else {
                    $limpias[$clave] = $valor;
                }

                $maximos = (float) $this->puntosMaximos($p);

                if ($maximos <= 0) {
                    continue;
                }

                $puntos = $valor === null ? 0.0 : $this->puntosDe($tipo, $p, $valor);
                $obtenidos += $puntos;
                $totales += $maximos;
                $detalle[$clave] = [
                    'obtenidos' => round($puntos, 2),
                    'maximos' => $maximos,
                    'correcta' => $tipo === TipoPreguntaTecnica::Escala ? null : $puntos >= $maximos,
                ];
            }
        }

        if ($faltantes !== []) {
            throw ValidationException::withMessages([
                'respuestas' => sprintf('Faltan %d %s obligatoria%s: %s', count($faltantes), count($faltantes) === 1 ? 'pregunta' : 'preguntas', count($faltantes) === 1 ? '' : 's', Str::limit(implode(' · ', $faltantes), 300)),
            ]);
        }

        return [
            'respuestas' => $limpias,
            'puntos_obtenidos' => round($obtenidos, 2),
            'puntos_totales' => round($totales, 2),
            'calificacion' => $totales > 0 ? round($obtenidos / $totales * 100, 2) : null,
            'detalle' => $detalle,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public function puntosMaximos(array $p): int
    {
        $tipo = TipoPreguntaTecnica::tryFrom((string) ($p['tipo'] ?? ''));

        return $tipo === null || $tipo->esAbierta() ? 0 : (int) ($p['puntos'] ?? 0);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<mixed>  $p
     * @param  list<string>  $usadas
     * @return array<string, mixed>
     */
    private function normalizarPregunta(array $p, string $porDefecto, array &$usadas): array
    {
        $tipo = TipoPreguntaTecnica::tryFrom((string) ($p['tipo'] ?? '')) ?? TipoPreguntaTecnica::OpcionMultiple;
        $opciones = [];

        if ($tipo === TipoPreguntaTecnica::VerdaderoFalso) {
            $opciones = [['clave' => 'verdadero', 'texto' => 'Verdadero'], ['clave' => 'falso', 'texto' => 'Falso']];
        } elseif ($tipo->tieneOpciones()) {
            $clavesOpcion = [];

            foreach (array_slice($this->lista($p['opciones'] ?? []), 0, self::MAX_OPCIONES) as $k => $o) {
                $opciones[] = [
                    'clave' => $this->claveUnica($o['clave'] ?? '', sprintf('o%d', $k + 1), $clavesOpcion),
                    'texto' => $this->texto($o['texto'] ?? '', 500),
                ];
            }
        }

        $validas = array_column($opciones, 'clave');
        $correctas = array_values(array_unique(array_filter(
            array_map(fn (mixed $c): string => is_scalar($c) ? (string) $c : '', is_array($p['correctas'] ?? null) ? $p['correctas'] : []),
            fn (string $c): bool => in_array($c, $validas, true),
        )));

        if ($tipo !== TipoPreguntaTecnica::Casillas) {
            $correctas = array_slice($correctas, 0, 1);
        }

        $min = is_numeric($p['escala_min'] ?? null) ? max(0, min(1, (int) $p['escala_min'])) : 1;
        $max = is_numeric($p['escala_max'] ?? null) ? max(2, min(10, (int) $p['escala_max'])) : 5;

        return [
            'clave' => $this->claveUnica($p['clave'] ?? '', $porDefecto, $usadas),
            'tipo' => $tipo->value,
            'enunciado' => $this->texto($p['enunciado'] ?? '', 1000),
            'ayuda' => $this->texto($p['ayuda'] ?? '', 1000),
            'obligatoria' => filter_var($p['obligatoria'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'puntos' => $tipo->esAbierta() ? 0 : (is_numeric($p['puntos'] ?? null) ? max(0, min(self::MAX_PUNTOS, (int) $p['puntos'])) : 1),
            'opciones' => $opciones,
            'correctas' => $tipo->tieneOpciones() || $tipo === TipoPreguntaTecnica::VerdaderoFalso ? $correctas : [],
            'escala_min' => $min,
            'escala_max' => $max,
            'etiqueta_min' => $this->texto($p['etiqueta_min'] ?? '', 60),
            'etiqueta_max' => $this->texto($p['etiqueta_max'] ?? '', 60),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return string|list<string>|int|null
     */
    private function respuestaLimpia(TipoPreguntaTecnica $tipo, array $p, mixed $valor): string|array|int|null
    {
        /** @var list<array{clave: string, texto: string}> $opciones */
        $opciones = $p['opciones'];
        $validas = array_column($opciones, 'clave');

        return match ($tipo) {
            TipoPreguntaTecnica::OpcionMultiple, TipoPreguntaTecnica::VerdaderoFalso => is_scalar($valor) && in_array((string) $valor, $validas, true) ? (string) $valor : null,
            TipoPreguntaTecnica::Casillas => (function () use ($valor, $validas): ?array {
                $elegidas = array_values(array_unique(array_filter(
                    array_map(fn (mixed $c): string => is_scalar($c) ? (string) $c : '', is_array($valor) ? $valor : []),
                    fn (string $c): bool => in_array($c, $validas, true),
                )));

                return $elegidas === [] ? null : $elegidas;
            })(),
            TipoPreguntaTecnica::Escala => is_numeric($valor) && (int) $valor >= (int) $p['escala_min'] && (int) $valor <= (int) $p['escala_max'] ? (int) $valor : null,
            TipoPreguntaTecnica::RespuestaCorta, TipoPreguntaTecnica::Parrafo => is_scalar($valor) && trim((string) $valor) !== ''
                ? Str::limit(trim((string) $valor), $tipo === TipoPreguntaTecnica::Parrafo ? 5000 : 500, '')
                : null,
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  string|list<string>|int  $valor
     */
    private function puntosDe(TipoPreguntaTecnica $tipo, array $p, string|array|int $valor): float
    {
        $maximos = (float) $this->puntosMaximos($p);
        /** @var list<string> $correctas */
        $correctas = $p['correctas'];

        return match ($tipo) {
            TipoPreguntaTecnica::OpcionMultiple, TipoPreguntaTecnica::VerdaderoFalso => $correctas !== [] && $valor === $correctas[0] ? $maximos : 0.0,
            TipoPreguntaTecnica::Casillas => (function () use ($valor, $correctas, $maximos): float {
                $elegidas = is_array($valor) ? $valor : [];
                sort($elegidas);
                $esperadas = $correctas;
                sort($esperadas);

                return $esperadas !== [] && $elegidas === $esperadas ? $maximos : 0.0;
            })(),
            TipoPreguntaTecnica::Escala => (function () use ($valor, $p, $maximos): float {
                $min = (int) $p['escala_min'];
                $max = (int) $p['escala_max'];

                return $max > $min ? $maximos * (((int) $valor) - $min) / ($max - $min) : 0.0;
            })(),
            TipoPreguntaTecnica::RespuestaCorta, TipoPreguntaTecnica::Parrafo => 0.0,
        };
    }

    /**
     * @return list<array<mixed>>
     */
    private function lista(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, 'is_array'));
    }

    private function texto(mixed $valor, int $maximo): string
    {
        return is_scalar($valor) ? Str::limit(trim((string) $valor), $maximo, '') : '';
    }

    /**
     * Clave estable (a-z, 0-9, _) y única dentro de su lista.
     *
     * @param  list<string>  $usadas
     */
    private function claveUnica(mixed $propuesta, string $porDefecto, array &$usadas): string
    {
        $clave = is_string($propuesta) ? strtolower(trim($propuesta)) : '';

        if (preg_match('/^[a-z0-9_]{1,60}$/', $clave) !== 1) {
            $clave = $porDefecto;
        }

        $base = $clave;
        $n = 2;

        while (in_array($clave, $usadas, true)) {
            $clave = sprintf('%s_%d', $base, $n++);
        }

        $usadas[] = $clave;

        return $clave;
    }
}
