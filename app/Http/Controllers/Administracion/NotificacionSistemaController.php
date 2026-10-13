<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\ConsultaNotificacionesService;
use App\Services\Notificaciones\MonitorNotificacionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Notificaciones del sistema»: solo lectura, permiso
 * notificaciones.ver_todas (middleware de la ruta). La campana de cada
 * persona no cambia: siempre son las propias.
 */
class NotificacionSistemaController extends Controller
{
    private const FILTROS = ['usuario', 'tipo', 'modulo', 'estado', 'entrega', 'desde', 'hasta', 'per_page'];

    public function __construct(
        private readonly ConsultaNotificacionesService $consulta,
        private readonly MonitorNotificacionesService $monitor,
    ) {}

    public function index(Request $request): Response
    {
        $filtros = $request->only(self::FILTROS);

        return Inertia::render('Administracion/Notificaciones/Index', [
            'tarjetas' => $this->monitor->estado()['tarjetas'],
            'notificaciones' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'opciones' => $this->consulta->opciones(),
        ]);
    }
}
