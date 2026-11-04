<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\ReglaRecepcionDocumental;
use App\Enums\TipoSolicitudInterna;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;

/**
 * VACACIONES: un «Formato de vacaciones firmado» por solicitud de
 * vacaciones APROBADA cuya aprobación final (revisado_en, UTC) cae en el
 * mes en hora de México. Solo si el formato exige firma
 * (config/solicitudes.php → formatos.vacaciones.requiere_firma). Si la
 * solicitud se cancela después, deja de esperarse.
 */
class ReglaVacaciones implements ReglaRecepcion
{
    public const TIPO = 'formato_vacaciones';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Vacaciones;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        if (! (bool) config(sprintf('solicitudes.formatos.%s.requiere_firma', TipoSolicitudInterna::Vacaciones->value), false)) {
            return [];
        }

        $solicitudes = SolicitudInterna::query()
            ->where('tipo', TipoSolicitudInterna::Vacaciones->value)
            ->where('estado', EstadoSolicitudInterna::Aprobada->value)
            ->whereBetween('revisado_en', $periodo->rangoUtc())
            ->with(['colaborador' => fn ($q) => $q->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])])
            ->orderBy('id')
            ->get();

        if ($solicitudes->isEmpty()) {
            return [];
        }

        $documentos = GeneratedDocument::query()
            ->whereIn('solicitud_id', $solicitudes->modelKeys())
            ->whereIn('clave_plantilla', ['comprobante_vacaciones', 'formato_vacaciones'])
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get(['id', 'solicitud_id'])
            ->unique('solicitud_id')
            ->keyBy('solicitud_id');

        $esperados = [];

        foreach ($solicitudes as $solicitud) {
            $documento = $documentos->get($solicitud->id);

            $esperados[] = EsperadoRecepcion::deColaborador(
                $this->regla(),
                self::TIPO,
                EsperadoRecepcion::etiquetaTipo(self::TIPO),
                $solicitud,
                $solicitud->colaborador,
                PeriodoRecepcion::fechaMexico($solicitud->revisado_en),
                sprintf(
                    'Vacaciones %s del %s al %s (%d días), aprobadas el %s',
                    $solicitud->folio ?? '',
                    $solicitud->fecha_inicio?->format('d/m/Y') ?? '—',
                    $solicitud->fecha_fin?->format('d/m/Y') ?? '—',
                    (int) $solicitud->dias_solicitados,
                    $solicitud->revisado_en?->setTimezone('America/Mexico_City')->format('d/m/Y') ?? '—',
                ),
                $documento instanceof GeneratedDocument ? $documento->id : null,
            );
        }

        return $esperados;
    }
}
