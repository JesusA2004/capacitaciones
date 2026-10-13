<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Services\Colaboradores\NotificacionesService;
use App\Services\Notificaciones\ConsultaNotificacionesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo «Notificaciones»: las notificaciones del usuario con «Marcar como
 * leída», «Marcar todas como leídas» e «Ir» (abre el registro exacto para
 * darle seguimiento). Quien tiene notificaciones.ver_todas puede además
 * consultar las de todo el equipo, solo lectura: nunca se marca como leída
 * la notificación de otra persona.
 */
class NotificacionSistemaController extends Controller
{
    public function __construct(
        private readonly ConsultaNotificacionesService $consulta,
        private readonly NotificacionesService $notificaciones,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $deTodos = $request->string('ver')->toString() === 'todas' && $usuario->can('notificaciones.ver_todas');
        $estado = $request->string('estado')->toString() === 'no_leidas' ? 'no_leidas' : null;

        return Inertia::render('Administracion/Notificaciones/Index', [
            'notificaciones' => $this->consulta->listar([
                'estado' => $estado,
                'usuario_id' => $deTodos ? null : $usuario->id,
                'per_page' => $request->input('per_page'),
            ], $usuario),
            'filtros' => ['ver' => $deTodos ? 'todas' : 'mias', 'estado' => $estado ?? 'todas'],
            'puedeVerTodas' => $usuario->can('notificaciones.ver_todas'),
            'noLeidas' => $usuario->unreadNotifications()->count(),
        ]);
    }

    public function marcarLeida(Request $request, string $notificacion): RedirectResponse
    {
        $this->notificaciones->marcarLeida($request->user(), $notificacion);

        return back();
    }

    public function marcarTodasLeidas(Request $request): RedirectResponse
    {
        $this->notificaciones->marcarTodasLeidas($request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Todas tus notificaciones quedaron como leídas.']);
    }

    /**
     * «Ir»: la marca leída y lleva al registro (expediente, solicitud,
     * recibo…). Si ya fue atendida se avisa en vez de mandar a una pantalla rota.
     */
    public function abrir(Request $request, string $notificacion): RedirectResponse
    {
        $destino = $this->notificaciones->abrir($request->user(), $notificacion);

        if ($destino['url'] === null) {
            return back()->with('toast', ['type' => 'info', 'message' => $destino['mensaje_estado'] ?? 'Esta notificación no tiene una pantalla asociada.']);
        }

        $redireccion = redirect($destino['url']);

        return $destino['atendida'] === true && $destino['mensaje_estado'] !== null
            ? $redireccion->with('toast', ['type' => 'info', 'message' => $destino['mensaje_estado']])
            : $redireccion;
    }
}
