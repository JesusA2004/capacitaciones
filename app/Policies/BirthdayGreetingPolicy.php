<?php

namespace App\Policies;

use App\Enums\EstadoUsuario;
use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Celebraciones\FechasCelebracion;

/**
 * Celebraciones (App\Models\BirthdayGreeting = cumpleaños o aniversario).
 *
 * PRIVACIDAD de los mensajes (docs/CELEBRACIONES.md): los mensajes NO son un
 * muro público. Solo el homenajeado y quien tiene `celebraciones.moderar`
 * ven todos; cualquier otro colaborador solo ve el suyo. Esto se aplica en
 * las consultas del backend (MuroCumpleanosService::mensajesVisibles()),
 * nunca escondiendo en el frontend.
 */
class BirthdayGreetingPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Ver el evento: cualquier colaborador activo una vez publicado (se
     * avisó a todos / se abrió la recepción), el homenajeado siempre, y RH
     * con permiso dentro de su alcance.
     */
    public function view(User $usuario, BirthdayGreeting $celebracion): bool
    {
        if ($this->esHomenajeado($usuario, $celebracion) || $this->gestiona($usuario, $celebracion)) {
            return true;
        }

        return $this->colaboradorActivo($usuario) && $celebracion->muroPublicado();
    }

    public function verTodosLosMensajes(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $this->esHomenajeado($usuario, $celebracion) || $this->modera($usuario, $celebracion);
    }

    /**
     * Escribir SU felicitación: colaborador activo, no el homenajeado,
     * recepción abierta y dentro de los días visibles del evento.
     */
    public function escribir(User $usuario, BirthdayGreeting $celebracion): bool
    {
        $limite = FechasCelebracion::hoy()->subDays(max(0, (int) config('celebraciones.dias_visible', 3)));

        return $this->colaboradorActivo($usuario)
            && ! $this->esHomenajeado($usuario, $celebracion)
            && $celebracion->muroAbierto()
            && $celebracion->fecha->gte($limite);
    }

    /**
     * Enviar al homenajeado / avisar a todos / abrir-cerrar recepción.
     */
    public function enviar(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $usuario->can('celebraciones.enviar') && $this->enAlcance($usuario, $celebracion);
    }

    /**
     * Generar / regenerar la tarjeta.
     */
    public function gestionar(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $this->gestiona($usuario, $celebracion);
    }

    public function moderar(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $this->modera($usuario, $celebracion);
    }

    public function esHomenajeado(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $celebracion->colaborador_id;
    }

    private function gestiona(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return ($usuario->can('celebraciones.gestionar') || $usuario->can('rh.cumpleanos.ver')) && $this->enAlcance($usuario, $celebracion);
    }

    private function modera(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return ($usuario->can('celebraciones.moderar') || $usuario->can('rh.cumpleanos.muro.gestionar')) && $this->enAlcance($usuario, $celebracion);
    }

    private function enAlcance(User $usuario, BirthdayGreeting $celebracion): bool
    {
        return $this->alcance->puedeVerExpediente($usuario, $celebracion->colaborador);
    }

    private function colaboradorActivo(User $usuario): bool
    {
        return $usuario->colaborador?->estatus === EstadoUsuario::Activo;
    }
}
