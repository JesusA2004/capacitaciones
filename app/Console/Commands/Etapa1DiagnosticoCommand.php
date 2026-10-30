<?php

namespace App\Console\Commands;

use App\Services\Headcount\HeadcountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnóstico de reconciliación de la Etapa 1 (propuesta de mejoras de RH,
 * docs/propuesta/). SOLO LECTURA: no escribe en ninguna tabla, no toca el NAS,
 * no corre migraciones. Produce conteos y hallazgos para los 12 puntos de
 * reconciliación de docs/propuesta/ETAPA1_TRAZABILIDAD.md (§12 del encargo),
 * ANTES de cualquier corrección.
 *
 * Cada sección se protege con Schema::hasTable()/try-catch para que una tabla
 * o columna ausente no detenga el resto del diagnóstico — igual que
 * people:diagnostico.
 *
 * Los casos que requieren intervención humana se marcan con ✗ (no se corrigen
 * aquí); los informativos con ✓/•.
 */
class Etapa1DiagnosticoCommand extends Command
{
    protected $signature = 'etapa1:diagnostico {--json : Emitir el resumen como JSON en vez de texto}';

    protected $description = 'Reconciliación de datos para la Etapa 1 (plantilla, directorio, bajas, incapacidades, rotación, salarios, agenda, NOI). Solo lectura.';

    /** @var array<string, int|string> */
    private array $resumen = [];

