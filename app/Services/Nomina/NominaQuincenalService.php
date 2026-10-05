<?php

namespace App\Services\Nomina;

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Enums\TipoConceptoNomina;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;
use ZipArchive;

/**
 * Recibos de nómina quincenales automáticos (docs/NOMINA_QUINCENAL.md).
 *
 *  - Quincena 1: del 1 al 15, se paga el 15. Quincena 2: del 16 al último
 *    día del mes, se paga el último día. Clave legible: «2026-10-1».
 *  - `preparar()` crea un BORRADOR por colaborador activo con sueldo
 *    (sueldo mensual / 2; proporcional si ingresó a media quincena). Quien
 *    no tiene sueldo capturado se reporta, nunca se le inventa un monto.
 *  - RH ajusta uno por uno (ReciboNominaService::actualizar) o en bloque
 *    (`aplicarMasivo()`): agregar/cambiar o quitar un concepto a todos.
 *  - `emitirPeriodo()`/`emitirVencidos()` emiten: PDF + aviso.
 *  - Centro de descarga: ZIP con un PDF por persona o un solo PDF para
 *    imprimir y recabar firmas.
 *
 * El cálculo NO es fiscal (no ISR/IMSS): es el comprobante interno que se
 * entrega al colaborador, igual que el resto de recibos del sistema.
 */
