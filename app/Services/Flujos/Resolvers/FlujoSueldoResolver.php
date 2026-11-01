<?php

namespace App\Services\Flujos\Resolvers;

use App\Enums\EstadoFiniquito;
use App\Enums\FlujoOperativo;
use App\Enums\ModoFlujo;
use App\Enums\TipoSiguientePaso;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\NominaRectificacion;
use App\Models\User;
use App\Services\Compensacion\CompensacionService;
use App\Services\Correcciones\Dominios\SalarioDominio;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Flujos\Dto\AccionFlujo;
use App\Services\Flujos\Dto\EnlaceFlujo;
use App\Services\Flujos\Dto\SiguientePaso;

/**
 * Expediente → Sueldo → Historial → Corrección → Impacto → Reportes. El sueldo no
 * tiene estados: es un historial con consecuencias, así que esto es un hub. Sin
 * permiso de ver el sueldo no hay panel (ni un mensaje que revele un cambio).
 *
 * El sueldo individual vive en el expediente. El tabulador es la referencia del
 * puesto (TabuladorSalarialService): ninguno actualiza al otro y aquí solo se enlaza.
 */
class FlujoSueldoResolver
{
    public function __construct(
        private readonly SalarioDominio $salario,
        private readonly CompensacionService $compensacion,
        private readonly ConstructorEnlaces $enlaces,
    ) {}

    public function resolver(Colaborador $c, User $v): ?SiguientePaso
    {
        if (! $this->salario->puedeLeer($v, $c)) {
            return null;
        }

        $mensual = $this->compensacion->mensualDe($c);
        // El botón vive en la pestaña laboral, que además exige expedientes.editar.
        $puedeCorregir = $this->salario->puedeEscribir($v, $c) && $v->can('expedientes.editar');
        $corregir = $puedeCorregir ? $this->enlaces->expediente($v, $c, 'laborales', 'corregir_sueldo') : null;
        $historial = $this->enlaces->expediente($v, $c, 'cambios');

        if ($mensual === null) {
            return $corregir !== null
                ? $this->paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('corregir_sueldo', 'Capturar sueldo', $corregir), 'Esta persona todavía no tiene sueldo capturado.')
                : $this->paso(TipoSiguientePaso::Espera, null, 'Falta que Recursos Humanos capture su sueldo.');
        }

        $relacionadas = [
            $this->enlaces->enlace('corregir_sueldo', 'Corregir sueldo', $corregir),
            $this->enlaces->enlace('exportar_reporte', 'Exportar reporte', $this->enlaces->reportes($v)),
            $this->enlaces->enlace('tabulador', 'Ver tabulador del puesto', $this->enlaces->tabulador($v)),
        ];

        if (NominaRectificacion::query()->where('colaborador_id', $c->id)->where('estado', 'pendiente')->exists()
            && ($href = $this->enlaces->rectificaciones($v, $c)) !== null) {
            return $this->paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('revisar_nomina', 'Revisar nómina', ConstructorEnlaces::conRegreso($href, 'expediente', $c->id) ?? $href), 'Hay una rectificación de nómina pendiente. Lo emitido no cambia solo.', $relacionadas);
        }

        if (($afectados = $this->contratosFirmadosConOtroSueldo($c, $mensual)) > 0
            && ($href = $this->enlaces->expediente($v, $c, 'contratacion')) !== null) {
            return $this->paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('ver_documentos_afectados', 'Ver documentos afectados', $href), sprintf('%d contrato(s) firmado(s) con otro sueldo: conservan lo firmado y requieren modificación contractual.', $afectados), $relacionadas);
        }

        if (($finiquito = $this->finiquitoConOtroSueldo($c, $mensual)) !== null && ($href = $this->enlaces->finiquito($v, $finiquito)) !== null) {
            return $this->paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('ver_documentos_afectados', 'Ver documentos afectados', $href), 'Su finiquito se calculó con otro sueldo y no se recalcula solo.', $relacionadas);
        }

        return $historial !== null
            ? $this->paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('ver_historial_salarial', 'Ver historial salarial', $historial), 'Sin efectos pendientes por cambios de sueldo.', $relacionadas)
            : $this->paso(TipoSiguientePaso::Terminado, null, 'Sin efectos pendientes por cambios de sueldo.', $relacionadas);
    }

    private function contratosFirmadosConOtroSueldo(Colaborador $c, float $mensual): int
    {
        return $this->compensacion->contratosVigentesFirmados($c)
            ->filter(fn ($contrato): bool => $contrato->sueldo_mensual !== null && abs((float) $contrato->sueldo_mensual - $mensual) > 0.005)
            ->count();
    }

    private function finiquitoConOtroSueldo(Colaborador $c, float $mensual): ?FiniquitoCalculo
    {
        return FiniquitoCalculo::query()->where('colaborador_id', $c->id)
            ->whereNotIn('estado', [EstadoFiniquito::Firmado->value, EstadoFiniquito::Pagado->value])
            ->get()
            ->first(fn (FiniquitoCalculo $f): bool => abs((float) $f->sueldo_mensual - $mensual) > 0.005);
    }

    /**
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function paso(TipoSiguientePaso $tipo, ?AccionFlujo $accion, string $mensaje, array $relacionadas = []): SiguientePaso
    {
        return new SiguientePaso(
            FlujoOperativo::Sueldo,
            ModoFlujo::Hub,
            ['clave' => 'sueldo', 'etiqueta' => FlujoOperativo::Sueldo->etiqueta()],
            $tipo,
            $accion,
            $mensaje,
            relacionadas: $relacionadas,
        );
    }
}
