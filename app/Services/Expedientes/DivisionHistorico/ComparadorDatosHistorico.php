<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Enums\EstadoDatoDetectado;
use App\Models\Colaborador;
use App\Services\Documentos\DocumentExtractionService;
use App\Services\Documentos\RegexPersonalDataExtractor;
use Illuminate\Support\Str;

/**
 * Reporte «datos faltantes / discrepancias» del PDF histórico dividido:
 * corre el extractor por regex YA EXISTENTE (RegexPersonalDataExtractor)
 * sobre el texto de CADA parte clasificada, acepta de cada documento solo
 * los campos que tiene sentido leer de él (reglas.{tipo}.campos) y compara
 * contra el colaborador con la misma regla que las sugerencias de
 * extracción (DocumentExtractionService::compararCampo).
 *
 * Nunca escribe en el colaborador. El nombre NUNCA se extrae de texto libre
 * (docs/DOCUMENT_EXTRACTION.md): solo se verifica que el nombre capturado
 * aparezca en el PDF, como control de identidad.
 *
 * @phpstan-type ParteConTexto array{tipo: string, tipo_nombre: string, paginas: list<int>, texto: string}
 * @phpstan-type DatoDetectado array{campo: string, etiqueta: string, actual: string|null, valores: list<array{valor: string, fuentes: list<string>}>, estado: string, aplicado: array{valor: string, por: int, en: string}|null}
 */
class ComparadorDatosHistorico
{
    /** Etiqueta en español de cada campo de DocumentExtractionService::CAMPO_COLUMNA. */
    public const ETIQUETAS = [
        'curp' => 'CURP',
        'rfc' => 'RFC',
        'nss' => 'NSS',
        'fecha_nacimiento' => 'Fecha de nacimiento',
        'codigo_postal' => 'Código postal',
        'sexo' => 'Sexo',
        'clave_elector' => 'Clave de elector',
        'correo' => 'Correo personal',
        'telefono' => 'Teléfono',
        'nacionalidad' => 'Nacionalidad',
        'estado_civil' => 'Estado civil',
        'lugar_nacimiento' => 'Lugar de nacimiento',
        'profesion' => 'Profesión',
        'domicilio' => 'Domicilio',
        'colonia' => 'Colonia',
        'municipio' => 'Municipio',
        'estado_domicilio' => 'Estado (domicilio)',
        'beneficiario_nombre' => 'Beneficiario',
        'beneficiario_parentesco' => 'Parentesco del beneficiario',
        'contacto_emergencia_nombre' => 'Contacto de emergencia',
        'contacto_emergencia_telefono' => 'Teléfono del contacto de emergencia',
        'contacto_emergencia_parentesco' => 'Parentesco del contacto de emergencia',
    ];

    public function __construct(
        private readonly RegexPersonalDataExtractor $extractor,
        private readonly DocumentExtractionService $extraccion,
    ) {}

    /**
     * @param  list<ParteConTexto>  $partes
     * @return list<DatoDetectado>
     */
    public function comparar(Colaborador $colaborador, array $partes): array
    {
        $reglas = (array) config('expedientes.division_historico.reglas', []);
        /** @var array<string, array<string, array{valor: string, fuentes: list<string>}>> $porCampo */
        $porCampo = [];

        foreach ($partes as $parte) {
            $permitidos = (array) ($reglas[$parte['tipo']]['campos'] ?? []);

            if ($permitidos === [] || trim($parte['texto']) === '') {
                continue;
            }

            $fuente = sprintf('%s (p. %s)', $parte['tipo_nombre'], implode(', ', $parte['paginas']));
            $detectado = $this->extractor->extraer($parte['texto'])['data'];

            foreach ($detectado as $campo => $valor) {
                if (! in_array($campo, $permitidos, true) || ! array_key_exists($campo, DocumentExtractionService::CAMPO_COLUMNA)) {
                    continue;
                }

                $clave = $this->normalizar($valor);
                $porCampo[$campo][$clave] ??= ['valor' => $valor, 'fuentes' => []];
                $porCampo[$campo][$clave]['fuentes'][] = $fuente;
            }
        }

        $datos = [];

        foreach (array_keys(DocumentExtractionService::CAMPO_COLUMNA) as $campo) {
            if (! isset($porCampo[$campo])) {
                continue;
            }

            $valores = array_values($porCampo[$campo]);
            $datos[] = $this->evaluar($colaborador, $campo, $valores, null);
        }

        return $datos;
    }

