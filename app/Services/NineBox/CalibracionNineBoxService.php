<?php

namespace App\Services\NineBox;

use App\Enums\NivelNineBox;
use App\Enums\TipoPeriodoNineBox;
use App\Models\Colaborador;
use App\Models\NineBoxCalibracion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calibración del potencial (bajo/medio/alto + comentario) que captura el
 * jefe directo o RH por colaborador y periodo de la Nine Box. Cada captura
 * es una fila nueva (historial completo, nunca se borra ni se edita) y queda
 * en la bitácora. Nadie se calibra a sí mismo (docs/NINE_BOX.md).
 */
class CalibracionNineBoxService
{
    public function __construct(
        private readonly NineBoxService $nineBox,
        private readonly AlcanceModuloService $alcanceModulo,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ReportesNoDisponibleException
     */
    public function calibrar(User $usuario, Colaborador $colaborador, TipoPeriodoNineBox $tipo, string $clave, NivelNineBox $nivel, string $comentario): NineBoxCalibracion
    {
        if (! $usuario->can('nine_box.calibrar') || ! $this->alcanceModulo->alcanzaColaborador($usuario, $colaborador, NineBoxService::MODULO)) {
            throw new AuthorizationException('No puedes calibrar el potencial de esta persona.');
        }

        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            throw new AuthorizationException('Nadie calibra su propio potencial: lo hace tu jefe directo o RH.');
        }

        // El periodo se resuelve contra Reportes: nunca se confía en la
        // etiqueta ni en las fechas que manda el navegador.
        $periodo = $this->nineBox->periodo($tipo, $clave);

        if ($periodo === null || $periodo['clave'] !== $clave || $periodo['fin'] === null) {
            throw ValidationException::withMessages(['periodo_clave' => 'El periodo elegido ya no existe en Reportes. Recarga la pantalla.']);
        }

        $calibracion = DB::transaction(fn (): NineBoxCalibracion => NineBoxCalibracion::query()->create([
            'colaborador_id' => $colaborador->id,
            'periodo_tipo' => $tipo,
            'periodo_clave' => $periodo['clave'],
            'periodo_etiqueta' => $periodo['etiqueta'],
            'periodo_fin' => $periodo['fin'],
            'nivel' => $nivel,
            'comentario' => trim($comentario),
            'capturada_por' => $usuario->id,
        ]));

        // La bitácora ya atrapa sus propias fallas: nunca deshace la calibración.
        $this->auditoria->registrar('nine_box_calibrada', $colaborador, $usuario, [
            'calibracion_id' => $calibracion->id,
            'periodo' => $periodo['etiqueta'],
            'nivel' => $nivel->value,
        ]);

        return $calibracion;
    }
}
