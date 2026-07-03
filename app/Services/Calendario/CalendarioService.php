<?php

namespace App\Services\Calendario;

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionUsuario;
use App\Models\Asistencia;
use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Eventos de calendario de un usuario para un mes dado: sus propias fechas
 * limite de asignaciones pendientes y las sesiones en vivo a las que tiene
 * asistencia registrada (siempre las suyas, sin importar el rol). Si ademas
 * administra sesiones (permiso sesiones.administrar), se agregan tambien las
 * sesiones que el mismo programo, para que tenga una vista completa de lo
 * que gestiona sin tener que entrar sesion por sesion.
 *
 * Devuelve arreglos planos de PHP (no Collection): al combinar eventos de
 * distinto origen con formas de arreglo ligeramente distintas, envolverlos
 * en Collection dispara errores de invarianza de plantillas en PHPStan
 * (Collection<int, TValue> no es covariante); los arreglos nativos de PHP
 * no tienen ese problema y de todas formas Inertia los serializa igual.
 */
class CalendarioService
{
    /**
     * @return array<int, array{id: string, tipo: string, titulo: string, fecha: string, url: string}>
     */
    public function eventosDelMes(User $usuario, Carbon $inicioMes, Carbon $finMes): array
    {
        $eventos = [
            ...$this->fechasLimiteDe($usuario, $inicioMes, $finMes),
            ...$this->sesionesDeAsistenciaDe($usuario, $inicioMes, $finMes),
        ];

        if ($usuario->can('sesiones.administrar')) {
            $eventos = [...$eventos, ...$this->sesionesCreadasPor($usuario, $inicioMes, $finMes)];
        }

        return collect($eventos)->unique('id')->sortBy('fecha')->values()->all();
    }

    /**
     * @return array<int, array{id: string, tipo: string, titulo: string, fecha: string, url: string}>
     */
    private function fechasLimiteDe(User $usuario, Carbon $inicioMes, Carbon $finMes): array
    {
        $asignaciones = AsignacionUsuario::query()
            ->where('user_id', $usuario->id)
            ->whereIn('estado', [EstadoAsignacion::Pendiente->value, EstadoAsignacion::EnProgreso->value])
            ->whereNotNull('fecha_limite')
            ->whereBetween('fecha_limite', [$inicioMes, $finMes])
            ->with('asignacion:id,nombre')
            ->get();

        $eventos = [];

        foreach ($asignaciones as $asignacionUsuario) {
            $eventos[] = [
                'id' => "fecha_limite_{$asignacionUsuario->id}",
                'tipo' => 'fecha_limite',
                'titulo' => "Vence: {$asignacionUsuario->asignacion->nombre}",
                'fecha' => $asignacionUsuario->fecha_limite->toDateString(),
                'url' => route('mi-capacitacion.index'),
            ];
        }

        return $eventos;
    }

    /**
     * @return array<int, array{id: string, tipo: string, titulo: string, fecha: string, url: string}>
     */
    private function sesionesDeAsistenciaDe(User $usuario, Carbon $inicioMes, Carbon $finMes): array
    {
        $asistencias = Asistencia::query()
            ->where('user_id', $usuario->id)
            ->whereHas('sesion', fn ($query) => $query->whereBetween('fecha_inicio', [$inicioMes, $finMes]))
            ->with('sesion:id,leccion_id,titulo,fecha_inicio')
            ->get();

        $eventos = [];

        foreach ($asistencias as $asistencia) {
            $eventos[] = [
                'id' => "sesion_{$asistencia->sesion->id}",
                'tipo' => 'sesion',
                'titulo' => $asistencia->sesion->titulo,
                'fecha' => $asistencia->sesion->fecha_inicio->toDateString(),
                'url' => route('mi-capacitacion.lecciones.sesion.show', $asistencia->sesion->leccion_id),
            ];
        }

        return $eventos;
    }

    /**
     * @return array<int, array{id: string, tipo: string, titulo: string, fecha: string, url: string}>
     */
    private function sesionesCreadasPor(User $usuario, Carbon $inicioMes, Carbon $finMes): array
    {
        $sesiones = SesionEnVivo::query()
            ->where('creado_por', $usuario->id)
            ->whereBetween('fecha_inicio', [$inicioMes, $finMes])
            ->get();

        $eventos = [];

        foreach ($sesiones as $sesion) {
            $eventos[] = [
                'id' => "sesion_{$sesion->id}",
                'tipo' => 'sesion',
                'titulo' => $sesion->titulo,
                'fecha' => $sesion->fecha_inicio->toDateString(),
                'url' => route('sesiones.asistencias.index', $sesion->id),
            ];
        }

        return $eventos;
    }
}
