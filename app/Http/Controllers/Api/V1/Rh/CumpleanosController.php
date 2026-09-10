<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bandeja de cumpleanos para RH desde la app movil (docs/RH_MOBILE_API.md,
 * docs/CUMPLEANOS.md). Mismo criterio de alcance organizacional y de datos
 * minimos que el resto de la API RH movil: nunca regresa el anio de
 * nacimiento, y la edad solo si config('cumpleanos.show_age') esta activo.
 */
class CumpleanosController extends Controller
{
    public function __construct(
        private readonly CumpleanosService $cumpleanos,
        private readonly BirthdayCardService $tarjetas,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DocumentoStorageService $fotos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $periodo = $request->string('periodo')->toString() ?: 'mes';
        $mes = $request->integer('mes') ?: null;

        $filtros = array_filter([
            'sucursal_id' => $request->integer('sucursal_id') ?: null,
            'departamento_id' => $request->integer('departamento_id') ?: null,
            'busqueda' => $request->string('q')->toString() ?: null,
        ], fn ($valor) => $valor !== null);

        $colaboradores = $this->cumpleanos->colaboradoresPorPeriodo($periodo, $mes, $usuario, $filtros);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $page = max(1, (int) $request->integer('page', 1));
        $total = $colaboradores->count();
        $pagina = $colaboradores->forPage($page, $perPage)->values();

        $greetingsPorColaborador = BirthdayGreeting::query()
            ->whereIn('user_id', $pagina->pluck('id'))
            ->whereYear('fecha', Carbon::today()->year)
            ->get()
            ->keyBy('user_id');

        $hoy = Carbon::today();

        $data = $pagina->map(function (User $colaborador) use ($greetingsPorColaborador, $hoy) {
            /** @var BirthdayGreeting|null $greeting */
            $greeting = $greetingsPorColaborador->get($colaborador->id);

            return [
                'greeting_id' => $greeting?->id,
                'colaborador' => [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'numero_empleado' => $colaborador->numero_empleado,
                    'foto_url_api' => $colaborador->foto_path !== null
                        ? route('api.v1.rh.cumpleanos.foto', $colaborador)
                        : null,
                    'puesto' => $colaborador->puesto?->nombre,
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                    'departamento' => $colaborador->departamento?->nombre,
                ],
                'dia' => (int) $colaborador->fecha_nacimiento->format('d'),
                'es_hoy' => (int) $colaborador->fecha_nacimiento->format('n') === $hoy->month
                    && (int) $colaborador->fecha_nacimiento->format('j') === $hoy->day,
                'felicitacion_generada' => $greeting !== null,
                'enviada' => $greeting?->enviada_at !== null,
            ];
        });

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'hoy' => $this->cumpleanos->cumpleanosDeHoy($usuario)->count(),
                'proximos_7_dias' => $this->cumpleanos->proximosCumpleanos($usuario, 7)->count(),
                'proximos_30_dias' => $this->cumpleanos->proximosCumpleanos($usuario, 30)->count(),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Detalle de una felicitacion puntual: destino del push
     * {"type": "rh_cumpleanos", "resource_id": greeting_id}.
     */
    public function show(Request $request, BirthdayGreeting $greeting): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);

        $greeting->loadMissing(['colaborador.sucursalPrincipal:id,nombre', 'colaborador.departamento:id,nombre', 'colaborador.puesto:id,nombre']);
        abort_unless($this->alcance->puedeVerUsuario($usuario, $greeting->colaborador), 404);

        return response()->json([
            'data' => [
                'greeting_id' => $greeting->id,
                'fecha' => $greeting->fecha->toDateString(),
                'frase' => $greeting->frase,
                'enviada' => $greeting->enviada_at !== null,
                'card_url' => $greeting->card_path !== null
                    ? route('api.v1.rh.cumpleanos.imagen', $greeting)
                    : null,
                'colaborador' => [
                    'id' => $greeting->colaborador->id,
                    'nombre' => $greeting->colaborador->nombreCompleto(),
                    'numero_empleado' => $greeting->colaborador->numero_empleado,
                    'foto_url_api' => $greeting->colaborador->foto_path !== null
                        ? route('api.v1.rh.cumpleanos.foto', $greeting->colaborador)
                        : null,
                    'puesto' => $greeting->colaborador->puesto?->nombre,
                    'sucursal' => $greeting->colaborador->sucursalPrincipal?->nombre,
                    'departamento' => $greeting->colaborador->departamento?->nombre,
                ],
            ],
        ]);
    }

    /** Foto del colaborador (nunca expone `foto_path`), acotada por alcance. */
    public function foto(Request $request, User $colaborador): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);
        abort_unless($this->alcance->puedeVerUsuario($usuario, $colaborador), 404);
        abort_unless($colaborador->foto_path !== null, 404);

        return $this->fotos->respuesta($colaborador->foto_path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
        ]);
    }

    /** Imagen de la tarjeta de felicitacion (nunca expone `card_path`). */
    public function imagen(Request $request, BirthdayGreeting $greeting): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.cumpleanos.ver'), 403);
        abort_unless($this->alcance->puedeVerUsuario($usuario, $greeting->colaborador), 404);

        return $this->tarjetas->descargar($greeting);
    }
}
