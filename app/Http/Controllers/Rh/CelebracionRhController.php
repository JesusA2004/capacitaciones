<?php

namespace App\Http\Controllers\Rh;

use App\Enums\TipoCelebracion;
use App\Http\Controllers\Controller;
use App\Models\BirthdayGreeting;
use App\Models\CelebracionConfiguracion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\FechasCelebracion;
use App\Services\Celebraciones\TarjetaAniversarioService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosStorageService;
use App\Services\Cumpleanos\MuroCumpleanosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Panel RH de celebraciones (docs/CELEBRACIONES.md): Aniversarios (misma
 * experiencia que Cumpleaños) y las acciones comunes a ambos tipos —
 * enviar al colaborador, avisar a todos, generar/descargar tarjeta, abrir o
 * cerrar la recepción de mensajes. Toda la regla vive en CelebracionService.
 */
class CelebracionRhController extends Controller
{
    public function __construct(
        private readonly CelebracionService $celebraciones,
        private readonly TarjetaAniversarioService $tarjetaAniversario,
        private readonly BirthdayCardService $tarjetaCumpleanos,
        private readonly MuroCumpleanosService $muro,
        private readonly CumpleanosStorageService $storage,
    ) {}

    public function aniversarios(Request $request): Response
    {
        abort_unless($request->user()->can('celebraciones.ver'), 403);

        $filtros = $this->filtros($request);
        $hoy = FechasCelebracion::hoy();
        $desde = $request->date('desde') ?? $hoy->copy()->addDay();
        $hasta = $request->date('hasta') ?? $hoy->copy()->addDays(30);

        return Inertia::render('Rh/Aniversarios/Index', [
            'hoy' => $this->celebraciones->filasAniversarios($request->user(), $hoy, $hoy, $filtros),
            'proximos' => $this->celebraciones->filasAniversarios($request->user(), Carbon::parse($desde->toDateString()), Carbon::parse($hasta->toDateString()), $filtros),
            'rango' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'filtros' => $filtros,
            'catalogos' => [
                'empresas' => Empresa::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
            'permisos' => $this->permisos($request),
            'configuracionActiva' => CelebracionConfiguracion::de(TipoCelebracion::AniversarioLaboral)->activo,
        ]);
    }

    public function enviar(Request $request, Colaborador $colaborador, string $tipo): RedirectResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('enviar', $celebracion);

        $this->celebraciones->enviarAlColaborador($celebracion, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Felicitación enviada a %s.', $colaborador->nombreCompleto())]);
    }

    public function avisarATodos(Request $request, Colaborador $colaborador, string $tipo): RedirectResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('enviar', $celebracion);

        $resultado = $this->celebraciones->avisarATodos($celebracion, $request->user());

        if (! $resultado['avisado']) {
            return back()->with('toast', [
                'type' => 'info',
                'message' => sprintf('Aviso general enviado el %s.', $resultado['celebracion']->avisada_todos_at?->timezone(FechasCelebracion::ZONA)->format('d/m/Y \a \l\a\s H:i')),
            ]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Se avisó a %d colaborador(es).', $resultado['destinatarios'])]);
    }

    public function regenerarTarjeta(Request $request, Colaborador $colaborador, string $tipo): RedirectResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('gestionar', $celebracion);

        $this->celebraciones->tarjeta($celebracion, regenerar: true);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tarjeta generada con los datos actuales.']);
    }

    public function descargarTarjeta(Request $request, Colaborador $colaborador, string $tipo): HttpResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('gestionar', $celebracion);
        $celebracion = $this->celebraciones->tarjeta($celebracion);

        return $celebracion->esAniversario()
            ? $this->tarjetaAniversario->descargar($celebracion, enLinea: $request->boolean('ver'))
            : $this->tarjetaCumpleanos->descargar($celebracion);
    }

