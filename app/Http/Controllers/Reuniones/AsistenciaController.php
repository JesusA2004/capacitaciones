<?php

namespace App\Http\Controllers\Reuniones;

use App\Enums\EstadoAsistencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reuniones\MarcarAsistenciaRequest;
use App\Models\Asistencia;
use App\Models\SesionEnVivo;
use App\Services\Reuniones\AsistenciaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Toma de asistencia de una sesion en vivo. Marcar por primera vez (una
 * asistencia todavia "pendiente") solo requiere el permiso operativo
 * `sesiones.administrar`; cambiar una asistencia que ya tenia un estado
 * definitivo es una "corrección" y exige el permiso `asistencias.corregir`
 * y un motivo obligatorio, para que quede claro quién y por qué se ajustó
 * un registro ya cerrado.
 */
class AsistenciaController extends Controller
{
    public function __construct(private readonly AsistenciaService $service) {}

    public function index(Request $request, SesionEnVivo $sesion): Response
    {
        $this->authorize('sesiones.administrar');

        $sesion->load([
            'asistencias.usuario:id,name,apellidos',
            'asistencias.corregidoPor:id,name,apellidos',
            'asistencias.sesionParticipante.entradasSalidas',
            'leccion',
        ]);

        return Inertia::render('Reuniones/Asistencias', [
            'sesion' => $sesion,
        ]);
    }

    public function marcar(MarcarAsistenciaRequest $request, SesionEnVivo $sesion, Asistencia $asistencia): RedirectResponse
    {
        abort_unless($asistencia->sesion_en_vivo_id === $sesion->id, 404);

        $estadoNuevo = EstadoAsistencia::from($request->string('estado')->toString());
        $esCorreccion = $asistencia->estado !== EstadoAsistencia::Pendiente;

        if ($esCorreccion) {
            if (! $request->user()->can('asistencias.corregir')) {
                abort(403, 'No tienes permiso para corregir una asistencia ya registrada.');
            }

            if (! $request->filled('motivo')) {
                return back()->with('toast', ['type' => 'error', 'message' => 'Debes indicar el motivo de la corrección.']);
            }

            $this->service->corregir(
                $request->user(),
                $asistencia,
                $estadoNuevo,
                $request->string('motivo')->toString(),
                $request->input('minutos'),
                $request->file('evidencia'),
                $request->ip() ?? '',
                (string) $request->userAgent(),
                'manual',
            );
        } else {
            $this->service->marcarManual($asistencia, $estadoNuevo);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Asistencia actualizada correctamente.']);
    }
}
