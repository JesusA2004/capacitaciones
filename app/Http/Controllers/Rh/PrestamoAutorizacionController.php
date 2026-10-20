<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AutorizarPrestamoWebRequest;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Services\Nomina\PrestamoAutorizacionService;
use Illuminate\Http\RedirectResponse;

/**
 * «Autorizar préstamo» desde el detalle web de la solicitud. Solo RH
 * (prestamos.autorizar + PrestamoPolicy::autorizarSolicitud); el gerente,
 * el regional y Dirección Comercial solo dan visto bueno. Llama al MISMO
 * servicio que la API móvil (Api\V1\Rh\PrestamoController::autorizar).
 */
class PrestamoAutorizacionController extends Controller
{
    public function __construct(private readonly PrestamoAutorizacionService $prestamos) {}

    public function autorizar(AutorizarPrestamoWebRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('autorizarSolicitud', [Prestamo::class, $solicitud]);

        $prestamo = $this->prestamos->autorizar($solicitud, $request->validated(), $request->user());

        // Un documento que no se pudo generar queda como pendiente (tarea),
        // nunca revierte la autorización: se avisa para que RH lo complete.
        if ($prestamo->contrato_documento_id === null || $prestamo->pagare_documento_id === null) {
            return back()->with('toast', ['type' => 'warning', 'message' => 'Préstamo autorizado. Algún documento quedó pendiente: revisa «Documentos del proceso».']);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Préstamo autorizado. Contrato de crédito, pagaré y carta de retención generados.']);
    }
}
