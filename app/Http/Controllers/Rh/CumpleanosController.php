<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\BirthdayPhrase;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    private const FILTROS = ['sucursal_id', 'departamento_id', 'colaborador_id', 'estatus', 'busqueda'];

    public function __construct(
        private readonly CumpleanosService $cumpleanos,
        private readonly BirthdayCardService $tarjetas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $datos = $request->validate([
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'anio' => ['nullable', 'integer', 'min:'.(now()->year - 1), 'max:'.(now()->year + 5)],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'colaborador_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'estatus' => ['nullable', 'string'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'rango_desde' => ['nullable', 'date'],
            'rango_hasta' => ['nullable', 'date', 'after_or_equal:rango_desde'],
        ]);

        $mes = (int) ($datos['mes'] ?? now()->month);
        $anio = (int) ($datos['anio'] ?? now()->year);
        $filtros = array_intersect_key($datos, array_flip(self::FILTROS));

        $delMes = $this->cumpleanos->cumpleanosDelMes($mes, $usuario, $filtros)
            ->map(fn (Colaborador $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        $hoy = $this->cumpleanos->cumpleanosDeHoy($usuario)
            ->map(fn (Colaborador $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        // Mini-calendario de rango libre en el sidebar "Próximos cumpleaños"
        // (reemplaza los botones fijos de 7/30 días): por defecto hoy -> +30
        // días, o lo que el usuario haya elegido en los inputs de fecha.
        $rangoDesde = isset($datos['rango_desde']) ? Carbon::parse($datos['rango_desde']) : now();
        $rangoHasta = isset($datos['rango_hasta']) ? Carbon::parse($datos['rango_hasta']) : now()->addDays(30);

        $proximosRango = $this->cumpleanos->cumpleanosEnRango($usuario, $rangoDesde, $rangoHasta)
            ->map(fn (Colaborador $c) => $this->cumpleanos->tarjetaColaborador($c, null, $usuario))
            ->values();

        // Solo conteos (no la lista completa) para las tarjetas KPI fijas
        // de arriba, independientes del rango libre que el usuario elija.
        $totalProximos7 = $this->cumpleanos->proximosCumpleanos($usuario, 7)->count();
        $totalProximos30 = $this->cumpleanos->proximosCumpleanos($usuario, 30)->count();

        // Colaboradores del alcance sin fecha_nacimiento: nunca pueden salir
        // en el calendario ni en los conteos anteriores, así que RH necesita
        // verlos aparte para saber a quién le falta completar el dato.
        $sinFechaNacimiento = $this->cumpleanos->sinFechaNacimiento($usuario, $filtros)
            ->map(fn (Colaborador $c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'sucursal' => $c->sucursalPrincipal?->nombre])
            ->values();

        $puedeCalendario = $usuario->can('rh.cumpleanos.calendario');

        return Inertia::render('Rh/Cumpleanos/Index', [
            'mes' => $mes,
            'anio' => $anio,
            'filtros' => $filtros,
            'delMes' => $delMes,
            'hoy' => $hoy,
            'rango' => [
                'desde' => $rangoDesde->toDateString(),
                'hasta' => $rangoHasta->toDateString(),
            ],
            'proximosRango' => $proximosRango,
            'totalProximos7' => $totalProximos7,
            'totalProximos30' => $totalProximos30,
            'sinFechaNacimiento' => $sinFechaNacimiento,
            'calendario' => $puedeCalendario ? $this->cumpleanos->payloadCalendario($anio, $mes, $usuario, $filtros) : null,
            'opciones' => [
                // Acotadas al alcance organizacional de quien consulta: un
                // usuario sin alcance global (p. ej. gerente_sucursal) nunca
                // debe ver sucursales/departamentos fuera de lo suyo en el
                // selector, aunque tenga permiso rh.cumpleanos.ver via un rol
                // reconfigurado (ver App\Services\AlcanceOrganizacionalService).
                'sucursales' => Sucursal::query()
                    ->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario))
                    ->orderBy('nombre')
                    ->get(['id', 'nombre']),
                'departamentos' => Departamento::query()
                    ->whereIn('id', $this->alcance->departamentosVisiblesIds($usuario))
                    ->orderBy('nombre')
                    ->get(['id', 'nombre']),
                'colaboradores' => $this->cumpleanos->colaboradoresElegibles($usuario, $filtros)
                    ->map(fn (Colaborador $c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto()])
                    ->values(),
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

    public function felicitacion(Request $request, Colaborador $colaborador): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        // Reutiliza tarjetaColaborador() (misma funcion que arma el
        // calendario) para la foto: nunca expone foto_path, solo la URL
        // protegida por permiso.
        $datosColaborador = $this->cumpleanos->tarjetaColaborador($colaborador, false, $usuario);

        return Inertia::render('Rh/Cumpleanos/Felicitacion', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'foto_url' => $datosColaborador['foto_url'],
            ],
            'greeting' => [
                'id' => $greeting->id,
                'fecha' => $greeting->fecha->toDateString(),
                'frase' => $greeting->frase,
                'enviadaAt' => $greeting->enviada_at?->toIso8601String(),
                'tieneImagen' => $greeting->card_path !== null,
                // El querystring `v=` cambia cada vez que se regenera la
                // imagen (updated_at se actualiza con card_path): sin esto,
                // la URL es siempre la misma y el navegador sigue mostrando
                // la tarjeta vieja de caché hasta forzar un F5, aunque el
                // servidor ya tenga la nueva.
                'imagenUrl' => route('rh.cumpleanos.felicitacion.descargar', $colaborador).'?v='.$greeting->updated_at?->timestamp,
            ],
            'opciones' => [
                'frases' => BirthdayPhrase::query()->orderBy('orden')->orderBy('id')->get(['id', 'texto']),
            ],
            'permisos' => [
                'descargarImagen' => $usuario->can('rh.cumpleanos.descargar_imagen'),
                'gestionarNotificaciones' => $usuario->can('rh.cumpleanos.notificaciones.gestionar'),
            ],
        ]);
    }

    /**
     * Vista previa (bytes PNG) de la tarjeta con una frase distinta a la
     * guardada, sin tocar base de datos ni storage — usada por el selector
     * de frase en Felicitacion.vue antes de confirmar.
     */
    public function previsualizarFrase(Request $request, Colaborador $colaborador): HttpResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $datos = $request->validate(['frase' => ['required', 'string', 'max:1000']]);

        $png = $this->tarjetas->preview($colaborador, $datos['frase']);

        return response($png, 200, ['Content-Type' => 'image/png']);
    }

    public function confirmarFrase(Request $request, Colaborador $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $datos = $request->validate([
            'frase' => ['required', 'string', 'max:1000'],
            'birthday_phrase_id' => ['nullable', 'integer', 'exists:birthday_phrases,id'],
        ]);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->tarjetas->aplicarFrase($colaborador, $fecha, $datos['frase'], $datos['birthday_phrase_id'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Frase actualizada en la tarjeta.']);
    }

    public function generar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->tarjetas->generar($colaborador, $fecha);

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación generada.']);
    }

    public function regenerar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.ver'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $this->tarjetas->regenerar($colaborador, $fecha);

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación regenerada con una nueva frase e imagen.']);
    }

    public function descargar(Request $request, Colaborador $colaborador): HttpResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.descargar_imagen'), 403);

        $fecha = $this->cumpleanos->fechaEsteAnio($colaborador);
        abort_if($fecha === null, 404, 'Este colaborador no tiene fecha de nacimiento capturada.');

        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        return $this->tarjetas->descargar($greeting);
    }

    public function enviarManual(Request $request, Colaborador $colaborador): RedirectResponse
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
