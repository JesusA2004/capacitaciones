<?php

namespace App\Services\Nomina;

use App\Enums\AlcanceDatos;
use App\Enums\EstadoLoteNomina;
use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Enums\FamiliaAdministrativa;
use App\Enums\PeriodicidadNomina;
use App\Enums\TipoConceptoNomina;
use App\Models\Colaborador;
use App\Models\NominaLote;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Compensacion\CompensacionService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Permisos\AlcanceModuloService;
use App\Support\Paginacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Lote de recibos de nómina: PREPARAR → REVISAR → EMITIR
 * (docs/NOMINA_QUINCENAL.md). Recibo administrativo simple, no CFDI.
 *
 *  1. preparar()/importar(): crea el lote y sus recibos como BORRADOR. No
 *     son visibles al trabajador, no hay push, no hay notificación y no se
 *     marcan emitidos. Cada persona que no se pudo preparar queda en
 *     `errores`; lo que se preparó con dudas, en `advertencias`.
 *  2. RH revisa: resumen(), recibos() y vistaPreviaPdf() (PDF REAL con el
 *     formato oficial, sin guardar ni avisar nada).
 *  3. emitir(): SOLO entonces cada recibo se marca emitido (fecha + quién) y
 *     se congela su PDF en el expediente. Si features.recibos_colaborador
 *     está activo, además queda visible en el autoservicio y se le avisa al
 *     trabajador (in-app + push encolado); si está apagado (canon actual:
 *     recibo se entrega impreso), nunca aparece en la app del colaborador ni
 *     se le notifica — RH lo ve/descarga/imprime desde la experiencia RH.
 *     Idempotente: un lote emitido no se emite dos veces.
 *
 * Periodos: semanal = lunes → domingo; quincenal = 1 → 15 y 16 → último día
 * REAL del mes (28/29/30/31, nunca un 30 fijo).
 */
