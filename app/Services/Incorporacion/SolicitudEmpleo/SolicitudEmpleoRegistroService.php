<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;
use Illuminate\Validation\ValidationException;

/**
 * La Solicitud de empleo + Estudio socioeconómico dentro del registro en la
 * app (RegistroAppService::estado()/finalizar()): bloque de avance por
 * sección y exigencia de completitud al cerrar.
 *
 * Compatibilidad: con config incorporacion.registro_app.exigir_solicitud_empleo
 * = «cliente» (por defecto) solo se exige a la app que declara implementar
 * estas pantallas (cabecera X-People-Solicitud-Empleo: 1). Las versiones ya
 * instaladas cierran su registro como siempre y RH completa lo faltante en
 * la web; el contrato nunca se genera sin ambos documentos
 * (RequisitoDocumentosIngresoService).
 */
class SolicitudEmpleoRegistroService
{
    public const CABECERA_CLIENTE = 'X-People-Solicitud-Empleo';

    public function __construct(
        private readonly PerfilIngresoService $perfil,
        private readonly RegistroAppService $registro,
    ) {}

    /**
     * Modo de captura de la PROPIA persona (web y app, mismo criterio):
     * durante su registro captura y ajusta todo; después solo llena lo que
     * sigue vacío (lo ya capturado se corrige por solicitud de corrección).
     */
    public function modoPropio(User $usuario): string
    {
        return $this->registro->aplica($usuario) ? PerfilIngresoService::ORIGEN_APP : PerfilIngresoService::ORIGEN_COMPLETAR;
    }

    /**
     * Estado por sección para la propia persona (GET web y API).
     *
     * @return array<string, mixed>
     */
    public function estadoPropio(User $usuario, Colaborador $colaborador): array
    {
        $modo = $this->modoPropio($usuario);

        return [
            'registro_abierto' => $modo === PerfilIngresoService::ORIGEN_APP,
            'exigida' => $this->exigidaEnRegistro(),
            'modo' => $modo,
            ...$this->perfil->estado($colaborador, $modo),
        ];
    }

    /**
     * Guarda una sección de la propia persona con el modo que le toca.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>|null  $noAplica
     */
    public function guardarPropio(User $usuario, Colaborador $colaborador, string $seccion, array $datos, ?array $noAplica): void
    {
        $this->perfil->guardarSeccion($colaborador, $seccion, $datos, $noAplica, $usuario, $this->modoPropio($usuario));
    }

    /**
     * ¿A la persona le falta algo que ELLA puede llenar? (pendiente del portal).
     */
    public function tienePendientePropio(Colaborador $colaborador): bool
    {
        foreach ($this->perfil->completitud($colaborador)['faltantes'] as $faltante) {
            if ($faltante['origen'] === 'solicitud') {
                return true;
            }
        }

        return false;
    }

    public function exigidaEnRegistro(): bool
    {
        return match ((string) config('incorporacion.registro_app.exigir_solicitud_empleo', 'cliente')) {
            'siempre' => true,
            'nunca' => false,
            default => request()->header(self::CABECERA_CLIENTE) === '1',
        };
    }

    /**
     * Bloque `solicitud_empleo` de RegistroAppService::estado().
     *
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $colaborador): array
    {
        $completitud = $this->perfil->completitud($colaborador);

        return [
            'exigida' => $this->exigidaEnRegistro(),
            'completo' => $completitud['completo'],
            'porcentaje' => $completitud['porcentaje'],
            'secciones' => array_map(fn (array $s): array => [
                'clave' => $s['clave'],
                'titulo' => $s['titulo'],
                'completa' => $s['completa'],
                'porcentaje' => $s['porcentaje'],
                'faltantes' => $s['faltantes'],
            ], $completitud['secciones']),
            'documentos' => array_values(array_map(fn (array $d): array => [
                'clave' => $d['clave'],
                'nombre' => $d['nombre'],
                'completo' => $d['completo'],
                'mensaje' => $d['mensaje'],
            ], $completitud['documentos'])),
            'pendientes' => $this->pendientes($completitud['secciones']),
        ];
    }

    /**
     * ¿Puede cerrar el registro respecto a la solicitud? (true si no se exige).
     */
    public function completaParaRegistro(Colaborador $colaborador): bool
    {
        return ! $this->exigidaEnRegistro() || $this->perfil->completitud($colaborador)['completo'];
    }

    /**
     * @throws ValidationException Con lo que falta, sección por sección.
     */
    public function exigirParaRegistro(Colaborador $colaborador): void
    {
        if (! $this->exigidaEnRegistro()) {
            return;
        }

        $completitud = $this->perfil->completitud($colaborador);

        if ($completitud['completo']) {
            return;
        }

        throw ValidationException::withMessages([
            'solicitud_empleo' => sprintf('Completa tu solicitud de empleo y estudio socioeconómico. Falta: %s.', implode('; ', $this->pendientes($completitud['secciones']))),
        ]);
    }

    /**
     * «Domicilio: Entre calle, Y calle».
     *
     * @param  list<array{clave: string, titulo: string, completa: bool, faltantes: list<string>}>  $secciones
     * @return list<string>
     */
    private function pendientes(array $secciones): array
    {
        $pendientes = [];

        foreach ($secciones as $seccion) {
            if (! $seccion['completa']) {
                $pendientes[] = sprintf('%s: %s', $seccion['titulo'], implode(', ', $seccion['faltantes']));
            }
        }

        return $pendientes;
    }
}