    public function recepcion(Request $request, BirthdayGreeting $celebracion): RedirectResponse
    {
        $this->authorize('enviar', $celebracion);

        $celebracion->muroAbierto()
            ? $this->muro->cerrar($celebracion)
            : $this->muro->abrir($celebracion, $request->user(), avisar: false);

        return back()->with('toast', ['type' => 'success', 'message' => $celebracion->muroAbierto() ? 'Recepción de felicitaciones abierta.' : 'Recepción de felicitaciones cerrada.']);
    }

    public function configuracion(Request $request): Response
    {
        abort_unless($request->user()->can('celebraciones.gestionar'), 403);
        $configuracion = CelebracionConfiguracion::de(TipoCelebracion::AniversarioLaboral);

        return Inertia::render('Rh/Aniversarios/Configuracion', [
            'configuracion' => [
                'activo' => $configuracion->activo,
                'mensaje' => $configuracion->mensaje,
                'auto_enviar_colaborador' => $configuracion->auto_enviar_colaborador,
                'tiene_fondo' => $this->storage->existe($this->tarjetaAniversario->rutaFondo()),
            ],
            'mensajePredeterminado' => (string) config('celebraciones.aniversario.mensaje'),
        ]);
    }

    public function guardarConfiguracion(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('celebraciones.gestionar'), 403);

        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
            'mensaje' => ['required', 'string', 'max:600'],
            'auto_enviar_colaborador' => ['required', 'boolean'],
            'fondo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=600,min_height=750'],
            'quitar_fondo' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('fondo')) {
            $this->storage->guardar($this->tarjetaAniversario->rutaFondo(), (string) file_get_contents((string) $request->file('fondo')?->getRealPath()));
        } elseif ($request->boolean('quitar_fondo')) {
            $this->storage->eliminar($this->tarjetaAniversario->rutaFondo());
        }

        CelebracionConfiguracion::de(TipoCelebracion::AniversarioLaboral)->update([
            'activo' => (bool) $datos['activo'],
            'mensaje' => $datos['mensaje'],
            'auto_enviar_colaborador' => (bool) $datos['auto_enviar_colaborador'],
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Configuración guardada. Las tarjetas nuevas o regeneradas usarán estos datos.']);
    }

    /**
     * Vista previa real de la tarjeta con datos de EJEMPLO.
     */
    public function vistaPrevia(Request $request): HttpResponse
    {
        abort_unless($request->user()->can('celebraciones.gestionar'), 403);

        $png = $this->tarjetaAniversario->renderPng('Nombre de Ejemplo Apellido', 6, 'Sucursal de ejemplo', $this->tarjetaAniversario->mensaje(6, 'Nombre de Ejemplo', 'Sucursal de ejemplo'));

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    private function eventoDeHoy(Colaborador $colaborador, string $tipo): BirthdayGreeting
    {
        $tipoCelebracion = TipoCelebracion::tryFrom($tipo);
        abort_if($tipoCelebracion === null, 404);

        $evento = $this->celebraciones->delDia($colaborador, $tipoCelebracion);

        if ($evento === null) {
            throw ValidationException::withMessages([
                'celebracion' => $tipoCelebracion === TipoCelebracion::Cumpleanos
                    ? 'Hoy no es el cumpleaños de esta persona.'
                    : 'Hoy no es el aniversario laboral de esta persona.',
            ]);
        }

        return $evento;
    }

    /**
     * @return array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, busqueda: string|null}
     */
    private function filtros(Request $request): array
    {
        return [
            'empresa_id' => $request->integer('empresa_id') ?: null,
            'sucursal_id' => $request->integer('sucursal_id') ?: null,
            'departamento_id' => $request->integer('departamento_id') ?: null,
            'busqueda' => $request->string('busqueda')->toString() ?: null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permisos(Request $request): array
    {
        $usuario = $request->user();

        return [
            'gestionar' => $usuario->can('celebraciones.gestionar'),
            'enviar' => $usuario->can('celebraciones.enviar'),
            'moderar' => $usuario->can('celebraciones.moderar'),
        ];
    }
}
