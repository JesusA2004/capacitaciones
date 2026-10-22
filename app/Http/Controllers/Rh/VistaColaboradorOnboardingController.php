<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\AsignacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\ProgresoInduccionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Ver como colaborador» (RH): lo que una persona —o un colaborador nuevo
 * genérico— ve en Mi espacio, con los MISMOS componentes del portal y el
 * MISMO DTO (ProgresoInduccionService / AcuseDescriptivoPuestoService::aArray).
 * Solo lectura: no registra páginas, video, lectura ni acuse.
 */
class VistaColaboradorOnboardingController extends Controller
{
    public function __construct(
        private readonly AsignacionInduccionService $asignaciones,
        private readonly ProgresoInduccionService $progreso,
        private readonly InduccionPdfService $pdf,
        private readonly AcuseDescriptivoPuestoService $descriptivos,
    ) {}

    public function induccion(Request $request, ?Colaborador $colaborador = null): Response
    {
        $this->asignaciones->autorizarVistaPrevia($request->user(), $colaborador);

        return Inertia::render('Rh/Onboarding/VistaColaboradorInduccion', [
            'colaborador' => $colaborador !== null ? $this->persona($colaborador) : null,
            'miInduccion' => $colaborador !== null ? $this->progreso->resumen($colaborador) : $this->progreso->resumenGenerico(),
            'pdf' => $this->pdf->datosVisor(),
        ]);
    }

    public function descriptivo(Request $request, Colaborador $colaborador): Response
    {
        $vista = $this->descriptivos->vistaPreviaColaborador($colaborador, $request->user());

        return Inertia::render('Rh/Onboarding/VistaColaboradorDescriptivo', [
            'colaborador' => $this->persona($colaborador),
            'acuse' => $vista['acuse'],
            'motivo' => $vista['motivo'],
        ]);
    }

    /**
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}
     */
    private function persona(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
