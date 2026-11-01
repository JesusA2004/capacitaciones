<?php

namespace App\Services\Flujos;

use App\Models\AgendaEvento;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\IncorporacionInvitacion;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Contratos\RegularizacionContratosService;
use App\Services\Flujos\Dto\EnlaceFlujo;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\Request;

/**
 * Único lugar que arma los href de «Siguiente paso». Cada método pasa la MISMA
 * regla que el controlador de destino y devuelve null si no se cumple: un enlace
 * nunca termina en 403. Las rutas son relativas, sin datos del usuario.
 */
class ConstructorEnlaces
{
    /** Regresos permitidos en `?desde=`: cualquier otro valor se ignora. */
    private const REGRESOS = ['expediente', 'ciclo', 'candidato', 'baja', 'solicitud'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly RegularizacionContratosService $contratos,
    ) {}

    /**
     * @param  array<string, int|string|null>  $parametros
     */
    private function ruta(string $nombre, array $parametros = []): string
    {
        return route($nombre, array_filter($parametros, fn ($valor): bool => $valor !== null), false);
    }

    public function expediente(User $v, Colaborador $c, ?string $pestana = null, ?string $accion = null): ?string
    {
        return $this->alcance->puedeVerExpediente($v, $c)
            ? $this->ruta('rh.expedientes.show', ['colaborador' => $c->id, 'tab' => $pestana, 'accion' => $accion])
            : null;
    }

    public function ciclo(User $v, Colaborador $c, ?string $accion = null): ?string
    {
        $enCadena = $v->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($v->colaborador, $c);

        return $this->alcance->alcanzaColaborador($v, $c) || $enCadena
            ? $this->ruta('rh.colaboradores.ciclo', ['colaborador' => $c->id, 'accion' => $accion])
            : null;
    }

    public function fichaCandidato(User $v, Candidato $c, ?string $accion = null): ?string
    {
        return $v->can('view', $c) ? $this->ruta('rh.candidatos.show', ['candidato' => $c->id, 'accion' => $accion]) : null;
    }

    public function agendarCandidato(User $v, Candidato $c): ?string
    {
        return $c->sucursal_id !== null && $v->can('viewAny', AgendaEvento::class) && $v->can('agendarEn', [AgendaEvento::class, $c->sucursal_id])
            ? $this->ruta('agenda.index', ['candidato' => $c->id])
            : null;
    }

    public function evento(User $v, AgendaEvento $evento): ?string
    {
        return $v->can('view', $evento) ? $this->ruta('agenda.index', ['evento' => $evento->id, 'fecha' => $evento->fecha_hora->toDateString()]) : null;
    }

    public function invitacion(User $v, IncorporacionInvitacion $invitacion): ?string
    {
        return $v->can('rh.incorporacion.invitaciones.ver') ? $this->ruta('rh.incorporacion.invitaciones.show', ['invitacion' => $invitacion->id]) : null;
    }

    public function validacionDocumentos(User $v, Colaborador $c): ?string
    {
        return $v->can('documentos.revisar') && $v->colaborador_id !== $c->id && $this->alcance->alcanzaColaborador($v, $c)
            ? $this->ruta('rh.validacion-documentos.index', ['colaborador' => $c->id])
            : null;
    }

    /** Pestaña de contratos: mismo criterio que ExpedienteCentroService (nunca el propio). */
    public function generarContratos(User $v, Colaborador $c): ?string
    {
        return $this->contratos->puedeVer($v) && $this->contratos->puedeGenerar($v) && $v->colaborador_id !== $c->id
            ? $this->expediente($v, $c, 'contratacion')
            : null;
    }

    public function completarExpediente(User $v, Colaborador $c): ?string
    {
        return ($v->can('expedientes.editar') || $v->can('documentos.revisar')) && $v->colaborador_id !== $c->id
            ? $this->expediente($v, $c, 'documentos')
            : null;
    }

    public function firmarContratos(User $v, Colaborador $c): ?string
    {
        $ciclo = $v->can('documentos_laborales.operar_fisico') ? $this->ciclo($v, $c) : null;

        return $ciclo !== null ? $ciclo.'#documentos-laborales' : null;
    }

    public function presentarInduccion(User $v, Colaborador $c): ?string
    {
        return $v->can(OnboardingService::PERMISO_GESTIONAR) && $v->can('onboarding.presentar_induccion') && $this->alcance->alcanzaColaborador($v, $c)
            ? $this->ruta('induccion.presentacion', ['colaborador' => $c->id])
            : null;
    }

    public function calificarInduccion(User $v, Colaborador $c): ?string
    {
        return $v->can('onboarding.calificar') && $this->alcance->alcanzaColaborador($v, $c)
            ? $this->ruta('rh.onboarding.calificaciones', ['colaborador' => $c->id])
            : null;
    }

    public function baja(User $v, Baja $baja, ?string $accion = null): ?string
    {
        return $v->can('view', $baja) ? $this->ruta('rh.bajas.show', ['baja' => $baja->id, 'accion' => $accion]) : null;
    }

    public function finiquito(User $v, FiniquitoCalculo $finiquito): ?string
    {
        return $v->can('ver', $finiquito) ? $this->ruta('rh.bajas.finiquitos.index', ['finiquito' => $finiquito->id]) : null;
    }

