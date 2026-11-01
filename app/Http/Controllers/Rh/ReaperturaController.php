<?php

namespace App\Http\Controllers\Rh;

use App\Enums\TipoReapertura;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reaperturas\ReabrirDecisionRequest;
use App\Services\Reaperturas\ReaperturaService;
use Illuminate\Http\RedirectResponse;

class ReaperturaController extends Controller
{
    public function __construct(private readonly ReaperturaService $reaperturas) {}

    public function store(ReabrirDecisionRequest $request, string $tipo, int $id): RedirectResponse
    {
        $this->reaperturas->reabrir(TipoReapertura::from($tipo), $id, $request->user(), (string) $request->validated('motivo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Se reabrió la decisión y quedó en el historial.']);
    }
}