class LoteNominaService
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly ReciboNominaImportService $importador,
        private readonly AuditoriaService $auditoria,
        private readonly DocumentoAdministrativoService $documentos,
        private readonly DatosDocumentoAdministrativo $datosDocumento,
        private readonly CompensacionService $compensacion,
        private readonly CombinadorPdfRecibos $combinador,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

    /**
     * Periodo de pago que contiene la fecha dada.
     *
     * @return array{inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     */
    public function periodo(PeriodicidadNomina $periodicidad, CarbonInterface $fecha): array
    {
        $dia = CarbonImmutable::parse($fecha->toDateString())->startOfDay();

        if ($periodicidad === PeriodicidadNomina::Semanal) {
            $inicio = $dia->startOfWeek(CarbonInterface::MONDAY);
            $fin = $inicio->addDays(6);

            return [
                'inicio' => $inicio,
                'fin' => $fin,
                'pago' => $fin,
                'numero' => $inicio->isoWeek,
                'etiqueta' => sprintf('Semanal %s', $this->rango($inicio, $fin)),
            ];
        }

        $primera = $dia->day <= 15;
        $inicio = $primera ? $dia->startOfMonth() : $dia->startOfMonth()->addDays(15);
        // Último día REAL del mes: endOfMonth() (28, 29, 30 o 31).
        $fin = $primera ? $dia->startOfMonth()->addDays(14) : $dia->endOfMonth()->startOfDay();

        return [
            'inicio' => $inicio,
            'fin' => $fin,
            'pago' => $fin,
            'numero' => ($inicio->month - 1) * 2 + ($primera ? 1 : 2),
            'etiqueta' => sprintf('Quincenal %s', $this->rango($inicio, $fin)),
        ];
    }

    /**
     * Prepara el lote a partir del sueldo capturado de cada colaborador
     * activo dentro del alcance de quien lo crea.
     *
     * Grupo de pago (CLAUDE.md §51-59): sucursal.es_corporativo decide la
     * periodicidad, nunca el usuario a mano — sucursales = semanal,
     * corporativo = quincenal, nunca mezclados. Un colaborador cuyo grupo no
     * coincide con $periodicidad simplemente no entra a ESTE lote (le toca
     * el suyo); uno sin sucursal asignada no se adivina: queda como error
     * explícito. $sucursalIds (solo aplica a semanal) permite acotar a
     * sucursales específicas en vez de "todas".
     *
     * @param  list<int>|null  $sucursalIds
     */
    public function preparar(PeriodicidadNomina $periodicidad, CarbonInterface $fecha, User $actor, ?array $sucursalIds = null): NominaLote
    {
        $periodo = $this->periodo($periodicidad, $fecha);
        // Antes de crear el lote: sin alcance de nómina no queda un lote vacío bloqueando el periodo.
        $global = $this->exigirAlcanceNomina($actor);
        $lote = $this->crearLote($periodicidad, $periodo, NominaLote::ORIGEN_SUELDOS, null, $actor, $sucursalIds);

        $colaboradores = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre,es_corporativo'])
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where(fn (Builder $q) => $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso', '<=', $periodo['fin']->toDateString()))
            ->when(! $global, fn (Builder $q) => $this->alcanceModulo->limitarColaboradores($q, $actor, 'recibos'))
            ->orderBy('id')
            ->get();

        [$errores, $advertencias, $esperados] = $this->generarRecibosDelLote($lote, $colaboradores, $periodo, $periodicidad, $actor, $sucursalIds);

        return $this->cerrarPreparacion($lote, $esperados, $errores, $advertencias, $actor);
    }

    /**
     * Recalcula ÚNICAMENTE los recibos en BORRADOR de un lote (CLAUDE.md
     * §44-50): el lote dejó de reflejar la realidad (p. ej. se preparó antes
     * de importar salarios) y RH necesita volver a calcular con los datos
     * laborales vigentes, sin borrar manualmente ni recrear el lote desde
     * cero. Bloqueado en cuanto exista CUALQUIER recibo emitido/cancelado —
     * un PDF emitido es inmutable, nunca se recalcula "alrededor" de él.
     *
     * Reconstruye el conjunto original de colaboradores (los que ya tienen
     * un recibo borrador MÁS los que figuran en los errores por
     * numero_empleado, p. ej. "sin sueldo" antes de importar salarios) para
     * que una corrección de datos laborales pueda sacarlos del error sin
     * perder de vista a nadie del lote original.
     */
    public function recalcular(NominaLote $lote, User $actor): NominaLote
    {
        return DB::transaction(function () use ($lote, $actor): NominaLote {
            $fila = NominaLote::query()->whereKey($lote->id)->lockForUpdate()->first();

            if ($fila === null || $fila->estado !== EstadoLoteNomina::Preparado) {
                throw ValidationException::withMessages(['lote' => 'Solo un lote preparado (todavía no emitido) se puede recalcular.']);
            }

            $recibosActuales = $fila->recibos()->get();

            if ($recibosActuales->contains(fn (ReciboNomina $r) => $r->estado !== EstadoReciboNomina::Borrador)) {
                throw ValidationException::withMessages(['lote' => 'Este lote ya tiene recibos emitidos o cancelados: no se puede recalcular.']);
            }

            $idsConRecibo = $recibosActuales->pluck('colaborador_id')->all();
            $sinClasificar = array_values(array_filter($fila->advertencias ?? [], fn (array $a): bool => (bool) ($a['sin_clasificacion'] ?? false)));
            $idsDeErrores = $this->colaboradorIdsDeErrores([...($fila->errores ?? []), ...$sinClasificar]);

            $colaboradores = Colaborador::query()
                ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre,es_corporativo'])
                ->whereIn('id', array_values(array_unique([...$idsConRecibo, ...$idsDeErrores])))
                ->orderBy('id')
                ->get();

            // Limpio: se vuelve a generar todo desde cero con los datos
            // vigentes. Un borrador no descuenta del préstamo; si uno anterior
            // a esa regla ya lo había hecho, se compensa antes de borrarlo.
            $recibosActuales->each(function (ReciboNomina $r) use ($actor): void {
                $this->recibos->revertirDescuentosPrestamo($r, $actor);
                $r->delete();
            });

            $periodo = ['inicio' => CarbonImmutable::parse($fila->periodo_inicio), 'fin' => CarbonImmutable::parse($fila->periodo_fin), 'pago' => CarbonImmutable::parse($fila->fecha_pago)];
            // Conserva EXACTAMENTE el filtro de sucursales con el que se
            // preparó el lote originalmente (CLAUDE.md §43): null = sin
            // filtro (todas las del grupo de pago correspondiente), nunca
            // se amplía solo porque se recalcula.
            [$errores, $advertencias, $esperados] = $this->generarRecibosDelLote($fila, $colaboradores, $periodo, $fila->periodicidad, $actor, $fila->sucursal_ids);

            $loteActualizado = $this->cerrarPreparacion($fila, $esperados, $errores, $advertencias, $actor);

            $this->auditoria->registrar('nomina_lote_recalculado', $loteActualizado, $actor, [
                'folio' => $loteActualizado->folio,
                'esperados' => $esperados,
                'preparados' => $loteActualizado->preparados,
                'errores' => count($errores),
                'advertencias' => count($advertencias),
            ]);

            return $loteActualizado;
        });
    }

    /**
     * Resuelve a qué colaborador corresponde cada error guardado en el lote
     * (CLAUDE.md §41): primero su colaborador_id (ya persistido desde esta
     * versión); si el error es de un lote anterior a este cambio y no lo
     * tiene, cae a numero_empleado exacto y único, y si tampoco, a nombre
     * exacto normalizado y único — NUNCA fuzzy. Si sigue siendo ambiguo
     * (0 o 2+ coincidencias), el colaborador simplemente no entra al
     * recálculo: el error se mantiene explícito en vez de adivinar.
     *
     * @param  list<array<string, mixed>>  $errores
     * @return list<int>
     */
    private function colaboradorIdsDeErrores(array $errores): array
    {
        $ids = [];
        $pendientes = [];

        foreach ($errores as $error) {
            if (! empty($error['colaborador_id'])) {
                $ids[] = (int) $error['colaborador_id'];

                continue;
            }

            $pendientes[] = $error;
        }

        if ($pendientes === []) {
            return $ids;
        }

        $numeros = collect($pendientes)->pluck('numero_empleado')->filter()->unique()->values();
        $porNumero = $numeros->isEmpty() ? collect() : Colaborador::query()
            ->whereIn('numero_empleado', $numeros->all())
            ->get(['id', 'numero_empleado'])
            ->groupBy('numero_empleado');

        $nombresPendientes = [];

        foreach ($pendientes as $error) {
            $numero = $error['numero_empleado'] ?? null;
            $coincidenciasNumero = $numero !== null ? $porNumero->get($numero) : null;

            if ($coincidenciasNumero !== null && $coincidenciasNumero->count() === 1) {
                $ids[] = $coincidenciasNumero->first()->id;

                continue;
            }

            $nombre = $error['colaborador'] ?? null;
            if ($nombre !== null && trim((string) $nombre) !== '') {
                $nombresPendientes[] = $nombre;
            }
        }

        if ($nombresPendientes !== []) {
            $normalizadosBuscados = array_unique(array_map(fn (string $n) => $this->normalizarNombre($n), $nombresPendientes));
            $candidatos = Colaborador::query()->get(['id', 'name', 'apellidos'])
                ->groupBy(fn (Colaborador $c) => $this->normalizarNombre($c->nombreCompleto()));

            foreach ($normalizadosBuscados as $normalizado) {
                $coincidencias = $candidatos->get($normalizado);

                if ($coincidencias !== null && $coincidencias->count() === 1) {
                    $ids[] = $coincidencias->first()->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizarNombre(string $nombre): string
    {
        return Str::of($nombre)->ascii()->upper()->squish()->toString();
    }

    /**
     * Genera (o reporta el motivo por el que NO se genera) un recibo
     * borrador por cada colaborador del grupo de pago correspondiente a
     * $periodicidad — núcleo compartido por preparar() y recalcular().
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable}  $periodo
     * @param  list<int>|null  $sucursalIds
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int} [errores, advertencias, esperados]
     */
    private function generarRecibosDelLote(NominaLote $lote, Collection $colaboradores, array $periodo, PeriodicidadNomina $periodicidad, User $actor, ?array $sucursalIds): array
    {
        $errores = [];
        $advertencias = [];
        $esperados = 0;

        $conRecibo = ReciboNomina::query()
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->where('nomina_lote_id', '!=', $lote->id)
            ->where('tipo_periodo', $periodicidad->value)
            ->whereDate('periodo_inicio', $periodo['inicio']->toDateString())
            ->whereDate('periodo_fin', $periodo['fin']->toDateString())
            ->pluck('colaborador_id')
            ->flip();

        foreach ($colaboradores as $colaborador) {
            $identidad = ['colaborador_id' => $colaborador->id, 'numero_empleado' => $colaborador->numero_empleado, 'colaborador' => $colaborador->nombreCompleto()];
            $sucursal = $colaborador->sucursalPrincipal;

            // Sin sucursal no se sabe a qué grupo de pago pertenece: se
            // reporta como advertencia (para que RH lo clasifique) pero NO
            // cuenta en los esperados del grupo ni reduce el lote a ella.
            if ($sucursal === null) {
                $advertencias[] = [...$identidad, 'sin_clasificacion' => true, 'motivo' => 'Sin clasificación de nómina. Asigna ubicación organizacional antes de incluirlo.'];

                continue;
            }

            // No pertenece a este grupo de pago (p. ej. preparando semanal y
            // es colaborador corporativo): no es error, le toca su propio
            // lote quincenal — nunca se mezclan los dos grupos.
            $grupoEsperado = $sucursal->es_corporativo ? PeriodicidadNomina::Quincenal : PeriodicidadNomina::Semanal;
            if ($grupoEsperado !== $periodicidad) {
                continue;
            }

            if ($periodicidad === PeriodicidadNomina::Semanal && $sucursalIds !== null && ! in_array($sucursal->id, $sucursalIds, true)) {
                continue;
            }

            $esperados++;

            if ($conRecibo->has($colaborador->id)) {
                $errores[] = [...$identidad, 'motivo' => 'Ya tiene un recibo de este periodo (duplicado): no se volvió a preparar.'];

                continue;
            }

            // Fuente canónica del salario: salario_diario (CompensacionService
            // ::diarioDe), nunca sueldo_mensual directo — un colaborador con
            // diario capturado pero sin mensual sincronizado NO debe marcarse
            // como "sin sueldo".
            $diario = $this->compensacion->diarioDe($colaborador);

            if ($diario <= 0) {
                $errores[] = [...$identidad, 'motivo' => 'No tiene salario diario ni sueldo mensual capturado en Datos laborales.'];

                continue;
            }

            $dias = $periodicidad->diasBase();
            $avisos = [];

            // Ingresó a media semana/quincena: proporcional a los días trabajados.
            if ($colaborador->fecha_ingreso !== null && $colaborador->fecha_ingreso->gt($periodo['inicio'])) {
                $dias = min($dias, (int) $colaborador->fecha_ingreso->startOfDay()->diffInDays($periodo['fin']) + 1);
                $avisos[] = sprintf('Ingresó el %s: se pagan %d día(s) proporcionales.', $colaborador->fecha_ingreso->format('d/m/Y'), $dias);
            }

            foreach (['rfc' => 'RFC', 'curp' => 'CURP', 'nss' => 'NSS'] as $campo => $etiqueta) {
                if (blank($colaborador->getAttribute($campo))) {
                    $avisos[] = sprintf('Sin %s capturado: el recibo saldrá con ese campo vacío.', $etiqueta);
                }
            }

            try {
                $this->recibos->generar($colaborador, [
                    'periodo_inicio' => $periodo['inicio']->toDateString(),
                    'periodo_fin' => $periodo['fin']->toDateString(),
                    'fecha_pago' => $periodo['pago']->toDateString(),
                    'tipo_periodo' => $periodicidad->value,
                    'nomina_lote_id' => $lote->id,
                    'dias_pagados' => $dias,
                    'dias_falta' => 0,
                    'dias_incapacidad' => 0,
                    'advertencias' => $avisos,
                    'conceptos' => [['tipo' => TipoConceptoNomina::Percepcion->value, 'clave' => '001', 'concepto' => 'Sueldo', 'cantidad' => $dias, 'importe' => round($diario * $dias, 2)]],
                ], $actor, $lote->folio, borrador: true);
            } catch (ValidationException $e) {
                $errores[] = [...$identidad, 'motivo' => collect($e->errors())->flatten()->implode(' ')];

                continue;
            } catch (Throwable $e) {
                Log::warning('LoteNominaService: no se pudo preparar un recibo.', ['lote' => $lote->folio, 'colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
                $errores[] = [...$identidad, 'motivo' => 'Error inesperado al preparar el recibo; revisa el log.'];

                continue;
            }

            foreach ($avisos as $aviso) {
                $advertencias[] = [...$identidad, 'motivo' => $aviso];
            }
        }

        return [$errores, $advertencias, $esperados];
    }

    /**
     * Importa el lote desde el CSV/XLSX de RH (formato de
     * ReciboNominaImportService). Igual que preparar(): solo borradores.
     *
     * @param  array{simular?: bool}  $opciones
     * @return array{lote: NominaLote|null, resultado: array<string, mixed>}
     */
    public function importar(UploadedFile $archivo, PeriodicidadNomina $periodicidad, CarbonInterface $fecha, User $actor, array $opciones = []): array
    {
        $periodo = $this->periodo($periodicidad, $fecha);
        $simular = (bool) ($opciones['simular'] ?? false);
        $datosPeriodo = [
            'periodo_inicio' => $periodo['inicio']->toDateString(),
            'periodo_fin' => $periodo['fin']->toDateString(),
            'fecha_pago' => $periodo['pago']->toDateString(),
            'tipo_periodo' => $periodicidad->value,
            'simular' => $simular,
        ];

        if ($simular) {
            return ['lote' => null, 'resultado' => $this->importador->importar($archivo, $datosPeriodo, $actor)];
        }

        $lote = $this->crearLote($periodicidad, $periodo, NominaLote::ORIGEN_IMPORTACION, $archivo->getClientOriginalName(), $actor, null);
        $resultado = $this->importador->importar($archivo, [...$datosPeriodo, 'nomina_lote_id' => $lote->id, 'lote_folio' => $lote->folio], $actor);
        $numerosConError = collect($resultado['errores'])->pluck('numero_empleado')->filter()->unique();
        $colaboradoresConError = Colaborador::query()->whereIn('numero_empleado', $numerosConError->all())->get(['id', 'name', 'numero_empleado'])->keyBy('numero_empleado');
        $errores = array_map(fn (array $e) => [
            'colaborador_id' => $e['numero_empleado'] !== null ? $colaboradoresConError->get($e['numero_empleado'])?->id : null,
            'numero_empleado' => $e['numero_empleado'],
            'colaborador' => $e['numero_empleado'] !== null ? $colaboradoresConError->get($e['numero_empleado'])?->name : null,
            'motivo' => $e['fila'] !== null ? sprintf('Fila %d: %s', $e['fila'], $e['motivo']) : $e['motivo'],
            'fila' => $e['fila'],
        ], $resultado['errores']);
        $advertencias = [];

        foreach ($lote->recibos()->with('colaborador:id,name,apellidos,numero_empleado,rfc,curp,nss')->get() as $recibo) {
            $avisos = [];

            foreach (['rfc' => 'RFC', 'curp' => 'CURP', 'nss' => 'NSS'] as $campo => $etiqueta) {
                if (blank($recibo->colaborador->getAttribute($campo))) {
                    $avisos[] = sprintf('Sin %s capturado: el recibo saldrá con ese campo vacío.', $etiqueta);
                }
            }

            if ((float) $recibo->neto <= 0) {
                $avisos[] = 'El neto del recibo es $0.00 o negativo.';
            }

            if ($avisos !== []) {
                $recibo->update(['advertencias' => $avisos]);

                foreach ($avisos as $aviso) {
                    $advertencias[] = ['numero_empleado' => $recibo->colaborador->numero_empleado, 'colaborador' => $recibo->colaborador->nombreCompleto(), 'motivo' => $aviso];
                }
            }
        }

        $esperados = count($resultado['colaboradores']) + $numerosConError->count();

        return ['lote' => $this->cerrarPreparacion($lote, $esperados, $errores, $advertencias, $actor), 'resultado' => $resultado];
    }

    /**
     * Resumen para la pantalla de revisión (y para la app RH).
     *
     * @return array<string, mixed>
     */
    public function resumen(NominaLote $lote): array
    {
        $lote->loadMissing(['creadoPor:id,name,apellidos', 'emitidoPor:id,name,apellidos', 'canceladoPor:id,name,apellidos']);
        $nombre = fn (?User $u) => $u !== null ? trim($u->name.' '.$u->apellidos) : null;
        $porEstado = $lote->recibos()->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado');

        return [
            'id' => $lote->id,
            'folio' => $lote->folio,
            'periodicidad' => $lote->periodicidad->value,
            'periodicidad_etiqueta' => $lote->periodicidad->etiqueta(),
            'etiqueta' => sprintf('%s %s', $lote->periodicidad->etiqueta(), $this->rango(CarbonImmutable::parse($lote->periodo_inicio), CarbonImmutable::parse($lote->periodo_fin))),
            'periodo_inicio' => $lote->periodo_inicio->toDateString(),
            'periodo_fin' => $lote->periodo_fin->toDateString(),
            'fecha_pago' => $lote->fecha_pago->toDateString(),
            'numero_nomina' => $lote->numero_nomina,
            'origen' => $lote->origen,
            'archivo_nombre' => $lote->archivo_nombre,
            'estado' => $lote->estado->value,
            'estado_etiqueta' => $lote->estado->etiqueta(),
            'esperados' => $lote->esperados,
            'preparados' => $lote->preparados,
            'por_emitir' => (int) ($porEstado[EstadoReciboNomina::Borrador->value] ?? 0),
            'emitidos' => (int) ($porEstado[EstadoReciboNomina::Emitido->value] ?? 0),
            'errores' => $lote->errores ?? [],
            'advertencias' => $lote->advertencias ?? [],
            'total_errores' => count($lote->errores ?? []),
            'total_advertencias' => count($lote->advertencias ?? []),
            'total_percepciones' => (string) $lote->total_percepciones,
            'total_deducciones' => (string) $lote->total_deducciones,
            'total_neto' => (string) $lote->total_neto,
            'creado_por' => $nombre($lote->creadoPor),
            'creado_en' => $lote->created_at->toIso8601String(),
            'emitido_por' => $nombre($lote->emitidoPor),
            'emitido_at' => $lote->emitido_at?->toIso8601String(),
            'cancelado_por' => $nombre($lote->canceladoPor),
            'cancelado_at' => $lote->cancelado_at?->toIso8601String(),
            'motivo_cancelacion' => $lote->motivo_cancelacion,
        ];
    }

    /**
     * Lotes visibles para el usuario (histórico), más recientes primero.
     *
     * @return LengthAwarePaginator<int, NominaLote>
     */
    public function listar(User $usuario, int $porPagina = 15): LengthAwarePaginator
    {
        return NominaLote::query()
            ->when(! $this->esNominaGlobal($usuario), fn (Builder $q) => $q->where('creado_por', $usuario->id))
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina($porPagina));
    }

    /**
     * Recibos del lote para la revisión masiva, con filtro
     * todos | correctos | advertencias | errores.
     *
     * @return list<array<string, mixed>>
     */
    public function recibos(NominaLote $lote, User $usuario, string $filtro = 'todos'): array
    {
        $filas = $this->recibosVisibles($lote, $usuario)
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->select('id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'puesto_id'), 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre'])
            ->orderBy('id')
            ->get()
            ->map(fn (ReciboNomina $r) => [
                'id' => $r->id,
                'folio' => $r->folio,
                'colaborador_id' => $r->colaborador_id,
                'numero_empleado' => $r->colaborador->numero_empleado,
                'nombre' => $r->colaborador->nombreCompleto(),
                'sucursal' => $r->colaborador->sucursalPrincipal?->nombre,
                'puesto' => $r->colaborador->puesto?->nombre,
                'total_percepciones' => (string) $r->total_percepciones,
                'total_deducciones' => (string) $r->total_deducciones,
                'neto' => (string) $r->neto,
                'estado' => $r->estado->value,
                'estado_etiqueta' => $r->estado->etiqueta(),
                'advertencias' => $r->advertencias ?? [],
                'revision' => ($r->advertencias ?? []) !== [] ? 'advertencia' : 'correcto',
            ]);

        // Los errores no tienen recibo (no se pudieron preparar): se listan aparte.
        $errores = collect($lote->errores ?? [])->map(fn (array $e, int $i) => [
            'id' => null,
            'folio' => null,
            'colaborador_id' => null,
            'numero_empleado' => $e['numero_empleado'] ?? null,
            'nombre' => $e['colaborador'] ?? 'Sin colaborador identificado',
            'sucursal' => null,
            'puesto' => null,
            'total_percepciones' => null,
            'total_deducciones' => null,
            'neto' => null,
            'estado' => 'error',
            'estado_etiqueta' => 'No preparado',
            'advertencias' => [$e['motivo']],
            'revision' => 'error',
            'clave_error' => $i,
        ]);

        return array_values(match ($filtro) {
            'correctos' => $filas->where('revision', 'correcto')->values()->all(),
            'advertencias' => $filas->where('revision', 'advertencia')->values()->all(),
            'errores' => $errores->all(),
            default => [...$filas->all(), ...$errores->all()],
        });
    }

    /**
     * Cuántos recibos entran al paquete de impresión del lote y cuántos de
     * ellos todavía no tienen su PDF congelado (para la UI de RH).
     *
     * @return array{total: int, sin_pdf: int}
     */
    public function estadoImpresion(NominaLote $lote, User $usuario): array
    {
        $recibos = $this->recibosParaImpresion($lote, $usuario);

        return ['total' => $recibos->count(), 'sin_pdf' => $recibos->reject(fn (ReciboNomina $r) => $this->listoParaImprimir($r))->count()];
    }

    /**
     * «Descargar todos para imprimir»: UN solo PDF con los recibos del lote
     * EMITIDO, uno tras otro, ordenados por sucursal y luego por nombre del
     * colaborador. Une los PDFs CONGELADOS al emitir (formato oficial de cada
     * recibo, CombinadorPdfRecibos) — nunca regenera ni inventa un recibo:
     * si a alguno le falta su PDF, se bloquea la descarga completa.
     * El PDF compuesto no se guarda; solo se audita la descarga.
     *
     * @return array{contenido: string, nombre: string, total: int}
     *
     * @throws ValidationException
     */
    public function pdfImpresion(NominaLote $lote, User $actor): array
    {
        if ($lote->estado !== EstadoLoteNomina::Emitido) {
            throw ValidationException::withMessages(['lote' => 'Solo un lote emitido se puede descargar para imprimir.']);
        }

        $recibos = $this->recibosParaImpresion($lote, $actor);

        if ($recibos->isEmpty()) {
            throw ValidationException::withMessages(['lote' => 'Este lote no tiene recibos emitidos para imprimir.']);
        }

        $sinPdf = $recibos->reject(fn (ReciboNomina $r) => $this->listoParaImprimir($r))->count();

        if ($sinPdf > 0) {
            throw ValidationException::withMessages(['lote' => $sinPdf === 1 ? 'Hay 1 recibo sin PDF generado.' : sprintf('Hay %d recibos sin PDF generado.', $sinPdf)]);
        }

        try {
            $contenido = $this->combinador->combinar($recibos, estricto: true);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['lote' => $e->getMessage()]);
        }

        $this->auditoria->registrar('descarga_masiva_recibos', $lote, $actor, [
            'folio' => $lote->folio,
            'recibos' => $recibos->count(),
            'periodo_inicio' => $lote->periodo_inicio->toDateString(),
            'periodo_fin' => $lote->periodo_fin->toDateString(),
        ]);

        return [
            'contenido' => $contenido,
            'nombre' => sprintf(
                'recibos_nomina_%s_%s_%s.pdf',
                Str::slug($lote->periodicidad->value, '_'),
                $lote->periodo_inicio->toDateString(),
                $lote->periodo_fin->toDateString(),
            ),
            'total' => $recibos->count(),
        ];
    }

    /**
     * PDF REAL del recibo con el formato oficial vigente, sin guardarlo, sin
     * marcarlo emitido y sin avisar a nadie (revisión previa).
     */
    public function vistaPreviaPdf(ReciboNomina $recibo): string
    {
        return $this->documentos->renderizarVigente(FamiliaAdministrativa::ReciboNomina, $this->datosDocumento->recibo($recibo));
    }

    /**
     * EMITE el lote: cada borrador se publica (PDF congelado en el
     * expediente; visible en autoservicio y avisado al trabajador solo si
     * features.recibos_colaborador está activo — ver
     * ReciboNominaService::notificarColaborador()). Solo un lote preparado;
     * un segundo intento sobre un lote emitido es rechazado.
     *
     * Reintento seguro: si una emisión anterior se cortó a mitad (el lote
     * quedó emitido con recibos todavía en borrador), volver a emitir solo
     * publica los pendientes. Cada recibo se emite una sola vez
     * (ReciboNominaService::emitir es idempotente), así que nadie recibe
     * dos avisos.
     *
     * @return array{emitidos: int, fallidos: list<array{folio: string, motivo: string}>, lote: NominaLote}
     */
    public function emitir(NominaLote $lote, User $actor): array
    {
        $reclamado = DB::transaction(function () use ($lote, $actor): bool {
            $fila = NominaLote::query()->whereKey($lote->id)->lockForUpdate()->first();

            if ($fila === null) {
                return false;
            }

            if ($fila->estado === EstadoLoteNomina::Emitido) {
                return $fila->recibos()->where('estado', EstadoReciboNomina::Borrador->value)->exists();
            }

            if ($fila->estado !== EstadoLoteNomina::Preparado) {
                return false;
            }

            $fila->update(['estado' => EstadoLoteNomina::Emitido, 'emitido_por' => $actor->id, 'emitido_at' => now()]);

            return true;
        });

        if (! $reclamado) {
            $lote->refresh();

            throw ValidationException::withMessages(['lote' => match ($lote->estado) {
                EstadoLoteNomina::Emitido => 'Este lote ya fue emitido; no se vuelve a publicar ni a notificar.',
                EstadoLoteNomina::Cancelado => 'Este lote está cancelado.',
                default => 'El lote todavía no está listo para emitirse.',
            }]);
        }

        $emitidos = 0;
        $fallidos = [];

        // Uno por uno: si el PDF de un recibo falla, ese queda en borrador
        // (reintentable volviendo a emitir el lote) y los demás sí se emiten.
        $lote->recibos()->where('estado', EstadoReciboNomina::Borrador->value)->orderBy('id')->each(function (ReciboNomina $recibo) use ($actor, &$emitidos, &$fallidos): void {
            try {
                $this->recibos->emitir($recibo, $actor);
                $emitidos++;
            } catch (ValidationException $e) {
                $fallidos[] = ['folio' => (string) ($recibo->folio ?? $recibo->id), 'motivo' => (string) (collect($e->errors())->flatten()->first() ?? 'No se pudo generar el PDF.')];
            }
        });

        $this->auditoria->registrar('lote_nomina_emitido', $lote, $actor, ['folio' => $lote->folio, 'emitidos' => $emitidos, 'fallidos' => count($fallidos)]);

        return ['emitidos' => $emitidos, 'fallidos' => $fallidos, 'lote' => $lote->refresh()];
    }

    /**
     * Cancela el lote con motivo. Sus recibos quedan cancelados (si ya se
     * habían emitido, dejan de verse en web/app) y el periodo se puede
     * volver a preparar. Nunca se borra nada.
     */
    public function cancelar(NominaLote $lote, string $motivo, User $actor): NominaLote
    {
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la cancelación.']);
        }

        DB::transaction(function () use ($lote, $motivo, $actor): void {
            $fila = NominaLote::query()->whereKey($lote->id)->lockForUpdate()->first();

            if ($fila === null || $fila->estado === EstadoLoteNomina::Cancelado) {
                throw ValidationException::withMessages(['lote' => 'Este lote ya está cancelado.']);
            }

            $fila->update(['estado' => EstadoLoteNomina::Cancelado, 'cancelado_por' => $actor->id, 'cancelado_at' => now(), 'motivo_cancelacion' => mb_substr(trim($motivo), 0, 500)]);
            $porCancelar = $fila->recibos()->where('estado', '!=', EstadoReciboNomina::Cancelado->value)->get();
            $fila->recibos()->whereKey($porCancelar->modelKeys())->update([
                'estado' => EstadoReciboNomina::Cancelado->value,
                'cancelado_at' => now(),
                'cancelado_por' => $actor->id,
            ]);

            // Recibos ya emitidos que descontaron de un préstamo: el saldo
            // regresa (movimiento compensatorio idempotente). Los borradores
            // nunca descontaron, así que no generan nada.
            $porCancelar->each(fn (ReciboNomina $recibo) => $this->recibos->revertirDescuentosPrestamo($recibo, $actor));
        });

        $this->auditoria->registrar('lote_nomina_cancelado', $lote, $actor, ['folio' => $lote->folio, 'motivo' => $motivo]);

        return $lote->refresh();
    }

    /**
     * Recibos del lote acotados al alcance de quien mira (RH con alcance
     * global ve todos).
     *
     * @return Builder<ReciboNomina>
     */
    private function recibosVisibles(NominaLote $lote, User $usuario): Builder
    {
        return $lote->recibos()->getQuery()
            ->when(! $this->esNominaGlobal($usuario), fn (Builder $q) => $q->whereIn('colaborador_id', $this->alcanceModulo->limitarColaboradores(Colaborador::query()->withTrashed(), $usuario, 'recibos')->select('id')));
    }

    /**
     * Recibos no cancelados del lote en orden de impresión: sucursal →
     * nombre del colaborador (el mismo orden del centro de descarga
     * quincenal). Un borrador que quedó de una emisión cortada entra al
     * conteo como «sin PDF», para que nadie imprima un lote incompleto.
     *
     * @return Collection<int, ReciboNomina>
     */
    private function recibosParaImpresion(NominaLote $lote, User $usuario): Collection
    {
        return $this->recibosVisibles($lote, $usuario)
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->select('id', 'name', 'apellidos', 'sucursal_principal_id'), 'colaborador.sucursalPrincipal:id,nombre'])
            ->get()
            ->sortBy(fn (ReciboNomina $r) => sprintf('%s|%s|%d', $r->colaborador->sucursalPrincipal->nombre ?? '', $r->colaborador->nombreCompleto(), $r->id), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->toBase();
    }

    private function listoParaImprimir(ReciboNomina $recibo): bool
    {
        return $recibo->estado === EstadoReciboNomina::Emitido && $this->combinador->tienePdf($recibo);
    }

    /**
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int}  $periodo
     * @param  list<int>|null  $sucursalIds
     */
    private function crearLote(PeriodicidadNomina $periodicidad, array $periodo, string $origen, ?string $archivo, User $actor, ?array $sucursalIds): NominaLote
    {
        return DB::transaction(function () use ($periodicidad, $periodo, $origen, $archivo, $actor, $sucursalIds): NominaLote {
            // Un solo lote vivo (en revisión) por periodo: evita lotes y recibos duplicados.
            $vivo = NominaLote::query()
                ->where('periodicidad', $periodicidad->value)
                ->whereDate('periodo_inicio', $periodo['inicio']->toDateString())
                ->whereDate('periodo_fin', $periodo['fin']->toDateString())
                ->whereIn('estado', [EstadoLoteNomina::Borrador->value, EstadoLoteNomina::Preparado->value, EstadoLoteNomina::Emitido->value])
                ->lockForUpdate()
                ->first();

            if ($vivo !== null) {
                throw ValidationException::withMessages(['periodo' => $vivo->estado === EstadoLoteNomina::Emitido
                    ? sprintf('Este periodo ya tiene un lote emitido (%s). Si hay que rehacerlo, cancélalo primero con su motivo.', $vivo->folio)
                    : sprintf('Ya hay un lote en revisión para este periodo (%s): emítelo o cancélalo antes de crear otro.', $vivo->folio)]);
            }

            return NominaLote::query()->create([
                'folio' => sprintf('NOM-%s-%s-%s', $periodicidad === PeriodicidadNomina::Semanal ? 'S' : 'Q', $periodo['inicio']->format('Ymd'), Str::upper(Str::random(4))),
                'periodicidad' => $periodicidad,
                'periodo_inicio' => $periodo['inicio']->toDateString(),
                'periodo_fin' => $periodo['fin']->toDateString(),
                'fecha_pago' => $periodo['pago']->toDateString(),
                'numero_nomina' => $periodo['numero'],
                'origen' => $origen,
                'sucursal_ids' => $sucursalIds,
                'archivo_nombre' => $archivo,
                'estado' => EstadoLoteNomina::Borrador,
                'creado_por' => $actor->id,
            ]);
        });
    }

    /**
     * Población de nómina = permiso de nómina + ALCANCE DEL MÓDULO «recibos»
     * (config/permisos_modulos.php), nunca la navegación de Mi espacio:
     * super_admin y alcance «todos» → toda la organización (true); «región»
     * o «sucursal» → sus sucursales (false). Con alcance propio/equipo no
     * hay población de nómina: se dice explícitamente en vez de armar en
     * silencio un lote de una sola persona.
     */
    private function exigirAlcanceNomina(User $actor): bool
    {
        if ($this->esNominaGlobal($actor)) {
            return true;
        }

        if (! in_array($this->alcanceModulo->nivel($actor, 'recibos'), [AlcanceDatos::Region, AlcanceDatos::Sucursal], true)) {
            throw ValidationException::withMessages(['lote' => 'Tu cuenta no tiene alcance de nómina sobre sucursales (Roles y permisos → Recibos de nómina: Todos, Región o Sucursal): no se puede preparar el lote.']);
        }

        return false;
    }

    private function esNominaGlobal(User $usuario): bool
    {
        return $usuario->hasRole('super_admin')
            || $this->alcanceModulo->nivel($usuario, 'recibos') === AlcanceDatos::Todos;
    }

    /**
     * @param  list<array<string, mixed>>  $errores
     * @param  list<array<string, mixed>>  $advertencias
     */
    private function cerrarPreparacion(NominaLote $lote, int $esperados, array $errores, array $advertencias, User $actor): NominaLote
    {
        $totales = $lote->recibos()->selectRaw('count(*) as total, coalesce(sum(total_percepciones), 0) as percepciones, coalesce(sum(total_deducciones), 0) as deducciones, coalesce(sum(neto), 0) as neto')->toBase()->first();

        $lote->update([
            'estado' => EstadoLoteNomina::Preparado,
            'esperados' => $esperados,
            'preparados' => (int) ($totales->total ?? 0),
            'errores' => $errores,
            'advertencias' => $advertencias,
            'total_percepciones' => round((float) ($totales->percepciones ?? 0), 2),
            'total_deducciones' => round((float) ($totales->deducciones ?? 0), 2),
            'total_neto' => round((float) ($totales->neto ?? 0), 2),
        ]);

        $this->auditoria->registrar('lote_nomina_preparado', $lote, $actor, [
            'folio' => $lote->folio,
            'esperados' => $esperados,
            'preparados' => $lote->preparados,
            'errores' => count($errores),
            'advertencias' => count($advertencias),
        ]);

        return $lote->refresh();
    }

    private function rango(CarbonImmutable $inicio, CarbonImmutable $fin): string
    {
        return $inicio->month === $fin->month
            ? sprintf('%d–%d %s %d', $inicio->day, $fin->day, self::MESES[$fin->month - 1], $fin->year)
            : sprintf('%d %s – %d %s %d', $inicio->day, self::MESES[$inicio->month - 1], $fin->day, self::MESES[$fin->month - 1], $fin->year);
    }
}
