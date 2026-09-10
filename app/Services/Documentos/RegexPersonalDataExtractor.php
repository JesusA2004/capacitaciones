<?php

namespace App\Services\Documentos;

/**
 * Extractor "v1" de datos personales a partir de texto plano (ya sacado de
 * un PDF por DocumentExtractionService): parsers simples por regex para los
 * campos que tienen un formato oficial reconocible sin necesidad de OCR ni
 * análisis de layout. Deliberadamente NO intenta adivinar nombre, apellidos
 * ni domicilio completo por regex — el riesgo de un falso positivo con
 * texto libre es alto y esos campos quedan para una iteración futura (con
 * OCR + posición del texto), documentado en docs/DOCUMENT_EXTRACTION.md.
 *
 * Nunca lanza excepción: un texto vacío o sin coincidencias simplemente
 * regresa listas vacías.
 */
class RegexPersonalDataExtractor
{
    /**
     * @return array{data: array<string, string>, confidence: array<string, string>}
     */
    public function extraer(string $texto): array
    {
        $texto = strtoupper($texto);
        $data = [];
        $confidence = [];

        if (($curp = $this->buscar('/\b[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d\b/', $texto)) !== null) {
            $data['curp'] = $curp;
            $confidence['curp'] = 'alta';
        }

        if (($rfc = $this->buscar('/\b[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}\b/', $texto)) !== null) {
            $data['rfc'] = $rfc;
            $confidence['rfc'] = 'alta';
        }

        if (($nss = $this->buscar('/\b\d{11}\b/', $texto)) !== null) {
            $data['nss'] = $nss;
            $confidence['nss'] = 'media';
        }

        if (($cp = $this->buscar('/\bC\.?\s?P\.?\s*:?\s*(\d{5})\b/', $texto, 1)) !== null) {
            $data['codigo_postal'] = $cp;
            $confidence['codigo_postal'] = 'media';
        }

        if (($sexo = $this->buscar('/\bSEXO\s*:?\s*([HM])\b/', $texto, 1)) !== null) {
            $data['sexo'] = $sexo;
            $confidence['sexo'] = 'media';
        }

        // Fecha de nacimiento: solo se reporta si aparece cerca de la
        // palabra "NACIMIENTO" (una fecha suelta en el documento puede ser
        // la de expedición/vigencia, no la de nacimiento).
        if (preg_match('/NACIMIENTO.{0,40}?(\d{2}[\/\-.]\d{2}[\/\-.](?:19|20)\d{2})/s', $texto, $m) === 1) {
            $data['fecha_nacimiento'] = $m[1];
            $confidence['fecha_nacimiento'] = 'media';
        }

        return ['data' => $data, 'confidence' => $confidence];
    }

    private function buscar(string $patron, string $texto, int $grupo = 0): ?string
    {
        return preg_match($patron, $texto, $m) === 1 ? $m[$grupo] : null;
    }
}
