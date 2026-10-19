<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Jobs\AplicarCargaInicialJob;
use App\Models\MigracionExpedientes;
use App\Models\User;
use App\Services\CargaInicial\CargaInicialPeopleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Importar datos → «Carga inicial PEOPLE». Delgado: todo
 * vive en CargaInicialPeopleService. Como inicializa colaboradores/NAS y
 * headcount a la vez, exige AMBOS permisos (expedientes.migrar y
 * headcount.importar) en cada endpoint, no solo en la UI.
 */
class CargaInicialController extends Controller
{
    /** @var list<string> */
    public const PERMISOS = ['expedientes.migrar', 'headcount.importar'];

    public function __construct(private readonly CargaInicialPeopleService $carga) {}

    public static function puede(?User $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        foreach (self::PERMISOS as $permiso) {
            if (! $usuario->can($permiso)) {
                return false;
            }
        }

        return true;
    }

    public function index(Request $request): Response
    {
        $this->exigir($request);
        $corrida = $request->filled('corrida')
            ? MigracionExpedientes::query()->where('tipo', CargaInicialPeopleService::TIPO)->where('id', $request->integer('corrida'))->with('usuario:id,name,apellidos')->first()
            : null;

        return Inertia::render('Administracion/ImportarDatos/CargaInicial', [
            'corrida' => $corrida !== null ? $this->carga->corrida($corrida, true) : null,
            'historial' => $this->carga->historial(),
        ]);
    }

    public function analizar(Request $request): RedirectResponse
    {
        $this->exigir($request);
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ], [
            'archivo.required' => 'Selecciona el archivo de carga inicial (.xlsx).',
            'archivo.mimes' => 'El archivo debe ser un Excel .xlsx.',
        ]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $migracion = $this->carga->analizar((string) $archivo->getRealPath(), $archivo->getClientOriginalName(), $request->user());

        return redirect()->route('administracion.importar-datos.carga-inicial.index', ['corrida' => $migracion->id])
            ->with('toast', ['type' => 'success', 'message' => 'Análisis listo: revisa el resumen antes de aplicar. No se modificó nada.']);
    }

    public function aplicar(Request $request, MigracionExpedientes $migracion): RedirectResponse
    {
        $this->exigir($request);
        $this->exigirCarga($migracion);
        $request->validate(['confirmacion' => ['accepted']], ['confirmacion.accepted' => 'Confirma que revisaste el análisis antes de aplicar.']);

        abort_if(in_array($migracion->estado, ['aplicando', 'en_cola'], true), 422, 'Esta carga ya está en proceso.');
        $migracion->update(['estado' => 'en_cola', 'etapa' => 'En cola', 'progreso' => 0, 'error' => null]);
        AplicarCargaInicialJob::dispatch($migracion->id, (int) $request->user()?->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'Carga inicial en proceso. Puedes seguir el avance en esta pantalla.']);
    }

    public function estado(Request $request, MigracionExpedientes $migracion): JsonResponse
    {
        $this->exigir($request);
        $this->exigirCarga($migracion);

        return response()->json(['data' => $this->carga->corrida($migracion->loadMissing('usuario:id,name,apellidos'), false)]);
    }

    private function exigir(Request $request): void
    {
        abort_unless(self::puede($request->user()), 403);
    }

    private function exigirCarga(MigracionExpedientes $migracion): void
    {
        abort_unless($migracion->tipo === CargaInicialPeopleService::TIPO, 404);
    }
}
