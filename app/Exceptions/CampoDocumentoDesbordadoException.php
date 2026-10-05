<?php

namespace App\Exceptions;

/**
 * 422 DOCUMENT_FIELD_OVERFLOW: un dato no cabe en su campo del formato
 * oficial (texto encimado en el PDF original, o el Word crece y aparece una
 * página que el original no tiene). Nunca se emite un documento deformado:
 * RH corrige el dato (abreviatura autorizada) o pide a Jurídico ampliar el
 * formato.
 */
class CampoDocumentoDesbordadoException extends DocumentoMotorException
{
    /**
     * @param  list<array{campo: string, etiqueta: string, valor: string, razon: string}>  $campos
     */
    public static function para(string $documento, array $campos, ?string $razon = null): self
    {
        $nombres = implode(', ', array_map(fn (array $c): string => $c['etiqueta'], $campos));

        return new self(
            $nombres !== ''
                ? sprintf('«%s»: el dato %s no cabe en el formato oficial sin deformarlo.', $documento, $nombres)
                : sprintf('«%s»: los datos no caben en el formato oficial sin deformarlo (%s).', $documento, $razon ?? 'cambia el número de páginas'),
            array_filter(['documento' => $documento, 'campos' => $campos, 'razon' => $razon], fn (mixed $v): bool => $v !== null),
        );
    }

    public function codigo(): string
    {
        return 'DOCUMENT_FIELD_OVERFLOW';
    }
}
