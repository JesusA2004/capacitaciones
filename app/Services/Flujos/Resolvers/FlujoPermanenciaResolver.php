<?php

namespace App\Services\Flujos\Resolvers;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\FlujoOperativo;
use App\Enums\ModoFlujo;
use App\Enums\TipoSiguientePaso;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\NominaRectificacion;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Flujos\Dto\AccionFlujo;
use App\Services\Flujos\Dto\EnlaceFlujo;
use App\Services\Flujos\Dto\SiguientePaso;

/**
 * Directorio → Expediente → Datos laborales → Solicitudes → Incidencias → Nómina →
 * Documentos. Es un hub: no hay estado que avance ni etapas. La acción es lo más
 * urgente que quien consulta sí puede atender; si no hay, enlaces de consulta.
 */
class FlujoPermanenciaResolver
{
    public const EN_EXPEDIENTE = 'expediente';

    public const EN_SOLICITUD = 'solicitud';

    public const EN_INCIDENCIAS = 'incidencias';

    public const EN_NOMINA = 'nomina';

    public function __construct(private readonly ConstructorEnlaces $enlaces) {}

    /**
     * @param  self::EN_*  $pantalla
     */
    public function resolver(Colaborador $colaborador, User $viewer, string $pantalla, ?SolicitudInterna $solicitud = null, ?ReciboNomina $recibo = null): SiguientePaso
    {
        return match ($pantalla) {
            self::EN_SOLICITUD => $this->enSolicitud($colaborador, $viewer, $solicitud),
            self::EN_INCIDENCIAS => $this->enIncidencias($colaborador, $viewer),
            self::EN_NOMINA => $this->enNomina($colaborador, $viewer, $recibo),
            default => $this->enExpediente($colaborador, $viewer),
        };
    }

    private function enExpediente(Colaborador $c, User $v): SiguientePaso
    {
        $regreso = fn (?string $href): ?string => ConstructorEnlaces::conRegreso($href, 'expediente', $c->id);
        $relacionadas = [
            $this->enlaces->enlace('registrar_incidencia', 'Registrar incidencia', $regreso($this->enlaces->registrarIncidencia($v, $c))),
            $this->enlaces->enlace('impacto_nomina', 'Ver impacto en nómina', $regreso($this->enlaces->nomina($v, $c))),
            $this->enlaces->enlace('incidencias', 'Ver incidencias', $regreso($this->enlaces->historialIncidencias($v, $c))),
        ];

        $porRevisar = EmployeeDocument::query()->where('colaborador_id', $c->id)
            ->whereIn('status', EstadoDocumento::porRevisarValues())->count();
        $href = $porRevisar > 0 ? $regreso($this->enlaces->validacionDocumentos($v, $c)) : null;

        if ($href !== null) {
            return $this->accion(AccionFlujo::navegar('revisar_documentos', 'Revisar documentos', $href), $porRevisar === 1 ? 'Hay 1 documento por revisar.' : sprintf('Hay %d documentos por revisar.', $porRevisar), $relacionadas);
        }

        $solicitud = SolicitudInterna::query()->where('colaborador_id', $c->id)
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->latest('id')->first();
        $href = $solicitud !== null && $v->can('solicitudes.revisar') ? $this->enlaces->solicitud($v, $solicitud) : null;

        if ($solicitud !== null && $href !== null) {
            return $this->accion(AccionFlujo::navegar('atender_solicitud', 'Atender solicitud', $href), sprintf('Solicitud abierta: %s.', $solicitud->tipo->etiqueta()), $relacionadas);
        }

        if (($rectificacion = $this->accionRectificacion($c, $v, $regreso)) !== null) {
            return $this->accion($rectificacion, 'Hay una rectificación de nómina pendiente.', $relacionadas);
        }

        return $this->terminado('Sin pendientes que puedas atender en este expediente.', $relacionadas);
    }

    private function enSolicitud(Colaborador $c, User $v, ?SolicitudInterna $solicitud): SiguientePaso
    {
        $desde = $solicitud !== null ? fn (?string $href): ?string => ConstructorEnlaces::conRegreso($href, 'solicitud', $solicitud->id) : fn (?string $href): ?string => $href;
        $href = $desde($this->enlaces->expediente($v, $c));
        $relacionadas = [
            $this->enlaces->enlace('incidencias', 'Ver incidencias', $desde($this->enlaces->historialIncidencias($v, $c))),
            $this->enlaces->enlace('impacto_nomina', 'Ver impacto en nómina', $desde($this->enlaces->nomina($v, $c))),
        ];

        return $href !== null
            ? $this->accion(AccionFlujo::navegar('abrir_expediente', 'Abrir expediente', $href), 'Consulta el expediente de esta persona sin perder la solicitud.', $relacionadas)
            : $this->terminado('Atiende esta solicitud desde esta pantalla.', $relacionadas);
    }

