<?php

namespace App\Http\Controllers\Multimedia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Multimedia\IniciarCargaResumibleRequest;
use App\Http\Requests\Multimedia\SubirBloqueCargaRequest;
use App\Models\CargaMultimedia;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\CargaResumibleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesion de carga de video por bloques reanudable. Endpoints en JSON (no
 * Inertia): el frontend orquesta el envio de bloques con fetch/XHR para
 * poder pausar, reanudar y mostrar progreso real por bloque.
 *
 * La carga se identifica por `identificador` (UUID publico), nunca por el
 * `id` autoincremental, y toda operacion se re-consulta scopeada al usuario
 * autenticado (una carga nunca es visible/operable por otro usuario).
 */
class CargaResumibleController extends Controller
{
    public function __construct(private readonly CargaResumibleService $service) {}

    public function iniciar(IniciarCargaResumibleRequest $request): JsonResponse
    {
        $carga = $this->service->iniciar($request->user(), [
            'nombre_original' => $request->string('nombre_original')->toString(),
            'tipo' => $request->string('tipo')->toString(),
            'tamano_total_bytes' => $request->integer('tamano_total_bytes'),
            'hash_esperado' => $request->input('hash_esperado'),
        ]);

        return response()->json($this->presentar($carga));
    }

    public function bloque(SubirBloqueCargaRequest $request, string $identificador): JsonResponse
    {
        $carga = $this->cargaDelUsuario($request, $identificador);

        try {
            $carga = $this->service->recibirBloque(
                $carga,
                (int) $request->validated('numero_bloque'),
                $request->file('bloque'),
            );
        } catch (\RuntimeException $excepcion) {
            return response()->json(['message' => $excepcion->getMessage()], 422);
        }

        return response()->json($this->presentar($carga));
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        return response()->json($this->presentar($this->cargaDelUsuario($request, $identificador)));
    }

    public function pausar(Request $request, string $identificador): JsonResponse
    {
        $carga = $this->service->pausar($this->cargaDelUsuario($request, $identificador));

        return response()->json($this->presentar($carga));
    }

    public function reanudar(Request $request, string $identificador): JsonResponse
    {
        $carga = $this->service->reanudar($this->cargaDelUsuario($request, $identificador));

        return response()->json($this->presentar($carga));
    }

    public function cancelar(Request $request, string $identificador): JsonResponse
    {
        $carga = $this->service->cancelar($this->cargaDelUsuario($request, $identificador));

        return response()->json($this->presentar($carga));
    }

    private function cargaDelUsuario(Request $request, string $identificador): CargaMultimedia
    {
        abort_unless($request->user()?->can('create', RecursoMultimedia::class), 403);

        return CargaMultimedia::query()
            ->where('identificador', $identificador)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CargaMultimedia $carga): array
    {
        return [
            'identificador' => $carga->identificador,
            'estado' => $carga->estado->value,
            'tamano_total_bytes' => $carga->tamano_total_bytes,
            'tamano_bloque_bytes' => $carga->tamano_bloque_bytes,
            'total_bloques' => $carga->total_bloques,
            'bytes_recibidos' => $carga->bytes_recibidos,
            'bloques_recibidos' => $carga->bloques_recibidos ?? [],
            'porcentaje' => $carga->porcentaje(),
            'error' => $carga->error,
            'recurso_multimedia_id' => $carga->recurso_multimedia_id,
        ];
    }
}