    public function rotacion(User $v, Baja $baja): ?string
    {
        return $v->can('verTablero', Baja::class)
            ? $this->ruta('reportes.rotacion.tablero.index', ['sucursal_id' => $baja->sucursal_id, 'forma' => $baja->forma_salida?->value])
            : null;
    }

    public function solicitud(User $v, SolicitudInterna $solicitud): ?string
    {
        return $v->can('view', $solicitud) ? $this->ruta('rh.solicitudes.show', ['solicitud' => $solicitud->id]) : null;
    }

    public function registrarIncidencia(User $v, Colaborador $c): ?string
    {
        return $v->can('incapacidades.registrar') && $v->colaborador_id !== $c->id && $this->alcance->alcanzaColaboradorEnModulo($v, $c, 'incapacidades')
            ? $this->ruta('rh.incapacidades.create', ['colaborador' => $c->id])
            : null;
    }

    public function historialIncidencias(User $v, Colaborador $c): ?string
    {
        return $v->can('incapacidades.ver') && $v->colaborador_id !== $c->id && $this->alcance->alcanzaColaboradorEnModulo($v, $c, 'incapacidades')
            ? $this->ruta('rh.incapacidades.historial', ['colaborador' => $c->id])
            : null;
    }

    /** Las rectificaciones por atender viven en el panel de Incapacidades. */
    public function rectificaciones(User $v, Colaborador $c): ?string
    {
        return $v->can('incapacidades.ver') && $v->can('nomina.recibos.crear') && $this->alcance->alcanzaColaboradorEnModulo($v, $c, 'incapacidades')
            ? $this->ruta('rh.incapacidades.index', ['colaborador' => $c->id])
            : null;
    }

    public function nomina(User $v, Colaborador $c, ?string $periodo = null): ?string
    {
        return $v->can('nomina.recibos.ver') && $v->colaborador_id !== $c->id && $this->alcance->alcanzaColaboradorEnModulo($v, $c, 'recibos')
            ? $this->ruta('rh.nomina.index', ['colaborador' => $c->id, 'periodo' => $periodo])
            : null;
    }

    public function reciboPdf(User $v, ReciboNomina $recibo): ?string
    {
        return $recibo->pdf_path !== null && $v->can('descargar', $recibo) ? $this->ruta('rh.nomina.recibos.pdf', ['recibo' => $recibo->id]) : null;
    }

    public function tabulador(User $v): ?string
    {
        return $v->can('salarios.ver') ? $this->ruta('salarios.index') : null;
    }

    public function reportes(User $v): ?string
    {
        return $v->can('reportes_rh.ver') ? $this->ruta('reportes.index') : null;
    }

    public function enlace(string $clave, string $etiqueta, ?string $href, bool $descarga = false): ?EnlaceFlujo
    {
        return $href !== null ? new EnlaceFlujo($clave, $etiqueta, $href, $descarga) : null;
    }

    /**
     * «Volver a…» desde `?desde=expediente&desde_id=12`. Clave en lista blanca y
     * resuelta aquí, con el mismo permiso que su pantalla: nunca una URL recibida,
     * así que no hay redirección abierta.
     *
     * @return array{clave: string, etiqueta: string, href: string, descarga: bool}|null
     */
    public function regreso(Request $request, User $v): ?array
    {
        $desde = $request->query('desde');
        $id = (int) $request->query('desde_id');

        if (! is_string($desde) || ! in_array($desde, self::REGRESOS, true) || $id <= 0) {
            return null;
        }

        $enlace = match ($desde) {
            'expediente' => $this->regresoColaborador($v, $id, 'Volver al expediente', fn (Colaborador $c): ?string => $this->expediente($v, $c)),
            'ciclo' => $this->regresoColaborador($v, $id, 'Volver al ciclo laboral', fn (Colaborador $c): ?string => $this->ciclo($v, $c)),
            'candidato' => ($candidato = Candidato::query()->whereKey($id)->first()) !== null ? $this->enlace('regreso', 'Volver al candidato', $this->fichaCandidato($v, $candidato)) : null,
            'baja' => ($baja = Baja::query()->whereKey($id)->first()) !== null ? $this->enlace('regreso', 'Volver a la baja', $this->baja($v, $baja)) : null,
            default => ($solicitud = SolicitudInterna::query()->whereKey($id)->first()) !== null ? $this->enlace('regreso', 'Volver a la solicitud', $this->solicitud($v, $solicitud)) : null,
        };

        return $enlace?->toArray();
    }

    /**
     * Agrega el origen a un href interno para poder volver.
     */
    public static function conRegreso(?string $href, string $desde, int $id): ?string
    {
        if ($href === null) {
            return null;
        }

        [$ruta, $ancla] = array_pad(explode('#', $href, 2), 2, null);
        $ruta .= (str_contains($ruta, '?') ? '&' : '?').http_build_query(['desde' => $desde, 'desde_id' => $id]);

        return $ancla !== null ? $ruta.'#'.$ancla : $ruta;
    }

    /**
     * @param  callable(Colaborador): ?string  $href
     */
    private function regresoColaborador(User $v, int $id, string $etiqueta, callable $href): ?EnlaceFlujo
    {
        $colaborador = Colaborador::query()->whereKey($id)->first();

        return $colaborador !== null ? $this->enlace('regreso', $etiqueta, $href($colaborador)) : null;
    }
}
