<?php

namespace App\Services\Formatos\Analisis;

use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use Illuminate\Support\Str;

/**
 * Convierte texto detectado en SUGERENCIAS de campos ("Este texto parece
 * corresponder a colaborador.curp"). Nunca decide por RH: cada sugerencia
 * lleva confianza y estado (seguro/dudoso) y se confirma en el editor.
 *
 * Coincidencia por sinónimos del catálogo (sin acentos, sin mayúsculas,
 * sin ":" ni guiones bajos de relleno). Los sinónimos genéricos ("área",
 * "fecha", "centro de trabajo"…) siempre salen como "dudoso".
 *
 * @phpstan-import-type Bloque from AnalizadorPlantilla
 *
 * @phpstan-type Sugerencia array{id: string, etiqueta_detectada: string, variable: string, etiqueta_variable: string, pagina: int, x: float, y: float, ancho: float, alto: float, font_size: float, confianza: float, estado: 'seguro'|'dudoso'}
 */
class DetectorCampos
{
    /**
     * Sinónimos demasiado genéricos para asumirlos sin revisión.
     */
    private const AMBIGUOS = [
        'area', 'area de trabajo', 'centro de trabajo', 'fecha', 'categoria', 'lugar', 'estado', 'ciudad', 'monto',
        'a los', 'del dia', 'al dia', 'oficina', 'plaza', 'cargo', 'ingreso', 'importe', 'pago', 'descuento', 'abono',
        'municipio', 'la empresa', 'empresa', 'causa', 'razon', 'supervisor', 'imss', 'nombre', 'dias', 'saldo',
        'folio', 'referencia', 'estatus', 'comentarios', 'sueldo', 'salario', 'gerente', 'cantidad de',
    ];

    public function __construct(private readonly CatalogoVariablesFormato $catalogo) {}

    /**
     * @param  list<Bloque>  $bloques
     * @param  list<array{numero: int, ancho: float, alto: float}>  $paginas
     * @return list<Sugerencia>
     */
    public function detectar(array $bloques, array $paginas): array
    {
        $indice = $this->indiceSinonimos();
        $anchoPagina = [];

        foreach ($paginas as $pagina) {
            $anchoPagina[$pagina['numero']] = $pagina['ancho'];
        }

        $sugerencias = [];

        foreach ($bloques as $bloque) {
            [$etiqueta, $resto] = $this->separarEtiqueta($bloque['texto']);
            $normal = $this->normalizar($etiqueta);

            if ($normal === '' || str_starts_with($normal, 'firma')) {
                continue;
            }

            $coincidencia = $this->mejorCoincidencia($normal, $indice);

            if ($coincidencia === null) {
                continue;
            }

            [$sinonimo, $variable, $exacta] = $coincidencia;
            $definicion = $this->catalogo->definicion($variable);

            if ($definicion === null || $definicion['tipo'] === 'imagen') {
                continue;
            }

            $confianza = $exacta ? 0.92 : 0.65;

            if (in_array($sinonimo, self::AMBIGUOS, true)) {
                $confianza = min($confianza, 0.55);
            }

            // Dónde escribir: sobre la raya "_____" si el bloque la trae;
            // si no, a la derecha de la etiqueta.
            $proporcion = mb_strlen($bloque['texto']) > 0 ? mb_strlen($etiqueta) / mb_strlen($bloque['texto']) : 1.0;
            $x = $resto !== ''
                ? $bloque['x'] + $bloque['ancho'] * $proporcion + 1
                : $bloque['x'] + $bloque['ancho'] + 2;
            $limite = ($anchoPagina[$bloque['pagina']] ?? 215.9) - 10;
            $x = min($x, $limite - 20);
            $alto = max(4.0, min(8.0, $bloque['alto']));

            $sugerencias[] = [
                'id' => Str::lower(Str::random(10)),
                'etiqueta_detectada' => $etiqueta,
                'variable' => $variable,
                'etiqueta_variable' => $definicion['etiqueta'],
                'pagina' => $bloque['pagina'],
                'x' => round($x, 2),
                'y' => round($bloque['y'], 2),
                'ancho' => round(max(20.0, min(100.0, $limite - $x)), 2),
                'alto' => round($alto, 2),
                'font_size' => round(max(7.0, min(12.0, $alto / 0.45)), 1),
                'confianza' => $confianza,
                'estado' => $confianza >= 0.8 ? 'seguro' : 'dudoso',
            ];
        }

        return $sugerencias;
    }

    public function normalizar(string $texto): string
    {
        $texto = Str::lower(Str::ascii($texto));
        $texto = (string) preg_replace('/[_:;]+/', ' ', $texto);
        $texto = (string) preg_replace('/\s+/', ' ', $texto);

        return trim($texto, " .-\t\n\r\0\x0B");
    }

    /**
     * "NOMBRE: ________" → ["NOMBRE:", "________"]; "CURP" → ["CURP", ""].
     *
     * @return array{0: string, 1: string}
     */
    private function separarEtiqueta(string $texto): array
    {
        if (preg_match('/^(.*?[^_\s])\s*(_{3,}.*)$/u', $texto, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($texto), ''];
    }

    /**
     * @param  array<string, string>  $indice  sinónimo normalizado → variable
     * @return array{0: string, 1: string, 2: bool}|null
     */
    private function mejorCoincidencia(string $normal, array $indice): ?array
    {
        if (isset($indice[$normal])) {
            return [$normal, $indice[$normal], true];
        }

        // Etiquetas largas que empiezan con un sinónimo ("nombre del
        // colaborador que solicita"): gana el sinónimo más largo.
        $mejor = null;

        foreach ($indice as $sinonimo => $variable) {
            if (mb_strlen($sinonimo) < 3 || mb_strlen($normal) > mb_strlen($sinonimo) + 30) {
                continue;
            }

            if (str_starts_with($normal, $sinonimo.' ') && ($mejor === null || mb_strlen($sinonimo) > mb_strlen($mejor[0]))) {
                $mejor = [$sinonimo, $variable, false];
            }
        }

        return $mejor;
    }

    /**
     * @return array<string, string>
     */
    private function indiceSinonimos(): array
    {
        $indice = [];

        foreach ($this->catalogo->definiciones() as $definicion) {
            foreach ($definicion['sinonimos'] as $sinonimo) {
                $clave = $this->normalizar($sinonimo);
                // El primero que declara un sinónimo gana (el catálogo
                // está ordenado de lo más común a lo más específico).
                $indice[$clave] ??= $definicion['clave'];
            }
        }

        return $indice;
    }
}
