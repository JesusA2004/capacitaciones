<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Enums\MetodoLecturaPagina;
use Illuminate\Support\Str;

/**
 * Clasifica cada página del PDF histórico contra los tipos del checklist con
 * las reglas de config('expedientes.division_historico.reglas') y agrupa las
 * páginas en partes. Determinístico y auditable: cada página guarda su
 * puntaje, las frases que coincidieron y el motivo cuando no se clasificó.
 *
 * Puntaje: frase fuerte = 3 (+2 si está en el encabezado de la página),
 * frase débil = 1. Gana el tipo con más puntos si llega a `puntaje_minimo`;
 * un empate entre dos tipos queda «sin clasificar» (ambigua) — nunca se
 * elige uno al azar.
 *
 * @phpstan-type PaginaLeida array{pagina: int, texto: string, metodo: MetodoLecturaPagina}
 * @phpstan-type PaginaClasificada array{pagina: int, tipo: string|null, sugerido: string|null, confianza: string|null, puntaje: int, coincidencias: list<string>, metodo: string, motivo: string|null, manual?: bool, tipo_ocr?: string|null}
 * @phpstan-type Parte array{tipo: string|null, paginas: list<int>, confianza: string|null}
 */
class ClasificadorPaginas
{
    /** Caracteres iniciales de la página que cuentan como «encabezado». */
    private const ENCABEZADO = 400;

    private const ORDEN_CONFIANZA = ['baja' => 1, 'media' => 2, 'alta' => 3];

    /**
     * @param  list<PaginaLeida>  $paginas
     * @param  list<string>  $tiposPermitidos  Claves del checklist activo (las reglas de otros tipos se ignoran).
     * @return list<PaginaClasificada>
     */
    public function clasificar(array $paginas, array $tiposPermitidos): array
    {
        $reglas = array_intersect_key((array) config('expedientes.division_historico.reglas', []), array_flip($tiposPermitidos));
        $resultado = [];
        $anterior = null;

        foreach ($paginas as $pagina) {
            $clasificada = $this->clasificarPagina($pagina, $reglas);

            // Continuación: hoja con texto pero sin coincidencias justo
            // después de un documento de varias hojas (contrato, solicitud…).
            if ($clasificada['tipo'] === null && $anterior !== null && ($clasificada['puntaje'] === 0 || $clasificada['sugerido'] === $anterior['tipo'])
                && $anterior['tipo'] !== null && $pagina['metodo'] !== MetodoLecturaPagina::SinTexto
                && trim($pagina['texto']) !== '' && (bool) ($reglas[$anterior['tipo']]['continuacion'] ?? false)) {
                $clasificada['tipo'] = $anterior['tipo'];
                $clasificada['confianza'] = 'baja';
                $clasificada['motivo'] = sprintf('Continuación de la página %d.', $anterior['pagina']);
            }

            $resultado[] = $clasificada;
            $anterior = $clasificada;
        }

        return $resultado;
    }

    /**
     * Partes a generar: todas las páginas de un mismo tipo forman UNA parte
     * (en orden de página, aunque no sean consecutivas); las páginas sin
     * clasificar forman una parte por cada tramo consecutivo, para que RH
     * pueda asignar cada tramo a un tipo distinto.
     *
     * @param  list<PaginaClasificada>  $paginas
     * @return list<Parte>
     */
    public function agrupar(array $paginas): array
    {
        $porTipo = [];
        $sinClasificar = [];
        $tramo = [];

        foreach ($paginas as $pagina) {
            if ($pagina['tipo'] === null) {
                $tramo[] = $pagina['pagina'];

                continue;
            }

            if ($tramo !== []) {
                $sinClasificar[] = $tramo;
                $tramo = [];
            }

            $tipo = $pagina['tipo'];
            $porTipo[$tipo] ??= ['tipo' => $tipo, 'paginas' => [], 'confianza' => $pagina['confianza']];
            $porTipo[$tipo]['paginas'][] = $pagina['pagina'];
            $porTipo[$tipo]['confianza'] = $this->menorConfianza($porTipo[$tipo]['confianza'], $pagina['confianza']);
        }

        if ($tramo !== []) {
            $sinClasificar[] = $tramo;
        }

        $partes = array_values($porTipo);

        foreach ($sinClasificar as $paginasTramo) {
            $partes[] = ['tipo' => null, 'paginas' => $paginasTramo, 'confianza' => null];
        }

        usort($partes, fn (array $a, array $b): int => $a['paginas'][0] <=> $b['paginas'][0]);

        return $partes;
    }

