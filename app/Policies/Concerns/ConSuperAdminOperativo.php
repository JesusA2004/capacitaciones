<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Bypass de super_admin para Policies OPERATIVAS/RH (actas, solicitudes,
 * vacaciones, documentos, nómina, finiquitos, reclutamiento, evaluaciones).
 *
 * El `Gate::before` global (AppServiceProvider) solo cubre habilidades que
 * son permisos del catálogo (p. ej. `actas.crear`); NO cubre el nombre del
 * método de la Policy (`crearActa`, `aprobar`, `gestionar`...) ni las
 * condiciones de segregación de funciones que cada Policy agrega a mano
 * (`! $usuario->is($solicitud->usuario)`, `! $this->esPropio(...)`). Este
 * `before()` de Policy sí intercepta esas condiciones: super_admin ejecuta
 * la acción aunque el recurso sea el suyo (queda auditado igual que
 * cualquier otra acción).
 *
 * NO usar en Policies personales/autoservicio (UserPolicy, TareaRhPolicy,
 * BirthdayGreetingPolicy): ahí la restricción de "no sobre uno mismo" o de
 * destinatario es la regla de negocio, no una fuga de permisos.
 */
trait ConSuperAdminOperativo
{
    public function before(User $usuario, string $habilidad): ?bool
    {
        return $usuario->hasRole('super_admin') ? true : null;
    }
}
