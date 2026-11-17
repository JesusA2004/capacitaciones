<?php

namespace App\Policies;

use App\Models\NineBoxCalibracion;
use App\Models\User;

/**
 * Nine Box (docs/NINE_BOX.md):
 *   - nine_box.ver: entrar a la matriz (qué gestores ve lo decide el
 *     alcance del módulo «nine_box», NineBoxService).
 *   - nine_box.calibrar: capturar el potencial (además debe alcanzar a la
 *     persona y nunca a sí mismo: CalibracionNineBoxService).
 *   - nine_box.configurar: pesos, metas y cortes.
 */
class NineBoxCalibracionPolicy
{
    public function ver(User $user): bool
    {
        return $user->can('nine_box.ver');
    }

    public function calibrar(User $user): bool
    {
        return $user->can('nine_box.ver') && $user->can('nine_box.calibrar');
    }

    public function configurar(User $user): bool
    {
        return $user->can('nine_box.ver') && $user->can('nine_box.configurar');
    }

    public function view(User $user, NineBoxCalibracion $calibracion): bool
    {
        return $this->ver($user);
    }
}
