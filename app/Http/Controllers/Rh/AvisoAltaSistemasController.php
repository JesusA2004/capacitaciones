<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reenvío manual del correo «Alta para asignar usuarios» a Sistemas desde la
 * ficha del candidato. La regla (permiso candidatos.contratar + alcance) vive
 * en AvisoAltaSistemasService::reenviar().
 */
class AvisoAltaSistemasController extends Controller
{
    public function __construct(private readonly AvisoAltaSistemasService $avisos) {}

    public function reenviar(Request $request, Candidato $candidato): RedirectResponse
    {
        $colaborador = $candidato->colaborador_id !== null
            ? Colaborador::withTrashed()->where('id', $candidato->colaborador_id)->first()
            : null;

        if ($colaborador === null) {
            throw ValidationException::withMessages(['aviso_sistemas' => 'Este candidato aún no entra a contratación: no hay datos que enviar a Sistemas.']);
        }

        $usuario = $request->user();

        if ($usuario === null) {
            abort(403);
        }

        $this->avisos->reenviar($colaborador, $usuario);

        return back()->with('success', 'Reenvío del aviso a Sistemas en cola.');
    }
}