    /**
     * Mayúsculas, sin acentos, sin signos y con espacios simples (la misma
     * forma para el texto y para las frases de las reglas).
     */
    public function normalizar(string $texto): string
    {
        $texto = Str::upper(Str::ascii($texto));
        $texto = preg_replace('/[^A-Z0-9]+/', ' ', $texto) ?? $texto;

        return trim($texto);
    }

    /**
     * @param  PaginaLeida  $pagina
     * @param  array<string, mixed>  $reglas
     * @return PaginaClasificada
     */
    private function clasificarPagina(array $pagina, array $reglas): array
    {
        $base = [
            'pagina' => $pagina['pagina'],
            'tipo' => null,
            'confianza' => null,
            'puntaje' => 0,
            'coincidencias' => [],
            'metodo' => $pagina['metodo']->value,
            'sugerido' => null,
            'motivo' => null,
        ];

        $texto = $this->normalizar($pagina['texto']);

        if ($texto === '') {
            $base['motivo'] = $pagina['metodo'] === MetodoLecturaPagina::SinTexto
                ? 'Página escaneada sin OCR disponible.'
                : 'La página no tiene texto legible (¿fotografía?).';

            return $base;
        }

        $encabezado = substr($texto, 0, self::ENCABEZADO);
        $puntajes = [];
        $coincidencias = [];

        foreach ($reglas as $tipo => $regla) {
            $puntos = 0;
            $frases = [];

            foreach ((array) ($regla['fuertes'] ?? []) as $frase) {
                $frase = $this->normalizar((string) $frase);

                if ($frase !== '' && $this->contiene($texto, $frase)) {
                    $puntos += 3 + ($this->contiene($encabezado, $frase) ? 2 : 0);
                    $frases[] = $frase;
                }
            }

            foreach ((array) ($regla['debiles'] ?? []) as $frase) {
                $frase = $this->normalizar((string) $frase);

                if ($frase !== '' && $this->contiene($texto, $frase)) {
                    $puntos++;
                    $frases[] = $frase;
                }
            }

            if ($puntos > 0) {
                $puntajes[(string) $tipo] = $puntos;
                $coincidencias[(string) $tipo] = $frases;
            }
        }

        if ($puntajes === []) {
            $base['motivo'] = 'Ninguna regla del checklist coincidió con el texto.';

            return $base;
        }

        arsort($puntajes);
        $tipos = array_keys($puntajes);
        $ganador = $tipos[0];
        $puntaje = $puntajes[$ganador];
        $segundo = isset($tipos[1]) ? $puntajes[$tipos[1]] : 0;
        $minimo = max(1, (int) config('expedientes.division_historico.puntaje_minimo', 2));

        $base['puntaje'] = $puntaje;
        $base['coincidencias'] = $coincidencias[$ganador];
        $base['sugerido'] = $ganador;

        if ($puntaje < $minimo) {
            $base['motivo'] = sprintf('Coincidencias insuficientes (%d de %d puntos).', $puntaje, $minimo);

            return $base;
        }

        if ($segundo === $puntaje) {
            $base['motivo'] = sprintf('Ambigua: coincide igual con «%s» y «%s».', $ganador, $tipos[1]);

            return $base;
        }

        $base['tipo'] = $ganador;
        $base['confianza'] = match (true) {
            $puntaje - $segundo <= 1 => 'baja',
            $puntaje >= 5 => 'alta',
            default => 'media',
        };

        return $base;
    }

    private function contiene(string $texto, string $frase): bool
    {
        return preg_match('/(?<![A-Z0-9])'.preg_quote($frase, '/').'(?![A-Z0-9])/', $texto) === 1;
    }

    private function menorConfianza(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return self::ORDEN_CONFIANZA[$a] <= self::ORDEN_CONFIANZA[$b] ? $a : $b;
    }
}
