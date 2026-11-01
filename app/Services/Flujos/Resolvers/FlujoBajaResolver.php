<?php

namespace App\Services\Flujos\Resolvers;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstatusBaja;
use App\Enums\FlujoOperativo;
use App\Enums\ModoFlujo;
use App\Enums\TipoSiguientePaso;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\Bajas\BajaDocumentoSalidaService;
use App\Services\Bajas\BajaDocumentosService;
use App\Services\Bajas\BajaReglasService;
use App\Services\Bajas\BajaService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Flujos\Dto\AccionFlujo;
use App\Services\Flujos\Dto\EnlaceFlujo;
use App\Services\Flujos\Dto\SiguientePaso;
use App\Support\FechaMexico;

/**
 * Colaborador → Baja → Causal → Documento → Revisar → Firmar → Finiquito → Cierre.
 * No hay estados nuevos: el orden se deriva de lo que ya informan BajaReglasService,
 * BajaDocumentoSalidaService, FiniquitoService y las acciones del cierre. La rotación
 * es un enlace, no un paso: la baja cuenta desde que se registra y llega su fecha.
 *
 * Hay dos cierres y no se fusionan (pendiente de decisión): «Cerrar baja» concluye
 * el expediente de la baja y «Concluir cierre laboral» concluye el ciclo del
 * colaborador. Cada uno conserva su botón y su pantalla.
 */
class FlujoBajaResolver
{
    public const EN_BAJA = 'baja';

    public const EN_CICLO = 'ciclo';

    public function __construct(
        private readonly BajaService $bajas,
        private readonly BajaReglasService $reglas,
        private readonly BajaDocumentosService $documentos,
        private readonly BajaDocumentoSalidaService $salida,
        private readonly CierreLaboralService $cierres,
        private readonly FiniquitoService $finiquitos,
        private readonly DocumentoProcesoService $proceso,
        private readonly ConstructorEnlaces $enlaces,
    ) {}

