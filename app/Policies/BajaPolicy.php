<?php

namespace App\Policies;

use App\Models\Baja;
use App\Models\User;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\BajaService;

/**
 * Permisos del módulo de bajas (docs/BAJAS_Y_CAUSALES.md):
 *
 *  - Gerente / jefe directo / regional (bajas.solicitar + bajas.ver): crea y
 *    envía solicitudes de su personal, adjunta documentos, consulta sus
 *    bajas. No cierra ni cambia la causal después de enviarla.
 *  - RH (bajas.gestionar): valida, rechaza, cambia causal con motivo,
 *    cierra, reabre; ve todo.
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

    public function revisarJuridico(User $user, Baja $baja): bool
    {
        return $user->can(BajaService::PERMISO_JURIDICO) && $this->view($user, $baja);
    }

    /**
     * Rechazar: RH o Jurídico (el servicio decide según el estatus).
     */
    public function rechazar(User $user, Baja $baja): bool
    {
        return $this->gestionar($user, $baja) || $this->revisarJuridico($user, $baja);
    }

    public function verTablero(User $user): bool
    {
        return $user->can(BajaService::PERMISO_TABLERO);
    }
}
