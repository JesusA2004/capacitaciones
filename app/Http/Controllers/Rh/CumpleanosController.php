<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\BirthdayPhrase;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Panel RH del modulo de cumpleanos (docs/CUMPLEANOS.md): dashboard,
 * calendario mensual, tarjeta de felicitacion descargable y catalogo de
 * frases. Toda la logica de negocio vive en
 * App\Services\Cumpleanos\CumpleanosService / BirthdayCardService; este
 * controller solo autoriza, arma el payload y traduce a Inertia/HTTP.
 */
class CumpleanosController extends Controller
{
    private const FILTROS = ['sucursal_id', 'departamento_id', 'estatus', 'busqueda'];

    public function __construct(
        private readonly CumpleanosService $cumpleanos,
        private readonly BirthdayCardService $tarjetas,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $mes = (int) ($request->integer('mes') ?: now()->month);
        $filtros = $request->only(self::FILTROS);

        $delMes = $this->cumpleanos->cumpleanosDelMes($mes, $usuario, $filtros)
            ->map(fn (User $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        $hoy = $this->cumpleanos->cumpleanosDeHoy($usuario)
            ->map(fn (User $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        $proximos7 = $this->cumpleanos->proximosCumpleanos($usuario, 7)
            ->map(fn (User $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        $proximos30 = $this->cumpleanos->proximosCumpleanos($usuario, 30)
            ->map(fn (User $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        $puedeCalendario = $usuario->can('rh.cumpleanos.calendario');

        return Inertia::render('Rh/Cumpleanos/Index', [
            'mes' => $mes,
            'filtros' => $filtros,
            'delMes' => $delMes,
            'hoy' => $hoy,
            'proximos7' => $proximos7,
            'proximos30' => $proximos30,
            'calendario' => $puedeCalendario ? $this->cumpleanos->payloadCalendario(now()->year, $mes, $usuario, $filtros) : null,
            'opciones' => [
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'frases' => $usuario->can('rh.cumpleanos.frases.gestionar')
                    ? BirthdayPhrase::query()->orderBy('orden')->orderBy('id')->get()
                    : [],
            ],
            'config' => [
                'enabled' => (bool) config('cumpleanos.enabled'),
                'notify_employee' => (bool) config('cumpleanos.notify_employee'),
                'notify_rh' => (bool) config('cumpleanos.notify_rh'),
                'show_age' => (bool) config('cumpleanos.show_age'),
                'show_branch' => (bool) config('cumpleanos.show_branch'),
                'show_employee_photo' => (bool) config('cumpleanos.show_employee_photo'),
                'auto_generate_cards' => (bool) config('cumpleanos.auto_generate_cards'),
            ],
            'permisos' => [
                'calendario' => $puedeCalendario,
                'descargarImagen' => $usuario->can('rh.cumpleanos.descargar_imagen'),
                'configurar' => $usuario->can('rh.cumpleanos.configurar'),
                'gestionarFrases' => $usuario->can('rh.cumpleanos.frases.gestionar'),
                'gestionarNotificaciones' => $usuario->can('rh.cumpleanos.notificaciones.gestionar'),
            ],
        ]);
    }

    public function felicitacion(Request $request, User $colaborador): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        return Inertia::render('Rh/Cumpleanos/Felicitacion', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ],
            'greeting' => [
                'id' => $greeting->id,
                'fecha' => $greeting->fecha->toDateString(),
                'frase' => $greeting->frase,
                'enviadaAt' => $greeting->enviada_at?->toIso8601String(),
                'tieneImagen' => $greeting->card_path !== null,
                'imagenUrl' => route('rh.cumpleanos.felicitacion.descargar', $colaborador),
            ],
            'permisos' => [
                'descargarImagen' => $usuario->can('rh.cumpleanos.descargar_imagen'),
                'gestionarNotificaciones' => $usuario->can('rh.cumpleanos.notificaciones.gestionar'),
            ],
        ]);
    }

    public function generar(Request $request, User $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->tarjetas->generar($colaborador, $fecha);

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación generada.']);
    }

    public function regenerar(Request $request, User $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->tarjetas->regenerar($colaborador, $fecha);

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación regenerada con una nueva frase e imagen.']);
    }

    public function descargar(Request $request, User $colaborador): HttpResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.descargar_imagen'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        return $this->tarjetas->descargar($greeting);
    }

    public function enviarManual(Request $request, User $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.notificaciones.gestionar'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->cumpleanos->reenviarManual($colaborador, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación enviada al colaborador.']);
    }

    public function storeFrase(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.frases.gestionar'), 403);

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'max:100'],
        ]);

        BirthdayPhrase::create([
            'texto' => $datos['texto'],
            'categoria' => $datos['categoria'] ?? null,
            'activo' => true,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Frase agregada.']);
    }

    public function updateFrase(Request $request, BirthdayPhrase $frase): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.frases.gestionar'), 403);

        $datos = $request->validate([
            'texto' => ['sometimes', 'string', 'max:1000'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:100'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $frase->update($datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Frase actualizada.']);
    }

    public function destroyFrase(Request $request, BirthdayPhrase $frase): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.frases.gestionar'), 403);

        $frase->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Frase eliminada.']);
    }
}
