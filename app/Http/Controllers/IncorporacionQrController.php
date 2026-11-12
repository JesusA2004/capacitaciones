<?php

namespace App\Http\Controllers;

use App\Exceptions\Incorporacion\InvitacionInvalidaException;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\RegistroAppService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla publica del QR de incorporacion (sin auth, sin sesion): es donde
 * cae el navegador al escanear el QR que RH genera/descarga (ver
 * config('incorporacion.qr_url_base') y
 * App\Services\Incorporacion\IncorporacionInvitacionService::urlQr()). Informa
 * el estado de la invitacion, intenta abrir la app movil via deep link y
 * ofrece «Registrarme aquí en la web» (quien no puede instalar la APK:
 * iPhone, Mac) — ese registro lo hace App\Http\Controllers\Registro\RegistroQrWebController
 * con el mismo servicio que la app. Mostrar esta pantalla nunca marca el QR
 * como usado.
 *
 * Un token invalido/vencido/revocado/usado nunca produce 404 ni 500: siempre
 * se responde 200 con la misma pagina, en el estado "invalida", para que el
 * colaborador vea un mensaje controlado en vez de un error del navegador.
 */
class IncorporacionQrController extends Controller
{
    /** Deep link que intenta abrir la app móvil de colaboradores. */
    private const APP_SCHEME = 'mrlanapeople://incorporacion/qr/';

    public function __construct(
        private readonly IncorporacionInvitacionService $invitaciones,
        private readonly RegistroAppService $registro,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $usuario = $request->user();
        // Ya creó su cuenta en la web y vuelve a escanear: «Continuar mi registro».
        $registroEnCurso = $usuario !== null && $this->registro->aplica($usuario);

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
                'prellenado' => null,
                'registroEnCurso' => $registroEnCurso,
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
            // Lo que RH ya capturó al generar el QR (igual que la app).
            'prellenado' => [
                'nombre' => $invitacion->nombre_prellenado,
                'email' => $invitacion->email,
                'telefono' => $invitacion->telefono,
            ],
            'registroEnCurso' => $registroEnCurso,
        ]);
    }
}