    public function handle(HeadcountService $headcount): int
    {
        $this->line('<fg=blue;options=bold>Diagnóstico de reconciliación — Etapa 1 (solo lectura)</>');
        $this->line('Fecha de corte: '.now()->toDateString());

        $this->plantillaUnica($headcount);
        $this->activoVsPlaza($headcount);
        $this->directorioExclusiones();
        $this->incapacidadesHistoricas();
        $this->bajasJuridico();
        $this->cierresYExpedientes();
        $this->finiquitosPrevios();
        $this->rotacionSolapamientos();
        $this->salariosVsTabulador();
        $this->agendaRegistrada();
        $this->fechaAltaImss();
        $this->datosNoi();

        if ($this->option('json')) {
            $this->newLine();
            $this->line((string) json_encode($this->resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->newLine();
        $this->info('Diagnóstico terminado. Ningún dato fue modificado.');

        return self::SUCCESS;
    }

    /** 1. Catálogo de plazas de Corporativo (objetivo 151 / 134 / 17). */
    private function plantillaUnica(HeadcountService $headcount): void
    {
        $this->seccion('1. Plantilla única (Tablero/Directorio/Reportes)');

        try {
            $totales = $headcount->totalesGenerales();
            $this->dato('Plantilla autorizada', $totales['plantilla_autorizada']);
            $this->dato('Plantilla activa (ocupada)', $totales['plantilla_actual']);
            $this->dato('Vacantes derivadas', $totales['vacantes']);
            $this->dato('Cobertura %', $totales['cumplimiento']);

            $corpId = DB::table('sucursales')->where('es_corporativo', true)->value('id');

            if ($corpId !== null) {
                $corpAutorizada = (int) DB::table('headcount_targets')->where('sucursal_id', $corpId)->sum('plantilla_autorizada');
                $corpActiva = DB::table('colaboradores')
                    ->where('sucursal_principal_id', $corpId)
                    ->whereIn('estatus', ['activo', 'en_incorporacion'])
                    ->count();
                $this->dato('Corporativo: plazas autorizadas', $corpAutorizada);
                $this->dato('Corporativo: personas vigentes', $corpActiva);

                if ($corpAutorizada === 0) {
                    $this->alerta('Corporativo sin plazas autorizadas en headcount_targets: el Tablero mostrará la cifra sin Corporativo (p. ej. 121 en vez de 151). Requiere cargar las plazas del Corporativo (auditable).');
                } elseif ($corpAutorizada < $corpActiva) {
                    $this->alerta(sprintf('Corporativo con %d plazas autorizadas < %d personas vigentes: faltan plazas por autorizar.', $corpAutorizada, $corpActiva));
                }
            } else {
                $this->alerta('No hay sucursal marcada como Corporativo (sucursales.es_corporativo). Revisa la migración marcar_sucursal_corporativo.');
            }
        } catch (Throwable $e) {
            $this->alerta('No se pudo calcular la plantilla: '.$e->getMessage());
        }
    }

    /** 2. Diferencia entre colaborador activo y plaza ocupada. */
    private function activoVsPlaza(HeadcountService $headcount): void
    {
        $this->seccion('2. Activo vs plaza ocupada (población por KPI)');

        try {
            $totales = $headcount->totalesGenerales();
            $cobertura = $headcount->coberturaDetallada();
            $this->dato('Personas vigentes (activo + en incorporación)', $totales['personas_activas']);
            $this->dato('Plazas ocupadas (acotado a autorizadas)', $totales['plantilla_actual']);
            $this->dato('Fuera de plantilla (vigentes sin plaza autorizada)', $totales['fuera_de_plantilla']);
            $this->dato('Excedentes (activos por encima de autorizados)', $cobertura['totales']['excedentes']);

            if ($totales['fuera_de_plantilla'] > 0) {
                $this->alerta(sprintf('%d persona(s) vigentes no ocupan una plaza autorizada: definir si son plaza nueva por autorizar o excedente.', $totales['fuera_de_plantilla']));
            }
        } catch (Throwable $e) {
            $this->alerta('No se pudo calcular: '.$e->getMessage());
        }
    }

    /** 3. Directorio que hoy excluye gestores. */
    private function directorioExclusiones(): void
    {
        $this->seccion('3. Directorio: exclusiones (gestores)');

        try {
            $activos = DB::table('colaboradores')->where('estatus', 'activo')->count();
            $grupos = $this->stringList(config('directorio.excluir_grupos', []));
            $patrones = $this->stringList(config('directorio.excluir_puestos', []));

            $excluidos = DB::table('colaboradores')
                ->join('puestos', 'colaboradores.puesto_id', '=', 'puestos.id')
                ->where('colaboradores.estatus', 'activo')
                ->where(function ($q) use ($grupos, $patrones): void {
                    if ($grupos !== []) {
                        $q->whereIn('puestos.grupo_documental', $grupos);
                    }
                    foreach ($patrones as $patron) {
                        $q->orWhere('puestos.nombre', 'like', $patron);
                    }
                })
                ->count();

            $this->dato('Colaboradores activos', $activos);
            $this->dato('Excluidos hoy del Directorio', $excluidos);
            $this->dato('Visibles en el Directorio actual (aprox.)', max($activos - $excluidos, 0));

            if ($excluidos > 0) {
                $this->alerta(sprintf('%d colaborador(es) activos (grupos: %s) quedan fuera del Directorio. La propuesta pide mostrarlos todos con "Datos por completar".', $excluidos, implode(', ', $grupos) ?: '—'));
            }
        } catch (Throwable $e) {
            $this->alerta('No se pudo calcular: '.$e->getMessage());
        }
    }

    /** 4. Incapacidades históricas dentro de Solicitudes. */
    private function incapacidadesHistoricas(): void
    {
        $this->seccion('4. Incapacidades históricas en Solicitudes');

        if (! Schema::hasTable('solicitudes_internas')) {
            $this->alerta('No existe la tabla solicitudes_internas.');

            return;
        }

        $total = DB::table('solicitudes_internas')->where('tipo', 'incapacidad')->count();
        $this->dato('Solicitudes tipo incapacidad (históricas)', $total);

        if ($total > 0) {
            $this->alerta(sprintf('%d solicitud(es) de incapacidad históricas: preservar folios/adjuntos/historial como lectura; NO duplicar en el nuevo módulo ni re-aplicar efectos de nómina.', $total));
        } else {
            $this->ok('Sin incapacidades históricas que migrar.');
        }
    }

    /** 5. Estados anteriores de Bajas con intervención jurídica. */
    private function bajasJuridico(): void
    {
        $this->seccion('5. Bajas en revisión de Jurídico');

        if (! Schema::hasTable('bajas')) {
            $this->alerta('No existe la tabla bajas.');

            return;
        }

        $enJuridico = DB::table('bajas')->where('estatus', 'en_revision_juridico')->count();
        $this->dato('Bajas en estado en_revision_juridico', $enJuridico);

        if (Schema::hasTable('causales_rotacion')) {
            $causalesJuridico = DB::table('causales_rotacion')->where('requiere_juridico', true)->count();
            $this->dato('Causales con requiere_juridico = true', $causalesJuridico);
        }

        if ($enJuridico > 0) {
            $this->alerta(sprintf('%d baja(s) en en_revision_juridico: migrar explícitamente a un estado del nuevo flujo (p. ej. Faltan documentos / Lista para cerrar) al retirar la compuerta jurídica. Auditable, sin cambios silenciosos.', $enJuridico));
        } else {
            $this->ok('Sin bajas en en_revision_juridico: la migración de estados no afecta registros existentes.');
        }
    }

    /** 6. Cierres laborales y expedientes de bajas ya realizados. */
    private function cierresYExpedientes(): void
    {
        $this->seccion('6. Bajas registradas y cerradas');

        if (! Schema::hasTable('bajas')) {
            $this->alerta('No existe la tabla bajas.');

            return;
        }

        $total = DB::table('bajas')->count();
        $cerradas = DB::table('bajas')->where('estatus', 'cerrada')->count();
        $rotacion = Schema::hasColumn('bajas', 'es_rotacion') ? DB::table('bajas')->where('es_rotacion', true)->count() : 0;
        $this->dato('Bajas totales', $total);
        $this->dato('Bajas cerradas', $cerradas);
        $this->dato('Bajas marcadas como rotación', $rotacion);
        $this->ok('Reutilizar estos expedientes; no crear duplicados al unificar la navegación de Bajas.');
    }

    /** 7. Finiquitos calculados y documentos previamente emitidos. */
    private function finiquitosPrevios(): void
    {
        $this->seccion('7. Finiquitos calculados');

        if (! Schema::hasTable('finiquito_calculos')) {
            $this->alerta('No existe la tabla finiquito_calculos.');

            return;
        }

        $total = DB::table('finiquito_calculos')->count();
        $this->dato('Finiquitos calculados', $total);

        if (Schema::hasColumn('finiquito_calculos', 'estado')) {
            foreach (DB::table('finiquito_calculos')->select('estado', DB::raw('count(*) as c'))->groupBy('estado')->get() as $fila) {
                $this->dato('  estado '.(string) $fila->estado, (int) $fila->c);
            }
        }
        $this->ok('La pestaña Finiquitos reutiliza estos registros (mismo FiniquitoCalculo que el expediente).');
    }

    /** 8. Solapamientos entre Excel histórico y bajas del sistema. */
    private function rotacionSolapamientos(): void
    {
        $this->seccion('8. Rotación: Excel histórico vs bajas del sistema');

        $periodos = Schema::hasTable('rotacion_periodos') ? DB::table('rotacion_periodos')->count() : 0;
        $this->dato('Renglones de rotación importados (Excel)', $periodos);

        $corte = config('bajas.tablero.mes_inicio_sistema');
        $this->dato('Mes de corte (bajas del sistema son fuente vigente)', is_string($corte) ? $corte : '—');

        if (Schema::hasTable('bajas') && Schema::hasColumn('bajas', 'fecha_baja')) {
            $bajasRotacion = DB::table('bajas')
                ->where('estatus', 'cerrada')
                ->when(Schema::hasColumn('bajas', 'es_rotacion'), fn ($q) => $q->where('es_rotacion', true))
                ->whereNotNull('fecha_baja')
                ->count();
            $this->dato('Bajas de sistema elegibles para rotación', $bajasRotacion);
        }

        $this->ok('Conciliación por corte mensual (mes_inicio_sistema): antes = Excel, desde el corte = bajas del sistema. Una baja se cuenta una sola vez.');
    }

    /** 9. Salarios históricos frente a tabuladores vigentes. */
    private function salariosVsTabulador(): void
    {
        $this->seccion('9. Salarios vs tabulador');

        $tieneTabulador = Schema::hasTable('tabuladores_salariales');
        $this->dato('Existe tabla de tabulador', $tieneTabulador ? 'sí' : 'no');

        if (! $tieneTabulador) {
            $this->alerta('No existe tabulador (min/medio/máx por puesto): el módulo Administración de salarios se construye desde cero. El sueldo real vive en colaboradores + historial_salarios.');
        }

        try {
            $sinSueldo = DB::table('colaboradores')
                ->whereIn('estatus', ['activo', 'en_incorporacion'])
                ->where(function ($q): void {
                    $q->whereNull('sueldo_mensual')->orWhere('sueldo_mensual', '<=', 0);
                })
                ->count();
            $this->dato('Colaboradores vigentes sin sueldo capturado', $sinSueldo);
        } catch (Throwable $e) {
            $this->alerta('No se pudo contar sueldos: '.$e->getMessage());
        }
    }

    /** 10. Entrevistas y socioeconómicos ya registrados antes de la agenda. */
    private function agendaRegistrada(): void
    {
        $this->seccion('10. Entrevistas y socioeconómicos registrados');

        $entrevistas = Schema::hasTable('candidato_entrevistas') ? DB::table('candidato_entrevistas')->count() : 0;
        $socio = Schema::hasTable('candidato_socioeconomicos') ? DB::table('candidato_socioeconomicos')->count() : 0;
        $this->dato('Entrevistas registradas (realizadas)', $entrevistas);
        $this->dato('Estudios socioeconómicos registrados', $socio);
        $this->ok('Hoy solo se registran eventos realizados (sin cita/estatus). La Agenda agrega la capa de agendamiento y delega el resultado al workflow existente.');
    }

    /** 11. Fechas de alta IMSS pendientes. */
    private function fechaAltaImss(): void
    {
        $this->seccion('11. Fecha de alta en el IMSS');

        if (! Schema::hasColumn('colaboradores', 'fecha_alta_imss')) {
            $this->alerta('No existe la columna colaboradores.fecha_alta_imss.');

            return;
        }

        try {
            $base = DB::table('colaboradores')->whereIn('estatus', ['activo', 'en_incorporacion']);
            $conImss = (clone $base)->where('estatus_imss', 'con_imss')->count();
            $sinFecha = (clone $base)->where('estatus_imss', 'con_imss')->whereNull('fecha_alta_imss')->count();
            $this->dato('Vigentes con IMSS', $conImss);
            $this->dato('Vigentes con IMSS sin fecha de alta IMSS', $sinFecha);

            if ($sinFecha > 0) {
                $this->alerta(sprintf('%d colaborador(es) con IMSS sin fecha de alta IMSS: el reporte "Aniversario de alta IMSS" quedará incompleto hasta capturarla (dato independiente de la fecha de ingreso).', $sinFecha));
            }
        } catch (Throwable $e) {
            $this->alerta('No se pudo contar: '.$e->getMessage());
        }
    }

    /** 12. Datos necesarios para NOI. */
    private function datosNoi(): void
    {
        $this->seccion('12. Datos para Altas NOI');

        $campos = [
            'rfc' => 'RFC',
            'curp' => 'CURP',
            'nss' => 'NSS',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'domicilio_cp' => 'código postal',
            'salario_diario' => 'salario diario',
        ];

        foreach ($campos as $columna => $etiqueta) {
            if (! Schema::hasColumn('colaboradores', $columna)) {
                $this->alerta(sprintf('No existe la columna colaboradores.%s.', $columna));

                continue;
            }

            try {
                $faltan = DB::table('colaboradores')
                    ->whereIn('estatus', ['activo', 'en_incorporacion'])
                    ->where(function ($q) use ($columna): void {
                        $q->whereNull($columna)->orWhere($columna, '');
                    })
                    ->count();
                $this->dato(sprintf('Vigentes sin %s', $etiqueta), $faltan);
            } catch (Throwable $e) {
                $this->alerta(sprintf('No se pudo contar %s: %s', $etiqueta, $e->getMessage()));
            }
        }

        $this->ok('El layout real de Aspel NOI no está disponible: el exportador se entrega como "Pendiente de validar con NOI" (capa de mapeo configurable).');
    }

    /**
     * @param  mixed  $valor
     * @return list<string>
     */
    private function stringList($valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, 'is_string'));
    }

    private function seccion(string $titulo): void
    {
        $this->newLine();
        $this->line('<fg=blue>'.$titulo.'</>');
    }

    private function dato(string $etiqueta, int|float|string $valor): void
    {
        $this->resumen[$etiqueta] = is_float($valor) ? (string) $valor : $valor;
        $this->line(sprintf('  • %s: <options=bold>%s</>', $etiqueta, (string) $valor));
    }

    private function ok(string $mensaje): void
    {
        $this->line('  <fg=green>✓</> '.$mensaje);
    }

    private function alerta(string $mensaje): void
    {
        $this->line('  <fg=yellow>✗</> '.$mensaje);
    }
}
