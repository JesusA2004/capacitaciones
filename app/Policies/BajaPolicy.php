<?php

namespace App\Policies;

use App\Models\Baja;
use App\Models\User;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\BajaService;

/**
 * Permisos del módulo «Bajas» (docs/BAJAS_Y_CAUSALES.md). Solo entran el
 * gerente (su personal, por alcance), RH y super admin; además, de solo
 * lectura, Jurídico y Contraloría:
 *
 *  - Gerente / regional (bajas.solicitar + bajas.ver): registra la baja de
 *    su personal (queda aplicada de inmediato), completa sus documentos y
 *    consulta sus bajas. No cierra ni cambia la causal.
 *  - RH (bajas.gestionar): registra, cambia causal con motivo, corrige
 *    datos, envía a Jurídico, cierra, reabre y marca «No procede»; ve todo.
 *  - Jurídico (bajas.revisar_juridico): revisa/aprueba las que lo requieren
 *    y consulta las rescisiones; no cierra.
 *  - Contraloría (bajas.consulta_contraloria): consulta RE-02 / RE-03, solo
 *    lectura.
 *  - Dirección General / Comercial: solo el tablero (bajas.tablero.ver).
 *
 * Las transiciones vuelven a validar permiso + estatus en BajaService.
 */
class BajaPolicy
{
    public function __construct(private readonly BajaConsultaService $consulta) {}

    public function viewAny(User $user): bool
    {
        return $this->consulta->puedeEntrar($user);
    }

    public function view(User $user, Baja $baja): bool
    {
        return $this->consulta->puedeVer($user, $baja);
    }

    public function create(User $user): bool
    {
        return $user->can(BajaService::PERMISO_SOLICITAR) || $user->can(BajaService::PERMISO_GESTIONAR);
    }

    public function update(User $user, Baja $baja): bool
    {
        return $this->create($user) && $this->view($user, $baja);
    }

    public function gestionar(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_GESTIONAR) && $this->view($user, $baja);
    }

    /*
     * Acciones de RH sobre una baja, cada una con su permiso (Roles y
     * permisos → Bajas; heredan de bajas.gestionar al crearse) y siempre
     * dentro de lo que el usuario puede ver.
     */
    public function cambiarCausal(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_CAMBIAR_CAUSAL) && $this->view($user, $baja);
    }

    public function editar(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_EDITAR) && $this->view($user, $baja);
    }

    public function enviarJuridico(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_ENVIAR_JURIDICO) && $this->view($user, $baja);
    }

    public function cerrar(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_CERRAR) && $this->view($user, $baja);
    }

    public function reabrir(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_REABRIR) && $this->view($user, $baja);
    }

    public function cancelar(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_CANCELAR) && $this->view($user, $baja);
    }

    /** PDF del finiquito propuesto antes de registrar (montos: RH por defecto). */
    public function vistaPreviaFiniquito(User $user): bool
    {
        return $this->create($user) && $user->can(BajaService::PERMISO_PREVIA_FINIQUITO);
    }

    public function revisarJuridico(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_JURIDICO) && $this->view($user, $baja);
    }

    public function verTablero(User $user): bool
    {
        return $user->can(BajaService::PERMISO_TABLERO);
    }
}