class NominaQuincenalService
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Quincena que contiene la fecha dada.
     *
     * @return array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     */
    public function periodo(CarbonInterface $fecha): array
    {
        $dia = CarbonImmutable::parse($fecha->toDateString())->startOfDay();
        $primera = $dia->day <= 15;
        $inicio = $primera ? $dia->startOfMonth() : $dia->startOfMonth()->addDays(15);
        $fin = $primera ? $dia->startOfMonth()->addDays(14) : $dia->endOfMonth()->startOfDay();
        $mitad = $primera ? 1 : 2;

        return [
            'clave' => sprintf('%d-%02d-%d', $inicio->year, $inicio->month, $mitad),
            'inicio' => $inicio,
            'fin' => $fin,
            'pago' => $fin,
            'numero' => ($inicio->month - 1) * 2 + $mitad,
            'etiqueta' => sprintf('%s quincena de %s %d', $primera ? '1.ª' : '2.ª', self::MESES[$inicio->month - 1], $inicio->year),
        ];
    }

    /**
     * @return array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     *
     * @throws ValidationException Clave inválida.
     */
    public function periodoPorClave(string $clave): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-([12])$/', $clave, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw ValidationException::withMessages(['periodo' => 'La quincena indicada no es válida.']);
        }

        return $this->periodo(CarbonImmutable::create((int) $m[1], (int) $m[2], $m[3] === '1' ? 1 : 16));
    }

    /**
     * La quincena siguiente a la indicada.
     *
     * @param  array{fin: CarbonImmutable}  $periodo
     * @return array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     */
    public function siguiente(array $periodo): array
    {
        return $this->periodo($periodo['fin']->addDay());
    }

    /**
     * Quincenas para el selector: la siguiente, la actual y las anteriores.
     *
     * @return list<array{clave: string, etiqueta: string, inicio: string, fin: string, pago: string}>
     */
    public function periodosParaSelector(int $anteriores = 12): array
    {
        $actual = $this->periodo(now('America/Mexico_City'));
        $lista = [$this->siguiente($actual), $actual];
        $cursor = $actual;

        for ($i = 0; $i < $anteriores; $i++) {
            $cursor = $this->periodo($cursor['inicio']->subDay());
            $lista[] = $cursor;
        }

        return array_map(fn (array $p): array => [
            'clave' => $p['clave'],
            'etiqueta' => $p['etiqueta'],
            'inicio' => $p['inicio']->toDateString(),
            'fin' => $p['fin']->toDateString(),
            'pago' => $p['pago']->toDateString(),
        ], $lista);
    }

    /**
     * Recibos quincenales del periodo dentro del alcance del usuario.
     *
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable}  $periodo
     * @return Builder<ReciboNomina>
     */
    public function recibosDelPeriodo(array $periodo, User $usuario): Builder
    {
        $query = ReciboNomina::query()
            ->where('tipo_periodo', ReciboNominaService::TIPO_PERIODO_QUINCENAL)
            ->whereDate('periodo_inicio', $periodo['inicio']->toDateString())
            ->whereDate('periodo_fin', $periodo['fin']->toDateString());

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        return $query;
    }

    /**
     * Crea los borradores de la quincena. Idempotente: a quien ya tiene
     * recibo de ese periodo no se le vuelve a crear.
     *
     * @param  array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable}  $periodo
     * @return array{creados: int, existentes: int, omitidos: list<array{colaborador_id: int, nombre: string, motivo: string}>}
     */
    public function preparar(array $periodo, ?User $actor = null): array
    {
        $resultado = ['creados' => 0, 'existentes' => 0, 'omitidos' => []];
        $concepto = (string) config('nomina.quincenal.concepto_sueldo', 'Sueldo quincenal');

        $yaTienen = ReciboNomina::query()
            ->whereDate('periodo_inicio', $periodo['inicio']->toDateString())
            ->whereDate('periodo_fin', $periodo['fin']->toDateString())
            ->pluck('colaborador_id')
            ->flip();

        $colaboradores = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where(fn (Builder $q) => $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso', '<=', $periodo['fin']->toDateString()))
            ->when($actor !== null && ! $this->alcance->tieneAlcanceGlobal($actor), fn (Builder $q) => $this->alcance->limitarColaboradoresPorAlcance($q, $actor))
            ->orderBy('id')
            ->get(['id', 'name', 'apellidos', 'sueldo_mensual', 'fecha_ingreso']);

        foreach ($colaboradores as $colaborador) {
            if ($yaTienen->has($colaborador->id)) {
                $resultado['existentes']++;

                continue;
            }

            $mensual = (float) ($colaborador->sueldo_mensual ?? 0);

            if ($mensual <= 0) {
                $resultado['omitidos'][] = ['colaborador_id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => 'No tiene sueldo mensual capturado en Datos laborales.'];

                continue;
            }

            [$importe, $etiqueta] = $this->sueldoDelPeriodo($mensual, $colaborador->fecha_ingreso, $periodo, $concepto);

            try {
                $this->recibos->generar($colaborador, [
                    'periodo_inicio' => $periodo['inicio']->toDateString(),
                    'periodo_fin' => $periodo['fin']->toDateString(),
                    'fecha_pago' => $periodo['pago']->toDateString(),
                    'tipo_periodo' => ReciboNominaService::TIPO_PERIODO_QUINCENAL,
                    'sueldo_base' => $importe,
                    'conceptos' => [['tipo' => TipoConceptoNomina::Percepcion->value, 'concepto' => $etiqueta, 'importe' => $importe]],
                ], $actor, 'Q-'.$periodo['clave'], borrador: true);
                $resultado['creados']++;
            } catch (ValidationException $e) {
                // Carrera con otra preparación: ya existe; se cuenta, no se duplica.
                $resultado['existentes']++;
            } catch (Throwable $e) {
                Log::warning('NominaQuincenalService: no se pudo preparar el recibo.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
                $resultado['omitidos'][] = ['colaborador_id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => 'Error al preparar: '.$e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * Sueldo de la quincena: la mitad del mensual; si ingresó a media
     * quincena, proporcional a los días trabajados (base 15 días).
     *
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable}  $periodo
     * @return array{0: float, 1: string}
     */
    private function sueldoDelPeriodo(float $mensual, ?CarbonInterface $ingreso, array $periodo, string $concepto): array
    {
        $quincena = round($mensual / 2, 2);

        if ($ingreso === null || $ingreso->lte($periodo['inicio'])) {
            return [$quincena, $concepto];
        }

        $dias = min(15, (int) $ingreso->startOfDay()->diffInDays($periodo['fin']) + 1);

        return [round($mensual / 30 * $dias, 2), sprintf('%s (%d días desde su ingreso)', $concepto, $dias)];
    }

    /**
     * Emite los borradores del periodo (o solo los indicados).
     *
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable}  $periodo
     * @param  array<int, int>|null  $ids
     * @return int Recibos emitidos.
     */
    public function emitirPeriodo(array $periodo, User $actor, ?array $ids = null): int
    {
        $emitidos = 0;

        $this->recibosDelPeriodo($periodo, $actor)
            ->where('estado', EstadoReciboNomina::Borrador->value)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->each(function (ReciboNomina $recibo) use ($actor, &$emitidos): void {
                $this->recibos->emitir($recibo, $actor);
                $emitidos++;
            });

        return $emitidos;
    }

    /**
     * Emite todos los borradores cuya fecha de pago ya llegó (proceso
     * automático, alcance global).
     */
    public function emitirVencidos(CarbonInterface $hoy, User $actor): int
    {
        $emitidos = 0;

        ReciboNomina::query()
            ->where('estado', EstadoReciboNomina::Borrador->value)
            ->whereDate('fecha_pago', '<=', $hoy->toDateString())
            ->orderBy('id')
            ->each(function (ReciboNomina $recibo) use ($actor, &$emitidos): void {
                $this->recibos->emitir($recibo, $actor);
                $emitidos++;
            });

        return $emitidos;
    }

    /**
     * Cambio en bloque a los recibos del periodo (o a los seleccionados):
     *  - `agregar`: agrega el concepto; si ya existe con ese nombre y tipo,
     *    le cambia el importe (aplicarlo dos veces no lo duplica);
     *  - `quitar`: elimina el concepto con ese nombre.
     *
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable}  $periodo
     * @param  array{accion: string, tipo: string, concepto: string, importe?: float|int|string|null}  $datos
     * @param  array<int, int>|null  $ids
     * @return int Recibos modificados.
     */
    public function aplicarMasivo(array $periodo, User $actor, array $datos, ?array $ids = null): int
    {
        $nombre = trim($datos['concepto']);
        $tipo = TipoConceptoNomina::from($datos['tipo'])->value;
        $modificados = 0;

        $this->recibosDelPeriodo($periodo, $actor)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->each(function (ReciboNomina $recibo) use ($actor, $datos, $nombre, $tipo, &$modificados): void {
                $conceptos = $this->recibos->conceptosEditables($recibo);
                $mismo = fn (array $c): bool => $c['tipo'] === $tipo && mb_strtolower($c['concepto']) === mb_strtolower($nombre);

                if ($datos['accion'] === 'quitar') {
                    $restantes = array_values(array_filter($conceptos, fn (array $c) => ! $mismo($c)));

                    if (count($restantes) === count($conceptos) || $restantes === []) {
                        return;
                    }

                    $conceptos = $restantes;
                } else {
                    $importe = round((float) ($datos['importe'] ?? 0), 2);
                    $existe = false;

                    foreach ($conceptos as $i => $c) {
                        if ($mismo($c)) {
                            $conceptos[$i]['importe'] = $importe;
                            $existe = true;
                        }
                    }

                    if (! $existe) {
                        $conceptos[] = ['tipo' => $tipo, 'concepto' => $nombre, 'cantidad' => 1.0, 'importe' => $importe, 'observaciones' => null];
                    }
                }

                $this->recibos->actualizar($recibo, ['conceptos' => $conceptos], $actor);
                $modificados++;
            });

        return $modificados;
    }

    /**
     * Usuario a nombre de quien se emiten los recibos automáticos: el
     * primer usuario activo con permiso para crear recibos.
     */
    public function responsableAutomatico(): ?User
    {
        return User::permission('nomina.recibos.crear')
            ->whereNull('acceso_bloqueado_en')
            ->orderBy('id')
            ->first();
    }

    /**
     * Recibos emitidos con PDF del periodo, listos para el centro de descarga.
     *
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable}  $periodo
     * @param  array<int, int>|null  $ids
     * @return Collection<int, ReciboNomina>
     */
    public function descargables(array $periodo, User $usuario, ?array $ids = null): Collection
    {
        return $this->recibosDelPeriodo($periodo, $usuario)
            ->where('estado', EstadoReciboNomina::Emitido->value)
            ->whereNotNull('pdf_path')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->with(['colaborador' => fn ($q) => $q->select('id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id'), 'colaborador.sucursalPrincipal:id,nombre'])
            ->get()
            ->sortBy(fn (ReciboNomina $r) => sprintf('%s|%s', $r->colaborador->sucursalPrincipal->nombre ?? '', $r->colaborador->nombreCompleto()))
            ->values()
            ->toBase();
    }

    /**
     * ZIP con un PDF por colaborador, agrupados por sucursal. Devuelve la
     * ruta de un archivo temporal (el controlador lo borra tras enviarlo).
     *
     * @param  Collection<int, ReciboNomina>  $recibos
     */
    public function zip(Collection $recibos, string $clave): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'recibos-');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del ZIP.');
        }

        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP de recibos.');
        }

        foreach ($recibos as $recibo) {
            $contenido = $this->contenidoPdf($recibo);

            if ($contenido === null) {
                continue;
            }

            $colaborador = $recibo->colaborador;
            $carpeta = $this->nombreSeguro($colaborador->sucursalPrincipal->nombre ?? 'Sin sucursal');
            $archivo = $this->nombreSeguro(sprintf('%s - %s - Quincena %s', $colaborador->numero_empleado ?? $colaborador->id, $colaborador->nombreCompleto(), $clave));
            $zip->addFromString(sprintf('%s/%s.pdf', $carpeta, $archivo), $contenido);
        }

        $zip->close();

        return $ruta;
    }

    /**
     * Un solo PDF con todos los recibos (para imprimir y recabar firmas).
     *
     * @param  Collection<int, ReciboNomina>  $recibos
     */
    public function pdfCombinado(Collection $recibos): string
    {
        $pdf = new Fpdi;

        foreach ($recibos as $recibo) {
            $contenido = $this->contenidoPdf($recibo);

            if ($contenido === null) {
                continue;
            }

            try {
                $paginas = $pdf->setSourceFile(StreamReader::createByString($contenido));

                for ($i = 1; $i <= $paginas; $i++) {
                    $plantilla = $pdf->importPage($i);
                    $tamano = $pdf->getTemplateSize($plantilla);

                    if (! is_array($tamano)) {
                        continue;
                    }

                    $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                    $pdf->useTemplate($plantilla);
                }
            } catch (Throwable $e) {
                Log::warning('NominaQuincenalService: no se pudo unir el PDF de un recibo.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);
            }
        }

        return $pdf->Output('S');
    }

    private function contenidoPdf(ReciboNomina $recibo): ?string
    {
        if ($recibo->pdf_disk === null || $recibo->pdf_path === null) {
            return null;
        }

        $contenido = Storage::disk($recibo->pdf_disk)->get($recibo->pdf_path);

        if ($contenido === null) {
            Log::warning('NominaQuincenalService: el PDF del recibo no está en el almacenamiento.', ['recibo_id' => $recibo->id]);
        }

        return $contenido;
    }

    private function nombreSeguro(string $valor): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]/', '', $valor)) ?: 'recibo';
    }
}