    private function enIncidencias(Colaborador $c, User $v): SiguientePaso
    {
        $regreso = fn (?string $href): ?string => ConstructorEnlaces::conRegreso($href, 'expediente', $c->id);
        $relacionadas = [
            $this->enlaces->enlace('abrir_expediente', 'Abrir expediente', $this->enlaces->expediente($v, $c)),
            $this->enlaces->enlace('registrar_incidencia', 'Registrar incidencia', $this->enlaces->registrarIncidencia($v, $c)),
        ];

        if (($rectificacion = $this->accionRectificacion($c, $v, $regreso)) !== null) {
            return $this->accion($rectificacion, 'Hay una rectificación de nómina pendiente.', $relacionadas);
        }

        $href = $regreso($this->enlaces->nomina($v, $c));

        return $href !== null
            ? $this->accion(AccionFlujo::navegar('impacto_nomina', 'Ver impacto en nómina', $href), 'Revisa cómo afectan las incidencias a sus recibos.', $relacionadas)
            : $this->terminado('Sin pendientes que puedas atender aquí.', $relacionadas);
    }

    /**
     * Pantalla de nómina filtrada a una persona: el recibo del periodo decide.
     */
    private function enNomina(Colaborador $c, User $v, ?ReciboNomina $recibo): SiguientePaso
    {
        $relacionadas = [
            $this->enlaces->enlace('abrir_expediente', 'Abrir expediente', $this->enlaces->expediente($v, $c, 'recibos')),
            $this->enlaces->enlace('incidencias', 'Ver incidencias', $this->enlaces->historialIncidencias($v, $c)),
        ];

        if ($recibo === null) {
            return $this->terminado(sprintf('Sin recibo de %s en este periodo.', $c->nombreCompleto()), $relacionadas);
        }

        if ($recibo->estado === EstadoReciboNomina::Borrador) {
            return $v->can('nomina.recibos.crear')
                ? $this->accion(AccionFlujo::dialogo('revisar_conceptos', 'Revisar conceptos', true), 'El recibo está preparado: revisa sus conceptos antes de emitirlo.', $relacionadas)
                : new SiguientePaso(FlujoOperativo::Permanencia, ModoFlujo::Hub, $this->estadoHub(), TipoSiguientePaso::Espera, null, 'Recursos Humanos revisa este recibo antes de emitirlo.', ['rol' => 'Recursos Humanos', 'nombre' => null], relacionadas: $relacionadas);
        }

        $pdf = $this->enlaces->reciboPdf($v, $recibo);

        return $pdf !== null
            ? $this->accion(AccionFlujo::navegar('descargar_pdf', 'Descargar PDF', $pdf, descarga: true), 'Recibo emitido.', $relacionadas)
            : $this->terminado('Recibo emitido.', $relacionadas);
    }

    /**
     * @param  callable(?string): ?string  $regreso
     */
    private function accionRectificacion(Colaborador $c, User $v, callable $regreso): ?AccionFlujo
    {
        if (! NominaRectificacion::query()->where('colaborador_id', $c->id)->where('estado', 'pendiente')->exists()) {
            return null;
        }

        $href = $regreso($this->enlaces->rectificaciones($v, $c));

        return $href !== null ? AccionFlujo::navegar('resolver_rectificacion', 'Ver impacto en nómina', $href) : null;
    }

    /**
     * @return array{clave: string, etiqueta: string}
     */
    private function estadoHub(): array
    {
        return ['clave' => 'consulta', 'etiqueta' => FlujoOperativo::Permanencia->etiqueta()];
    }

    /**
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function accion(AccionFlujo $accion, string $mensaje, array $relacionadas): SiguientePaso
    {
        return new SiguientePaso(FlujoOperativo::Permanencia, ModoFlujo::Hub, $this->estadoHub(), TipoSiguientePaso::Accion, $accion, $mensaje, relacionadas: $relacionadas);
    }

    /**
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function terminado(string $mensaje, array $relacionadas): SiguientePaso
    {
        return new SiguientePaso(FlujoOperativo::Permanencia, ModoFlujo::Hub, $this->estadoHub(), TipoSiguientePaso::Terminado, null, $mensaje, relacionadas: $relacionadas);
    }
}
