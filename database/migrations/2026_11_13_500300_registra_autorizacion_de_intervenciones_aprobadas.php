<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\ProcesoAprobacion;
use App\Models\IntervencionCandidato;
use App\Services\CicloLaboral\AprobacionService;
use Illuminate\Database\Migrations\Migration;

/**
 * Antes de esta corrección, aprobar una intervención solo cambiaba el estado
 * del candidato a «Autorizado por RH»: no registraba la autorización final en
 * la cadena de aprobaciones, y ContratacionCandidatoService bloqueaba el QR de
 * contratación («solo se contrata con la autorización final de RH
 * registrada»). Para cada intervención ya aprobada cuyo candidato sigue en
 * «Autorizado por RH» sin autorización registrada, se registra ahora, a nombre
 * de quien decidió la intervención. Idempotente: si ya existe, no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aprobaciones = app(AprobacionService::class);

        IntervencionCandidato::query()
            ->where('estado', EstadoIntervencionCandidato::Aprobada->value)
            ->with(['candidato', 'aprobador'])
            ->orderBy('id')
            ->each(function (IntervencionCandidato $intervencion) use ($aprobaciones): void {
                $candidato = $intervencion->candidato;
                $aprobador = $intervencion->aprobador;

                if ($candidato === null || $aprobador === null || $candidato->estado !== EstadoCandidato::AutorizadoRh) {
                    return;
                }

                if ($aprobaciones->estaAutorizadoPorRh($candidato, ProcesoAprobacion::SeleccionCandidato)) {
                    return;
                }

                $aprobaciones->autorizarPorIntervencion(
                    $candidato,
                    ProcesoAprobacion::SeleccionCandidato,
                    $aprobador,
                    sprintf(
                        'Contratación autorizada por intervención de %s (%s)%s.',
                        $intervencion->ruta->etiqueta(),
                        $aprobador->nombreCompleto(),
                        $intervencion->comentario_decision !== null ? ": {$intervencion->comentario_decision}" : '',
                    ),
                    ['candidato' => $candidato, 'solicitante' => $aprobador],
                );
            });
    }

    public function down(): void
    {
        // Sin reversa: quitar la autorización volvería a bloquear contrataciones legítimas.
    }
};
