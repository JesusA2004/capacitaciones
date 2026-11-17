<?php

namespace App\Http\Controllers\Rh;

use App\Exceptions\CorreccionConflictoException;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FechasLaboralesService;
use App\Services\Correcciones\CorreccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fechas laborales (docs/FECHAS_LABORALES.md). Delgado: el cálculo vive en
 * FechasLaboralesService y la escritura (motivo, vista previa, historial)
 * en CorreccionService, dominio `fechas_laborales`.
 */
class FechasLaboralesController extends Controller
{
    public function __construct(
        private readonly FechasLaboralesService $fechas,
        private readonly CorreccionService $correcciones,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /** Sección «Fechas laborales» del expediente (JSON). */
    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_if($usuario === null, 401);
        // Su propio expediente no muestra fechas de periodo de prueba.
        abort_unless($usuario->colaborador_id !== $colaborador->id && $this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        return response()->json(['data' => $this->fechas->resumen($colaborador, $usuario)]);
    }

    /** Captura masiva: activos con fechas faltantes, edición en línea. */
    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_if($usuario === null, 401);
        abort_unless($usuario->can(FechasLaboralesService::PERMISO_EDITAR), 403);

        $filtros = [
            'busqueda' => (string) $request->query('busqueda', ''),
            'sucursal_id' => (string) $request->query('sucursal_id', ''),
            'puesto_id' => (string) $request->query('puesto_id', ''),
            'solo_faltantes' => (string) $request->query('solo_faltantes', '1'),
        ];

        return Inertia::render('Rh/FechasLaborales/Index', [
            'filas' => $this->fechas->listadoCaptura($usuario, $filtros),
            'filtros' => $filtros,
            'sucursales' => $this->alcance->sucursalesParaFiltro($usuario, 'expedientes')
                ->map(fn ($s): array => ['id' => (int) $s->getKey(), 'nombre' => (string) $s->getAttribute('nombre')])->values(),
            'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'meses_periodo_prueba'])
                ->map(fn (Puesto $p): array => ['id' => $p->id, 'nombre' => $p->nombre, 'meses_periodo_prueba' => $p->meses_periodo_prueba])->values(),
        ]);
    }

    /**
     * Guarda varias filas de la captura masiva con UN motivo. Cada fila es
     * su propia corrección (historial por persona); una que falla no detiene
     * a las demás.
     */
    public function lote(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_if($usuario === null, 401);
        abort_unless($usuario->can(FechasLaboralesService::PERMISO_EDITAR), 403);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'filas' => ['required', 'array', 'min:1', 'max:100'],
            'filas.*.colaborador_id' => ['required', 'integer'],
            'filas.*.valores' => ['required', 'array'],
        ]);

        $guardados = [];
        $errores = [];

        foreach ($datos['filas'] as $fila) {
            $id = (int) $fila['colaborador_id'];
            $valores = array_intersect_key((array) $fila['valores'], array_flip(FechasLaboralesService::CAMPOS));

            try {
                $actual = $this->correcciones->describir('fechas_laborales', $id, $usuario);
                $this->correcciones->aplicar('fechas_laborales', $id, $valores, (string) $datos['motivo'], (string) $actual['huella'], (string) Str::uuid(), [], $usuario);
                $guardados[] = $id;
            } catch (ValidationException $e) {
                $errores[] = ['colaborador_id' => $id, 'mensaje' => (string) collect($e->errors())->flatten()->first()];
            } catch (CorreccionConflictoException|HttpException $e) {
                $errores[] = ['colaborador_id' => $id, 'mensaje' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['guardados' => $guardados, 'errores' => $errores]]);
    }
}
