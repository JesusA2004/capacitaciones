<?php

namespace App\Services\Documentos;

/**
 * Extractor de datos personales a partir de texto plano (ya sacado de un PDF
 * o de OCR local por DocumentExtractionService): parsers simples por regex
 * para los campos que tienen un formato oficial reconocible o que aparecen
 * bajo una etiqueta explícita en el documento. Deliberadamente NO intenta
 * adivinar nombre, apellidos ni domicilio completo de texto libre sin
 * etiqueta — el riesgo de un falso positivo es alto y CLAUDE.md §38 prohíbe
 * deducir (estado civil por nombre, nacionalidad por ser mexicano, etc.).
 *
 * `VERSION` sube cada vez que se agrega o cambia un patrón: el comando
 * `expedientes:extraer-existentes` lo guarda junto al hash del documento
 * (`DocumentExtraction::extractor_version`/`source_hash`) para no repetir
 * lectura de NAS sobre un documento ya procesado con la misma versión.
 *
 * Nunca lanza excepción: un texto vacío o sin coincidencias simplemente
 * regresa listas vacías.
 */
class RegexPersonalDataExtractor
{
    public const VERSION = 2;

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

        if (($claveElector = $this->buscar('/\bCLAVE\s*DE\s*ELECTOR\s*:?\s*([A-Z0-9]{18})\b/', $texto, 1)) !== null) {
            $data['clave_elector'] = $claveElector;
            $confidence['clave_elector'] = 'alta';
        }

        if (($correo = $this->buscar('/\bCORREO(?:\s*ELECTR[OÓ]NICO)?\s*:?\s*([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})\b/', $texto, 1)) !== null) {
            $data['correo'] = $correo;
            $confidence['correo'] = 'alta';
        }

        if (($telefono = $this->buscar('/\bTEL(?:[EÉ]FONO)?\s*:?\s*(\d{10})\b/', $texto, 1)) !== null) {
            $data['telefono'] = $telefono;
            $confidence['telefono'] = 'media';
        }

        // Los siguientes SOLO se reportan bajo su etiqueta explícita en el
        // documento (CLAUDE.md §38: nunca deducir estado civil, nacionalidad,
        // domicilio, beneficiario ni contacto de emergencia a partir de otro dato).
        if (($nacionalidad = $this->buscar('/\bNACIONALIDAD\s*:?\s*([A-ZÁÉÍÓÚÑ]{3,30})\b/', $texto, 1)) !== null) {
            $data['nacionalidad'] = $nacionalidad;
            $confidence['nacionalidad'] = 'media';
        }

        if (($estadoCivil = $this->buscar('/\bESTADO\s*CIVIL\s*:?\s*(SOLTER[OA]|CASAD[OA]|DIVORCIAD[OA]|VIUD[OA]|UNI[OÓ]N\s*LIBRE)\b/', $texto, 1)) !== null) {
            $data['estado_civil'] = $estadoCivil;
            $confidence['estado_civil'] = 'media';
        }

        if (($lugarNacimiento = $this->buscar('/\bLUGAR\s*DE\s*NACIMIENTO\s*:?\s*([A-ZÁÉÍÓÚÑ\s,.]{3,60})/', $texto, 1)) !== null) {
            $data['lugar_nacimiento'] = trim($lugarNacimiento);
            $confidence['lugar_nacimiento'] = 'media';
        }

        if (($profesion = $this->buscar('/\bPROFESI[OÓ]N\s*:?\s*([A-ZÁÉÍÓÚÑ\s]{3,60})/', $texto, 1)) !== null) {
            $data['profesion'] = trim($profesion);
            $confidence['profesion'] = 'baja';
        }

        if (($domicilio = $this->buscar('/\bDOMICILIO\s*:?\s*([A-ZÁÉÍÓÚÑ0-9#\s,.\-]{5,120})/', $texto, 1)) !== null) {
            $data['domicilio'] = trim($domicilio);
            $confidence['domicilio'] = 'baja';
        }

        if (($colonia = $this->buscar('/\bCOLONIA\s*:?\s*([A-ZÁÉÍÓÚÑ0-9\s,.\-]{3,60})/', $texto, 1)) !== null) {
            $data['colonia'] = trim($colonia);
            $confidence['colonia'] = 'media';
        }

        if (($municipio = $this->buscar('/\bMUNICIPIO\s*:?\s*([A-ZÁÉÍÓÚÑ\s]{3,60})/', $texto, 1)) !== null) {
            $data['municipio'] = trim($municipio);
            $confidence['municipio'] = 'media';
        }

        if (($estadoDomicilio = $this->buscar('/\bESTADO\s*:?\s*([A-ZÁÉÍÓÚÑ\s]{3,40})\s*(?:C\.?P\.?|MUNICIPIO|$)/', $texto, 1)) !== null) {
            $data['estado_domicilio'] = trim($estadoDomicilio);
            $confidence['estado_domicilio'] = 'baja';
        }

        // Beneficiario / contacto de emergencia: solo bajo su sección
        // explícita del documento, nunca por cercanía de texto libre.
        if (preg_match('/BENEFICIARIO\S*\s*:?\s*([A-ZÁÉÍÓÚÑ\s]{5,60})(?:PARENTESCO\s*:?\s*([A-ZÁÉÍÓÚÑ]{3,30}))?/s', $texto, $m) === 1) {
            $data['beneficiario_nombre'] = trim($m[1]);
            $confidence['beneficiario_nombre'] = 'baja';
            if (! empty($m[2])) {
                $data['beneficiario_parentesco'] = trim($m[2]);
                $confidence['beneficiario_parentesco'] = 'baja';
            }
        }

        if (preg_match('/CONTACTO\s*DE\s*EMERGENCIA\s*:?\s*([A-ZÁÉÍÓÚÑ\s]{5,60})/s', $texto, $m) === 1) {
            $data['contacto_emergencia_nombre'] = trim($m[1]);
            $confidence['contacto_emergencia_nombre'] = 'baja';

            if (preg_match('/CONTACTO\s*DE\s*EMERGENCIA.{0,80}?TEL(?:[EÉ]FONO)?\s*:?\s*(\d{10})/s', $texto, $mt) === 1) {
                $data['contacto_emergencia_telefono'] = $mt[1];
                $confidence['contacto_emergencia_telefono'] = 'media';
            }

            if (preg_match('/CONTACTO\s*DE\s*EMERGENCIA.{0,80}?PARENTESCO\s*:?\s*([A-ZÁÉÍÓÚÑ]{3,30})/s', $texto, $mp) === 1) {
                $data['contacto_emergencia_parentesco'] = $mp[1];
                $confidence['contacto_emergencia_parentesco'] = 'baja';
            }
        }

        return ['data' => $data, 'confidence' => $confidence];
    }

    private function buscar(string $patron, string $texto, int $grupo = 0): ?string
    {
        return preg_match($patron, $texto, $m) === 1 ? $m[$grupo] : null;
    }
}
