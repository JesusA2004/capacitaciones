<?php

namespace App\Services\Formatos\Variables;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Convierte el valor crudo de una variable (fecha, monto, número, texto)
 * al texto que se imprime según el "formato" elegido en el campo de la
 * plantilla. Centraliza fechas en letra, montos en letra, mayúsculas, etc.
 * para que ninguna plantilla/servicio lo reimplemente.
 */
class FormateadorValores
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly NumeroALetras $letras) {}

    /**
     * @param  'texto'|'fecha'|'moneda'|'numero'|'imagen'  $tipo
     */
    public function formatear(mixed $valor, string $tipo, ?string $formato = null): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($tipo) {
            'fecha' => $this->fecha($valor, $formato ?? 'corta'),
            'moneda' => $this->moneda((float) $valor, $formato ?? 'moneda'),
            'numero' => $this->numero($valor, $formato ?? 'numero'),
            'imagen' => '',
            default => $this->texto((string) $valor, $formato ?? 'normal'),
        };
    }

    public function fechaLarga(CarbonInterface $fecha): string
    {
        return sprintf('%d de %s de %d', $fecha->day, self::MESES[$fecha->month - 1], $fecha->year);
    }

    private function fecha(mixed $valor, string $formato): string
    {
        $fecha = $valor instanceof CarbonInterface ? $valor : Carbon::parse((string) $valor);

        return match ($formato) {
            'larga' => $this->fechaLarga($fecha),
            'letra' => sprintf(
                '%s de %s de %s',
                $fecha->day === 1 ? 'primero' : $this->letras->entero($fecha->day),
                self::MESES[$fecha->month - 1],
                $this->letras->entero($fecha->year),
            ),
            'dia' => sprintf('%d', $fecha->day),
            'mes' => self::MESES[$fecha->month - 1],
            'anio' => sprintf('%d', $fecha->year),
            default => $fecha->format('d/m/Y'),
        };
    }

    private function moneda(float $monto, string $formato): string
    {
        return match ($formato) {
            'moneda_letra' => $this->letras->moneda($monto),
            'numero' => number_format($monto, 2),
            default => sprintf('$%s', number_format($monto, 2)),
        };
    }

    private function numero(mixed $valor, string $formato): string
    {
        if ($formato === 'letra' && is_numeric($valor)) {
            return $this->letras->entero((int) $valor);
        }

        return (string) $valor;
    }

    private function texto(string $valor, string $formato): string
    {
        return match ($formato) {
            'mayusculas' => mb_strtoupper($valor),
            'minusculas' => mb_strtolower($valor),
            'titulo' => mb_convert_case(mb_strtolower($valor), MB_CASE_TITLE),
            default => $valor,
        };
    }
}
