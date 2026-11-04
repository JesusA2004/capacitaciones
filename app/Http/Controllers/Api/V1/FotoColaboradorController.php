<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de perfil de OTRA persona para la app (Bearer token): la URL
 * `foto_url` que traen el directorio, el equipo, solicitudes, bajas, etc.
 * La regla de quién la ve vive en FotoColaboradorService::puedeVerFoto
 * (alcance de expedientes + jerarquía directa) y se vuelve a evaluar aquí:
 * armar la URL a mano con otro id da 403 (sin IDOR). Nunca expone
 * `foto_path`. Ver docs/FOTO_EXPEDIENTE.md.
 */
class FotoColaboradorController extends Controller
{
    public function __construct(private readonly FotoColaboradorService $fotos) {}

    public function show(Request $request, Colaborador $colaborador): StreamedResponse
    {
        abort_unless($this->fotos->puedeVerFoto($request->user(), $colaborador), 403, 'No puedes ver la foto de esta persona.');

        return $this->fotos->respuestaOficial($colaborador);
    }
}
