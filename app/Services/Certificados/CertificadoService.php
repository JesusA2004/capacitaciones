<?php

namespace App\Services\Certificados;

use App\Models\Certificado;
use App\Models\InscripcionCurso;
use Illuminate\Support\Str;

/**
 * Emision de constancias: solo se genera una por inscripcion (indice unico
 * en inscripcion_curso_id), y solo si el curso tiene genera_constancia =
 * true. El folio es un codigo corto legible (no un UUID largo) pensado para
 * que un colaborador pueda escribirlo a mano al verificarlo.
 */
class CertificadoService
{
    public function emitirSiAplica(InscripcionCurso $inscripcion): ?Certificado
    {
        $inscripcion->loadMissing('curso');

        if (! $inscripcion->curso->genera_constancia) {
            return null;
        }

        return Certificado::firstOrCreate(
            ['inscripcion_curso_id' => $inscripcion->id],
            [
                'folio' => $this->generarFolio(),
                'user_id' => $inscripcion->user_id,
                'curso_id' => $inscripcion->curso_id,
                'emitido_en' => now(),
            ],
        );
    }

    private function generarFolio(): string
    {
        do {
            $folio = 'MRL-'.strtoupper(Str::random(8));
        } while (Certificado::where('folio', $folio)->exists());

        return $folio;
    }
}
