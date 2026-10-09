<?php

namespace App\Services\Nomina;

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
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
 *  3. emitir(): SOLO entonces cada recibo se marca emitido (fecha + quién),
 *     se congela su PDF en el expediente, queda visible en web/app y se le
 *     avisa al trabajador (in-app + push encolado). Idempotente: un lote
 *     emitido no se emite dos veces.
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
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly DocumentoAdministrativoService $documentos,
        private readonly DatosDocumentoAdministrativo $datosDocumento,
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
     */
    public function preparar(PeriodicidadNomina $periodicidad, CarbonInterface $fecha, User $actor): NominaLote
    {
        $periodo = $this->periodo($periodicidad, $fecha);
        $lote = $this->crearLote($periodicidad, $periodo, NominaLote::ORIGEN_SUELDOS, null, $actor);
        $errores = [];
        $advertencias = [];

        $colaboradores = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where(fn (Builder $q) => $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso', '<=', $periodo['fin']->toDateString()))
            ->when(! $this->alcance->tieneAlcanceGlobal($actor), fn (Builder $q) => $this->alcance->limitarColaboradoresPorAlcance($q, $actor))
            ->orderBy('id')
            ->get();

        $conRecibo = ReciboNomina::query()
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->whereDate('periodo_inicio', $periodo['inicio']->toDateString())
            ->whereDate('periodo_fin', $periodo['fin']->toDateString())
            ->pluck('colaborador_id')
            ->flip();

        foreach ($colaboradores as $colaborador) {
            $identidad = ['numero_empleado' => $colaborador->numero_empleado, 'colaborador' => $colaborador->nombreCompleto()];

            if ($conRecibo->has($colaborador->id)) {
                $errores[] = [...$identidad, 'motivo' => 'Ya tiene un recibo de este periodo (duplicado): no se volvió a preparar.'];

                continue;
            }

            $mensual = (float) ($colaborador->sueldo_mensual ?? 0);

            if ($mensual <= 0) {
                $errores[] = [...$identidad, 'motivo' => 'No tiene sueldo mensual capturado en Datos laborales.'];

                continue;
            }

            $diario = round($mensual / 30, 2);
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

        return $this->cerrarPreparacion($lote, $colaboradores->count(), $errores, $advertencias, $actor);
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

        $lote = $this->crearLote($periodicidad, $periodo, NominaLote::ORIGEN_IMPORTACION, $archivo->getClientOriginalName(), $actor);
        $resultado = $this->importador->importar($archivo, [...$datosPeriodo, 'nomina_lote_id' => $lote->id, 'lote_folio' => $lote->folio], $actor);
        $numerosConError = collect($resultado['errores'])->pluck('numero_empleado')->filter()->unique();
        $nombres = Colaborador::query()->whereIn('numero_empleado', $numerosConError->all())->pluck('name', 'numero_empleado');
        $errores = array_map(fn (array $e) => [
            'numero_empleado' => $e['numero_empleado'],
            'colaborador' => $e['numero_empleado'] !== null ? ($nombres[$e['numero_empleado']] ?? null) : null,
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
            ->when(! $this->alcance->tieneAlcanceGlobal($usuario), fn (Builder $q) => $q->where('creado_por', $usuario->id))
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(max(1, min(50, $porPagina)));
    }

    /**
     * Recibos del lote para la revisión masiva, con filtro
     * todos | correctos | advertencias | errores.
     *
     * @return list<array<string, mixed>>
     */
    public function recibos(NominaLote $lote, User $usuario, string $filtro = 'todos'): array
    {
        $filas = $lote->recibos()
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->select('id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'puesto_id'), 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre'])
            ->when(! $this->alcance->tieneAlcanceGlobal($usuario), fn (Builder $q) => $q->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id')))
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
     * PDF REAL del recibo con el formato oficial vigente, sin guardarlo, sin
     * marcarlo emitido y sin avisar a nadie (revisión previa).
     */
    public function vistaPreviaPdf(ReciboNomina $recibo): string
    {
        return $this->documentos->renderizarVigente(FamiliaAdministrativa::ReciboNomina, $this->datosDocumento->recibo($recibo));
    }

    /**
     * EMITE el lote: cada borrador se publica (PDF congelado en el
     * expediente, visible en web/app, aviso in-app + push). Solo un lote
     * preparado; un segundo intento sobre un lote emitido es rechazado.
     *
     * Reintento seguro: si una emisión anterior se cortó a mitad (el lote
     * quedó emitido con recibos todavía en borrador), volver a emitir solo
     * publica los pendientes. Cada recibo se emite una sola vez
     * (ReciboNominaService::emitir es idempotente), así que nadie recibe
     * dos avisos.
     *
     * @return array{emitidos: int, lote: NominaLote}
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

        $lote->recibos()->where('estado', EstadoReciboNomina::Borrador->value)->orderBy('id')->each(function (ReciboNomina $recibo) use ($actor, &$emitidos): void {
            $this->recibos->emitir($recibo, $actor);
            $emitidos++;
        });

        $this->auditoria->registrar('lote_nomina_emitido', $lote, $actor, ['folio' => $lote->folio, 'emitidos' => $emitidos]);

        return ['emitidos' => $emitidos, 'lote' => $lote->refresh()];
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
            $fila->recibos()->where('estado', '!=', EstadoReciboNomina::Cancelado->value)->update([
                'estado' => EstadoReciboNomina::Cancelado->value,
                'cancelado_at' => now(),
                'cancelado_por' => $actor->id,
            ]);
        });

        $this->auditoria->registrar('lote_nomina_cancelado', $lote, $actor, ['folio' => $lote->folio, 'motivo' => $motivo]);

        return $lote->refresh();
    }

    /**
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int}  $periodo
     */
    private function crearLote(PeriodicidadNomina $periodicidad, array $periodo, string $origen, ?string $archivo, User $actor): NominaLote
    {
        return DB::transaction(function () use ($periodicidad, $periodo, $origen, $archivo, $actor): NominaLote {
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
                'archivo_nombre' => $archivo,
                'estado' => EstadoLoteNomina::Borrador,
                'creado_por' => $actor->id,
            ]);
        });
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
