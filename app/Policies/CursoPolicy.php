<?php

namespace App\Policies;

use App\Models\Curso;
use App\Models\User;

class CursoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('cursos.ver');
    }

    /**
     * Portada operativa de Capacitación: quien opera alguna parte del
     * módulo (cursos, asignaciones, calificaciones o reportes de
     * capacitación). Cada indicador va acotado por alcance en el Service.
     */
    public function portada(User $usuario): bool
    {
        return $usuario->canAny(['cursos.ver', 'asignaciones.ver', 'respuestas.ver', 'reportes.globales', 'reportes.sucursal']);
    }

    /**
     * Tablero de la «Certificación Mr. Lana» (quién está en qué nivel),
     * acotado por alcance en CertificacionMrLanaService. Requiere además la
     * bandera `certificacion` (middleware feature:certificacion).
     */
    public function certificacion(User $usuario): bool
    {
        return $usuario->canAny(['cursos.ver', 'asignaciones.ver', 'respuestas.ver', 'certificacion.gestionar']);
    }

    /**
     * Aula tipo Classroom de un curso: mismo permiso que abrir el curso.
     */
    public function aula(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.ver');
    }

    public function view(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('cursos.crear');
    }

    public function update(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.editar');
    }

    public function delete(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.eliminar');
    }

    public function publicar(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.publicar');
    }
}
