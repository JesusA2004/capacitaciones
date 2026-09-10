<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Felicitacion de cumpleanos del colaborador autenticado, consumida por la
 * app movil (ver docs/API_MOVIL.md). Solo expone la felicitacion del propio
 * usuario del token — nunca la de otro colaborador — y nunca la ruta fisica
 * de la tarjeta (imagen servida por streaming a traves de imagen()).
 */
class ColaboradorCumpleanosController extends Controller
{
    public function __construct(
        private readonly CumpleanosService $cumpleanos,
        private readonly BirthdayCardService $tarjetas,
    ) {}

    /**
     * Felicitacion vigente del colaborador autenticado: solo existe si hoy
     * es su cumpleanos (mismo dia/mes que fecha_nacimiento). No genera nada
     * nuevo — eso lo hace el command diario cumpleanos:enviar-felicitaciones
     * — solo consulta lo ya generado para hoy.
     *
     * Responde siempre 200: `{"data": null}` cuando no es su cumpleanos, en
     * vez de 404, para que la app no lo trate como un error de red — es un
     * estado normal y esperado casi todos los dias del anio. Ver
     * docs/API_MOVIL.md.
     */
    public function felicitacionActual(Request $request): JsonResponse
    {
        $colaborador = $request->user();
        $hoy = $this->cumpleanos->cumpleanosDeHoy()->firstWhere('id', $colaborador->id);

        if ($hoy === null) {
            return response()->json(['data' => null]);
        }

        $greeting = $this->tarjetas->generar($colaborador, now());

        return response()->json([
            'data' => [
                'id' => $greeting->id,
                'fecha' => $greeting->fecha->toDateString(),
                'titulo' => '¡Feliz cumpleaños!',
                'mensaje' => 'Hoy celebramos tu vida y todo lo que aportas a MR. LANA. Que tengas un gran día.',
                'frase' => $greeting->frase,
                'card_url' => $greeting->card_path !== null ? route('api.v1.colaborador.cumpleanos.felicitacion-actual.imagen') : null,
            ],
        ]);
    }

    /**
     * Imagen de la felicitacion vigente del colaborador autenticado. 404 si
     * hoy no es su cumpleanos o todavia no hay tarjeta generada — nunca
     * expone la ruta fisica del archivo.
     */
    public function imagen(Request $request): HttpResponse
    {
        $colaborador = $request->user();
        $hoy = $this->cumpleanos->cumpleanosDeHoy()->firstWhere('id', $colaborador->id);

        abort_if($hoy === null, 404);

        $greeting = $this->tarjetas->generar($colaborador, now());

        return $this->tarjetas->descargar($greeting);
    }
}
