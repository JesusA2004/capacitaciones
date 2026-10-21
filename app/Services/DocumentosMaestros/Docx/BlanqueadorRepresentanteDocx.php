<?php

namespace App\Services\DocumentosMaestros\Docx;

use App\Models\Empresa;

/**
 * Regla universal: NINGÚN documento generado imprime el nombre del
 * representante legal de la empresa (se escribe y firma a pluma). Ver
 * memoria «firma-empresa-representante-legal» y MotorDocumentalService.
 *
 * La variable `representante_legal` ya se deja en blanco al renderizar,
 * pero los formatos de Jurídico con representante «fijo_juridico»
 * (contratos de capacitación inicial, indeterminados, confidencialidad, no
 * competencia…) traen el nombre como TEXTO FIJO —en la declaración
 * («representada por la señora …») y en el bloque de firmas— y ninguna
 * regla de mapeo lo convierte en variable. Este paso, aplicado al DOCX ya
 * rellenado, borra esas apariciones literales en cualquier versión guardada
 * sin tener que volver a normalizar ni a validar el diseño del master:
 *
 *  - renglón de firma (el párrafo es solo el nombre, con o sin «C.»): queda
 *    en blanco (espacio duro) y se conserva la línea y «Representante legal»;
 *  - dentro de un texto: se sustituye por una línea para escribir a mano.
 */
class BlanqueadorRepresentanteDocx
{
    /**
     * @param  list<string>  $nombres  Nombres a borrar (cualquier combinación de mayúsculas).
     */
    public function blanquear(string $docx, array $nombres): string
    {
        $nombres = array_values(array_unique(array_filter(array_map(
            fn (string $n): string => trim((string) preg_replace('/\s+/u', ' ', $n)),
            $nombres,
        ), fn (string $n): bool => mb_strlen($n) >= 5)));

        if ($nombres === []) {
            return $docx;
        }

        $documento = DocumentoWord::desdeBytes($docx);
        $cambios = 0;

        foreach ($documento->parrafos() as $parrafo) {
            foreach ($nombres as $nombre) {
                $cambios += $this->blanquearParrafo($parrafo, $nombre);
            }
        }

        return $cambios > 0 ? $documento->guardar() : $docx;
    }

    /**
     * Nombres conocidos del representante: el de respaldo de la
     * configuración y los capturados en Empresas.
     *
     * @return list<string>
     */
    public static function nombresConocidos(): array
    {
        $nombres = [(string) config('documentos_maestros.empresa_defecto.representante_legal_nombre', '')];

        foreach (Empresa::query()->whereNotNull('representante_legal_nombre')->pluck('representante_legal_nombre') as $nombre) {
            $nombres[] = (string) $nombre;
        }

        return array_values(array_filter($nombres, fn (string $n): bool => trim($n) !== ''));
    }

    private function blanquearParrafo(ParrafoWord $parrafo, string $nombre): int
    {
        $cambios = 0;
        $buscar = mb_strtoupper($nombre);
        $largo = mb_strlen($nombre);

        // De la última aparición a la primera: los offsets previos siguen valiendo.
        $texto = $parrafo->texto();
        $posiciones = [];
        $desde = 0;

        while (($posicion = mb_strpos(mb_strtoupper($texto), $buscar, $desde)) !== false) {
            $posiciones[] = $posicion;
            $desde = $posicion + $largo;
        }

        foreach (array_reverse($posiciones) as $posicion) {
            $resto = trim(mb_substr($texto, 0, $posicion).mb_substr($texto, $posicion + $largo), " \u{00A0}\t");
            $esFirma = $resto === '' || preg_match('/^(C\.|LA C\.|EL C\.)$/iu', $resto) === 1;

            if ($esFirma) {
                $parrafo->reemplazar(0, mb_strlen($texto), "\u{00A0}");
            } else {
                $parrafo->reemplazar($posicion, $posicion + $largo, str_repeat('_', $largo));
            }

            $cambios++;
            $texto = $parrafo->texto();

            if ($esFirma) {
                break;
            }
        }

        return $cambios;
    }
}
