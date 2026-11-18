<?php

namespace App\Services\Tareas;

use App\Models\TareaRh;
use App\Models\TareaRhDescarte;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Acciones de la persona sobre su lista de pendientes (docs/PENDIENTES.md):
 *
 *  - «Quitar de mi lista»: lo descarta SOLO para ella (motivo opcional); a
 *    los demás destinatarios les sigue llegando. Si el pendiente se vuelve
 *    a generar por un evento nuevo es otra fila y reaparece.
 *  - «Restaurar»: lo regresa a su lista.
 *  - «Marcar como hecho»: cierra el pendiente para todos; solo en los
 *    informativos o en los asignados a su cuenta (TareaRhPolicy).
 *
 * La autorización vive en TareaRhPolicy; aquí solo las reglas de escritura.
 */
class DescarteTareasService
{
    public function __construct(private readonly TareaService $tareas) {}

    public function descartar(TareaRh $tarea, User $usuario, ?string $motivo = null): TareaRhDescarte
    {
        $motivo = $motivo !== null ? trim($motivo) : null;

        $descarte = TareaRhDescarte::query()->updateOrCreate(
            ['tarea_rh_id' => $tarea->id, 'user_id' => $usuario->id],
            ['motivo' => $motivo === '' ? null : $motivo],
        );

        $this->olvidarConteo($usuario);

        return $descarte;
    }

    public function restaurar(TareaRh $tarea, User $usuario): void
    {
        TareaRhDescarte::query()->where('tarea_rh_id', $tarea->id)->where('user_id', $usuario->id)->delete();
        $this->olvidarConteo($usuario);
    }

    public function marcarHecha(TareaRh $tarea, User $usuario): TareaRh
    {
        $tarea = $this->tareas->resolverManual($tarea, $usuario);
        $this->olvidarConteo($usuario);

        return $tarea;
    }

    public function motivoDe(TareaRh $tarea, User $usuario): ?string
    {
        return TareaRhDescarte::query()->where('tarea_rh_id', $tarea->id)->where('user_id', $usuario->id)->value('motivo');
    }

    /**
     * El contador del sidebar se guarda unos segundos en caché: tras una
     * acción se olvida para que cuadre de inmediato con la lista.
     */
    public function olvidarConteo(User $usuario): void
    {
        Cache::forget(self::claveConteo($usuario));
    }

    public static function claveConteo(User $usuario): string
    {
        return sprintf('pendientes_abiertos:%d', $usuario->id);
    }
}