    /**
     * @param  self::EN_*  $pantalla
     */
    public function resolver(Baja $baja, User $viewer, string $pantalla = self::EN_BAJA): SiguientePaso
    {
        $baja->loadMissing(['cierre', 'causal']);
        $cierre = $baja->cierre;
        $finiquito = $cierre !== null ? $this->cierres->finiquito($cierre) : null;
        $colaborador = Colaborador::withTrashed()->where('id', $baja->colaborador_id)->first();
        $panel = $viewer->can('documentoSalida', $baja) ? $this->salida->panel($baja, $viewer) : null;
        $faltantes = $this->documentos->faltantes($baja);
        $errores = $baja->estatus->esAbierta() ? array_values($this->reglas->errores($baja)) : [];
        $relacionadas = $this->relacionadas($baja, $viewer, $colaborador, $pantalla);
        $pasos = $this->pasos($baja, $panel, $faltantes, $errores, $finiquito, $cierre);
        $progreso = $this->progreso($pasos);
        $estado = ['clave' => $baja->estatus->value, 'etiqueta' => $baja->estatus->etiqueta()];
        $responsable = ['rol' => 'Recursos Humanos', 'nombre' => null];
        $paso = fn (TipoSiguientePaso $tipo, ?AccionFlujo $accion, string $mensaje, array $bloqueos = []): SiguientePaso => new SiguientePaso(FlujoOperativo::Baja, ModoFlujo::Lineal, $estado, $tipo, $accion, $mensaje, $responsable, $progreso, $pasos, array_values($bloqueos), $relacionadas);

        if ($baja->estatus === EstatusBaja::Cancelada) {
            return $paso(TipoSiguientePaso::Terminado, null, 'La baja no procedió: la persona sigue laborando.');
        }

        if ($baja->estatus === EstatusBaja::Cerrada) {
            $acciones = $cierre !== null ? $this->cierres->accionesPermitidas($cierre, $viewer) : [];

            if ($cierre !== null && $cierre->estado === EstadoCierreLaboral::BajaEjecutada) {
                $concluir = $this->tiene($acciones, 'cerrar') && $colaborador !== null ? $this->enlaces->ciclo($viewer, $colaborador, 'cerrar') : null;

                return $concluir !== null
                    ? $paso(TipoSiguientePaso::Accion, AccionFlujo::navegar('concluir_cierre', 'Concluir cierre laboral', $concluir), 'La baja ya se cerró; falta concluir el cierre laboral del colaborador.')
                    : $paso(TipoSiguientePaso::Espera, null, 'La baja ya se cerró; Recursos Humanos concluye el cierre laboral.');
            }

            return $paso(TipoSiguientePaso::Terminado, null, 'La baja está cerrada.');
        }

        $acciones = $this->bajas->acciones($baja, $viewer);
        $accionesCierre = $cierre !== null ? $this->cierres->accionesPermitidas($cierre, $viewer) : [];
        $dialogo = fn (string $clave, string $etiqueta): AccionFlujo => $this->dialogo($baja, $viewer, $pantalla, $clave, $etiqueta);
        $bloqueos = $errores;

        // 1. Datos de la baja (causal, fechas, comentario).
        if ($errores !== [] && in_array('actualizar', $acciones, true)) {
            return $paso(TipoSiguientePaso::Accion, $dialogo('completar_datos', 'Completar datos de la baja'), 'Faltan datos de la baja.', $bloqueos);
        }

        // 2-4. Documento de salida: generar, corregir si cambió, registrar la firma.
        if ($panel !== null && $panel['documento'] !== null) {
            $etiqueta = $panel['documento']['etiqueta'];

            if ($panel['generado'] === null) {
                return $paso(TipoSiguientePaso::Accion, $dialogo('generar_documento', 'Generar documento de salida'), sprintf('Falta generar: %s.', $etiqueta), array_map(fn (string $dato): string => sprintf('Falta capturar %s.', $dato), $panel['faltantes']));
            }

            if ($panel['generado']['desactualizado'] === true && $panel['generado']['firmado'] !== true) {
                return $paso(TipoSiguientePaso::Accion, $dialogo('corregir_documento', 'Corregir documento'), sprintf('Los datos cambiaron después de generar %s: emite otra versión antes de firmar.', $etiqueta));
            }

            if ($panel['generado']['firmado'] !== true) {
                return $paso(TipoSiguientePaso::Accion, $dialogo('registrar_firmado', 'Registrar documento firmado'), sprintf('Falta registrar la firma de %s.', $etiqueta));
            }
        }

        // Quien adjunta sin gestionar la baja (el gerente) también tiene su paso.
        if ($faltantes !== [] && in_array('adjuntar', $acciones, true)) {
            return $paso(TipoSiguientePaso::Accion, $dialogo('adjuntar_documentos', 'Adjuntar documentos'), sprintf('Faltan documentos: %s.', implode(', ', $faltantes)));
        }

        // 5. Finiquito.
        if ($cierre !== null && ! in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado], true)) {
            $accionFiniquito = $this->pasoFiniquito($baja, $cierre, $finiquito, $viewer, $colaborador, $accionesCierre, $pantalla);

            if ($accionFiniquito instanceof AccionFlujo) {
                [$mensaje, $bloqueosFiniquito] = $this->detalleFiniquito($accionFiniquito->clave, $cierre);

                return $paso(TipoSiguientePaso::Accion, $accionFiniquito, $mensaje, $bloqueosFiniquito);
            }

            if ($finiquito === null || $finiquito->estado === EstadoFiniquito::Borrador) {
                return $paso(TipoSiguientePaso::Espera, null, $finiquito === null ? 'Recursos Humanos calcula el finiquito.' : 'Recursos Humanos revisa el finiquito.');
            }
        }

        // La fecha manda: nada se cierra antes del último día laborado.
        if ($baja->fecha_baja !== null && $baja->fecha_baja->isAfter(FechaMexico::inicioDeHoy())) {
            return $paso(TipoSiguientePaso::Espera, null, sprintf('La baja se cierra a partir del %s.', $baja->fecha_baja->format('d/m/Y')));
        }

        if ($errores === [] && $faltantes === [] && in_array('cerrar', $acciones, true)) {
            return $paso(TipoSiguientePaso::Accion, $dialogo('cerrar_baja', 'Cerrar baja'), 'Todo está listo para cerrar la baja.');
        }

        return $paso(TipoSiguientePaso::Espera, null, $faltantes !== [] ? sprintf('Faltan documentos: %s.', implode(', ', $faltantes)) : 'Recursos Humanos da seguimiento a esta baja.', $bloqueos);
    }

    /**
     * Desde el ciclo, lo que se atiende en la baja lleva a ella y abre lo mismo.
     */
    private function dialogo(Baja $baja, User $viewer, string $pantalla, string $clave, string $etiqueta): AccionFlujo
    {
        if ($pantalla === self::EN_BAJA) {
            return AccionFlujo::dialogo($clave, $etiqueta, $clave !== 'adjuntar_documentos');
        }

        $href = $this->enlaces->baja($viewer, $baja, $clave);

        return $href !== null ? AccionFlujo::navegar($clave, $etiqueta, $href) : AccionFlujo::dialogo($clave, $etiqueta, true);
    }

    /**
     * @param  list<array{clave: string, etiqueta: string, tipo: string}>  $accionesCierre
     */
    private function pasoFiniquito(Baja $baja, CierreLaboral $cierre, ?FiniquitoCalculo $finiquito, User $viewer, ?Colaborador $colaborador, array $accionesCierre, string $pantalla): ?AccionFlujo
    {
        $enCiclo = fn (string $clave, string $etiqueta): ?AccionFlujo => $this->enCiclo($viewer, $colaborador, $pantalla, $clave, $etiqueta);

        if ($finiquito === null) {
            return $this->tiene($accionesCierre, 'calcular_finiquito') ? $enCiclo('calcular_finiquito', 'Calcular finiquito') : null;
        }

        $cerrado = in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true);

        if ($cerrado) {
            return null;
        }

        if ($viewer->can('editarAjustes', $finiquito) && $this->requiereRecalculo($finiquito, $viewer)) {
            return $this->dialogo($baja, $viewer, $pantalla, 'recalcular_finiquito', 'Recalcular finiquito');
        }

        if ($finiquito->estado === EstadoFiniquito::Borrador) {
            return $viewer->can('revisar', $finiquito) ? $this->dialogo($baja, $viewer, $pantalla, 'revisar_finiquito', 'Revisar finiquito') : null;
        }

        // La entrega exige la fecha efectiva y el checklist del procedimiento.
        if ($this->tiene($accionesCierre, 'registrar_entrega_finiquito') && ! $cierre->fecha_efectiva->isAfter(FechaMexico::inicioDeHoy())) {
            return $this->proceso->checklistFaltantes($cierre, ['finiquito']) === []
                ? $enCiclo('registrar_entrega_finiquito', 'Registrar entrega de finiquito')
                : null;
        }

        return null;
    }

    private function enCiclo(User $viewer, ?Colaborador $colaborador, string $pantalla, string $clave, string $etiqueta): ?AccionFlujo
    {
        if ($pantalla === self::EN_CICLO) {
            return AccionFlujo::dialogo($clave, $etiqueta, true);
        }

        $href = $colaborador !== null ? $this->enlaces->ciclo($viewer, $colaborador, $clave) : null;

        return $href !== null ? AccionFlujo::navegar($clave, $etiqueta, $href) : null;
    }

    /**
     * Mismo criterio que firmar y entregar: un PDF ya generado que no corresponde.
     */
    private function requiereRecalculo(FiniquitoCalculo $finiquito, User $viewer): bool
    {
        return $finiquito->generated_document_id !== null && $this->finiquitos->documentoAlDia($finiquito, $viewer) === null;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function detalleFiniquito(string $clave, CierreLaboral $cierre): array
    {
        return match ($clave) {
            'calcular_finiquito' => ['Esta baja todavía no tiene finiquito calculado.', []],
            'recalcular_finiquito' => ['Los datos cambiaron después de generar el PDF del finiquito: recalcula y genera otra versión.', []],
            'revisar_finiquito' => ['El finiquito está en borrador: revísalo para poder entregarlo.', []],
            default => ['El finiquito está revisado: registra su entrega. Al registrarla, la baja laboral se ejecuta.', []],
        };
    }

    /**
     * @return list<EnlaceFlujo|null>
     */
    private function relacionadas(Baja $baja, User $viewer, ?Colaborador $colaborador, string $pantalla): array
    {
        return [
            $this->enlaces->enlace('rotacion', 'Ver en rotación', $this->enlaces->rotacion($viewer, $baja)),
            match (true) {
                $pantalla === self::EN_CICLO => $this->enlaces->enlace('baja', 'Ver baja', $this->enlaces->baja($viewer, $baja)),
                $colaborador !== null && $baja->cierre !== null => $this->enlaces->enlace('ciclo', 'Ver cierre laboral', $this->enlaces->ciclo($viewer, $colaborador)),
                default => null,
            },
            $colaborador !== null ? $this->enlaces->enlace('expediente', 'Abrir expediente', $this->enlaces->expediente($viewer, $colaborador)) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $panel
     * @param  list<string>  $faltantes
     * @param  list<string>  $errores
     * @return list<array{clave: string, etiqueta: string, estado: string}>
     */
    private function pasos(Baja $baja, ?array $panel, array $faltantes, array $errores, ?FiniquitoCalculo $finiquito, ?CierreLaboral $cierre): array
    {
        $cerrada = $baja->estatus === EstatusBaja::Cerrada;
        $generado = $panel['generado'] ?? null;
        $sinDocumento = $panel !== null && $panel['documento'] === null;
        $hechos = [
            'causal' => $baja->causal_rotacion_id !== null && $errores === [],
            // Sin acceso al panel, lo que se sabe es si faltan documentos.
            'documento' => $cerrada || $sinDocumento || ($panel !== null ? $generado !== null : $faltantes === []),
            'revisar' => $cerrada || $sinDocumento || ($panel !== null ? $generado !== null && $generado['desactualizado'] !== true : $faltantes === []),
            'firmar' => $cerrada || $sinDocumento || ($panel !== null ? $generado !== null && $generado['firmado'] === true : $faltantes === []),
            'finiquito' => $cierre === null || in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado], true)
                || ($finiquito !== null && in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado, EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)),
            'cierre' => $cerrada,
        ];
        $etiquetas = ['causal' => 'Causal', 'documento' => 'Documento', 'revisar' => 'Revisar', 'firmar' => 'Firmar', 'finiquito' => 'Finiquito', 'cierre' => 'Cierre'];
        $pasos = [['clave' => 'baja', 'etiqueta' => 'Baja', 'estado' => 'completado']];
        $actual = false;

        foreach ($etiquetas as $clave => $etiqueta) {
            $hecho = $hechos[$clave];
            $pasos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'estado' => match (true) {
                $hecho => 'completado',
                ! $actual && $baja->estatus !== EstatusBaja::Cancelada => 'actual',
                $baja->estatus === EstatusBaja::Cancelada => 'detenido',
                default => 'pendiente',
            }];
            $actual = $actual || ! $hecho;
        }

        return $pasos;
    }

    /**
     * @param  list<array{clave: string, etiqueta: string, estado: string}>  $pasos
     */
    private function progreso(array $pasos): int
    {
        $hechos = count(array_filter($pasos, fn (array $p): bool => $p['estado'] === 'completado'));

        return (int) round($hechos / max(count($pasos), 1) * 100);
    }

    /**
     * @param  list<array{clave: string, etiqueta: string, tipo: string}>  $acciones
     */
    private function tiene(array $acciones, string $clave): bool
    {
        return in_array($clave, array_column($acciones, 'clave'), true);
    }
}
