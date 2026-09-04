<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreIncorporacionInvitacionRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\IncorporacionInvitacion;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Módulo RH del portal web para invitaciones de incorporación por QR
 * temporal. Solo aquí se genera el token plano (nunca se guarda en BD, ver
 * IncorporacionInvitacionService): la vista `Show` lo recibe únicamente
 * durante los minutos siguientes a crear/regenerar la invitación (ver
 * MINUTOS_VIGENCIA_TOKEN_EN_SESION), guardado en la sesión de quien la creó.
 * Pasada esa ventana — o desde cualquier otra sesión/navegador — el token ya
 * no está disponible y hay que regenerar la invitación.
 */
class IncorporacionInvitacionController extends Controller
{
    private const FILTROS = ['estado', 'empresa_id', 'sucursal_id', 'busqueda'];

    public function __construct(private readonly IncorporacionInvitacionService $invitaciones) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.incorporacion.invitaciones.ver'), 403);

        $invitaciones = $this->queryFiltrada($request)
            ->with([
                'empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre',
                'candidato:id,nombre,apellidos', 'usuario:id,name,apellidos',
                'creadoPor:id,name,apellidos', 'usadoPor:id,name,apellidos',
                'regeneradaDesde:id,uuid,estado',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Rh/Incorporacion/Invitaciones/Index', [
            'invitaciones' => $invitaciones,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            ],
            'puedeCrear' => $usuario->can('rh.incorporacion.invitaciones.crear'),
            'puedeRegenerar' => $usuario->can('rh.incorporacion.invitaciones.regenerar'),
            'puedeRevocar' => $usuario->can('rh.incorporacion.invitaciones.revocar'),
        ]);
    }

    /**
     * @return Builder<IncorporacionInvitacion>
     */
    private function queryFiltrada(Request $request): Builder
    {
        return IncorporacionInvitacion::query()
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->where('empresa_id', $id))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_id', $id))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda): void {
                    $sub->where('nombre_prellenado', 'like', "%{$busqueda}%")
                        ->orWhere('email', 'like', "%{$busqueda}%")
                        ->orWhere('codigo_legible', 'like', "%{$busqueda}%");
                });
            });
    }

    public function store(StoreIncorporacionInvitacionRequest $request): RedirectResponse
    {
        ['invitacion' => $invitacion, 'token' => $token] = $this->invitaciones->crear($request->validated(), $request->user());

        $this->guardarTokenPlanoEnSesion($invitacion, $token);

        return redirect()->route('rh.incorporacion.invitaciones.show', $invitacion)
            ->with('toast', ['type' => 'success', 'message' => 'Invitación creada. Copia la liga o descarga el QR: no vuelve a mostrarse.']);
    }

    public function show(Request $request, IncorporacionInvitacion $invitacion): Response
    {
        abort_unless($request->user()->can('rh.incorporacion.invitaciones.ver'), 403);

        $invitacion->loadMissing([
            'empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre',
            'candidato:id,nombre,apellidos', 'usuario:id,name,apellidos',
            'creadoPor:id,name,apellidos', 'usadoPor:id,name,apellidos',
            'regeneradaDesde:id,uuid,estado',
        ]);

        $tokenPlano = $this->tokenPlanoDeSesion($invitacion);

        return Inertia::render('Rh/Incorporacion/Invitaciones/Show', [
            'invitacion' => $invitacion,
            'tokenPlano' => $tokenPlano,
            'qrUrl' => $tokenPlano ? $this->invitaciones->urlQr($tokenPlano) : null,
            // Sin el prolog XML inicial: aqui se incrusta inline (v-html)
            // dentro de la pagina, no se sirve como archivo .svg (eso lo
            // hace qr(), con el SVG completo tal cual devuelve el Service).
            'qrSvg' => $tokenPlano ? preg_replace('/^<\?xml[^>]*\?>/', '', $this->invitaciones->qrSvg($tokenPlano)) : null,
            'puedeRegenerar' => $request->user()->can('rh.incorporacion.invitaciones.regenerar'),
            'puedeRevocar' => $request->user()->can('rh.incorporacion.invitaciones.revocar'),
            'puedeDescargarQr' => $request->user()->can('rh.incorporacion.invitaciones.qr.descargar'),
        ]);
    }

    public function regenerar(Request $request, IncorporacionInvitacion $invitacion): RedirectResponse
    {
        abort_unless($request->user()->can('rh.incorporacion.invitaciones.regenerar'), 403);

        ['invitacion' => $nueva, 'token' => $token] = $this->invitaciones->regenerar($invitacion, $request->user());

        $this->guardarTokenPlanoEnSesion($nueva, $token);

        return redirect()->route('rh.incorporacion.invitaciones.show', $nueva)
            ->with('toast', ['type' => 'success', 'message' => 'Invitación regenerada. La anterior quedó revocada.']);
    }

    public function revocar(Request $request, IncorporacionInvitacion $invitacion): RedirectResponse
    {
        abort_unless($request->user()->can('rh.incorporacion.invitaciones.revocar'), 403);

        try {
            $this->invitaciones->revocar($invitacion);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Invitación revocada.']);
    }

    /**
     * Descarga el QR (SVG o PNG) de la invitación mientras el token plano
     * siga disponible en la sesión (ver guardarTokenPlanoEnSesion/tokenPlanoDeSesion):
     * el token nunca se guarda, así que pasado ese momento ya no se puede
     * volver a generar la imagen — hay que regenerar la invitación.
     */
    public function qr(Request $request, IncorporacionInvitacion $invitacion): HttpResponse
    {
        abort_unless($request->user()->can('rh.incorporacion.invitaciones.qr.descargar'), 403);

        $tokenPlano = $this->tokenPlanoDeSesion($invitacion);
        abort_unless($tokenPlano !== null, 410, 'El código ya no está disponible en esta sesión. Genera uno nuevo (regenerar).');

        $formato = $request->string('formato')->toString() === 'png' ? 'png' : 'svg';

        return $formato === 'png'
            ? response($this->invitaciones->qrPng($tokenPlano), 200, ['Content-Type' => 'image/png'])
            : response($this->invitaciones->qrSvg($tokenPlano), 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * Minutos que el token plano sigue disponible en la sesión de RH tras
     * crear/regenerar la invitación (para poder ver el QR, copiar la liga y
     * descargar SVG/PNG sin que el usuario tenga que hacerlo todo en una
     * sola respuesta). Pasado ese tiempo — o en cualquier otra sesión/
     * navegador — ya no hay forma de recuperarlo: hay que regenerar.
     */
    private const MINUTOS_VIGENCIA_TOKEN_EN_SESION = 5;

    private function claveSesionToken(IncorporacionInvitacion $invitacion): string
    {
        return "incorporacion_invitacion_token_{$invitacion->id}";
    }

    /**
     * Guarda el token plano en sesión (normal, no flash) junto con su propio
     * vencimiento explícito: usar una ventana de tiempo real es más simple
     * y predecible que encadenar `session()->flash()/keep()` request por
     * request, y no depende de cuántas veces se recargue la página.
     */
    private function guardarTokenPlanoEnSesion(IncorporacionInvitacion $invitacion, string $token): void
    {
        session()->put($this->claveSesionToken($invitacion), [
            'token' => $token,
            'expira_en' => now()->addMinutes(self::MINUTOS_VIGENCIA_TOKEN_EN_SESION)->timestamp,
        ]);
    }

    private function tokenPlanoDeSesion(IncorporacionInvitacion $invitacion): ?string
    {
        $clave = $this->claveSesionToken($invitacion);
        $dato = session($clave);

        if ($dato === null) {
            return null;
        }

        if (now()->timestamp > $dato['expira_en']) {
            session()->forget($clave);

            return null;
        }

        return $dato['token'];
    }
}