    /**
     * Recalcula el estado de un dato ya reportado (después de que RH aplicó
     * un valor o de que cambió el expediente), conservando lo aplicado.
     *
     * Acepta la fila tal como vuelve del JSON de BD y la re-tipa.
     *
     * @param  array<mixed>  $dato
     * @return DatoDetectado
     */
    public function reevaluar(Colaborador $colaborador, array $dato): array
    {
        $valores = [];

        foreach ((array) ($dato['valores'] ?? []) as $valor) {
            if (is_array($valor) && isset($valor['valor'])) {
                $valores[] = [
                    'valor' => sprintf('%s', $valor['valor']),
                    'fuentes' => array_values(array_map(fn ($f): string => sprintf('%s', $f), (array) ($valor['fuentes'] ?? []))),
                ];
            }
        }

        $aplicado = $dato['aplicado'] ?? null;
        $aplicado = is_array($aplicado) && isset($aplicado['valor'], $aplicado['por'], $aplicado['en'])
            ? ['valor' => sprintf('%s', $aplicado['valor']), 'por' => (int) $aplicado['por'], 'en' => sprintf('%s', $aplicado['en'])]
            : null;

        return $this->evaluar($colaborador, sprintf('%s', $dato['campo'] ?? ''), $valores, $aplicado);
    }

    /**
     * true si el nombre capturado del colaborador aparece en el texto del
     * PDF (todas sus palabras de 3+ letras, sin acentos ni mayúsculas).
     */
    public function nombreAparece(Colaborador $colaborador, string $texto): bool
    {
        $texto = ' '.$this->normalizarNombre($texto).' ';
        $palabras = array_filter(explode(' ', $this->normalizarNombre($colaborador->nombreCompleto())), fn (string $p): bool => strlen($p) >= 3);

        if ($palabras === []) {
            return false;
        }

        foreach ($palabras as $palabra) {
            if (! str_contains($texto, ' '.$palabra.' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{valor: string, fuentes: list<string>}>  $valores
     * @param  array{valor: string, por: int, en: string}|null  $aplicado
     * @return DatoDetectado
     */
    private function evaluar(Colaborador $colaborador, string $campo, array $valores, ?array $aplicado): array
    {
        $comparaciones = array_map(fn (array $v): array => $this->extraccion->compararCampo($colaborador, $campo, $v['valor']), $valores);
        $actual = $comparaciones[0]['actual'] ?? null;
        $algunoCoincide = in_array(true, array_column($comparaciones, 'coincide'), true);

        $estado = match (true) {
            $aplicado !== null && $algunoCoincide => EstadoDatoDetectado::Aplicado,
            count($valores) > 1 => EstadoDatoDetectado::ConflictoDocumentos,
            $actual === null || trim($actual) === '' => EstadoDatoDetectado::Faltante,
            $algunoCoincide => EstadoDatoDetectado::Coincide,
            default => EstadoDatoDetectado::Discrepancia,
        };

        return [
            'campo' => $campo,
            'etiqueta' => self::ETIQUETAS[$campo] ?? $campo,
            'actual' => $actual,
            'valores' => $valores,
            'estado' => $estado->value,
            'aplicado' => $aplicado,
        ];
    }

    private function normalizar(string $valor): string
    {
        return Str::upper(trim($valor));
    }

    private function normalizarNombre(string $texto): string
    {
        return trim((string) preg_replace('/[^A-Z]+/', ' ', Str::upper(Str::ascii($texto))));
    }
}
