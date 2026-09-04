<?php

namespace App\Http\Controllers;

use App\Exceptions\Incorporacion\InvitacionInvalidaException;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla publica del QR de incorporacion (sin auth, sin sesion): es donde
 * cae el navegador al escanear el QR que RH genera/descarga (ver
 * config('incorporacion.qr_url_base') y
 * App\Services\Incorporacion\IncorporacionInvitacionService::urlQr()). Solo
 * informa el estado de la invitacion e intenta abrir la app movil via deep
 * link — nunca registra ni marca el QR como usado, eso solo ocurre en
 * POST /api/v1/incorporacion/invitaciones/{token}/registrar.
 *
 * Un token invalido/vencido/revocado/usado nunca produce 404 ni 500: siempre
 * se responde 200 con la misma pagina, en el estado "invalida", para que el
 * colaborador vea un mensaje controlado en vez de un error del navegador.
 */
class IncorporacionQrController extends Controller
{
    /** Deep link que intenta abrir la app móvil de colaboradores. */
    private const APP_SCHEME = 'mrlanapeople://incorporacion/qr/';

    public function __construct(private readonly IncorporacionInvitacionService $invitaciones) {}

    public function show(string $token): Response
    {
        try {
            $invitacion = $this->invitaciones->validar($token);
        } catch (InvitacionInvalidaException $e) {
            return Inertia::render('Incorporacion/Qr', [
                'valida' => false,
                'estado' => $e->motivo,
                'message' => $e->getMessage(),
                'token' => $token,
                'appLink' => self::APP_SCHEME.$token,
                'codigoLegible' => null,
                'nombrePrellenado' => null,
            ]);
        }

        return Inertia::render('Incorporacion/Qr', [
            'valida' => true,
            'estado' => $invitacion->estado->value,
            'message' => null,
            'token' => $token,
            'appLink' => self::APP_SCHEME.$token,
            'codigoLegible' => $invitacion->codigo_legible,
            'nombrePrellenado' => $invitacion->nombre_prellenado,
        ]);
    }
}
