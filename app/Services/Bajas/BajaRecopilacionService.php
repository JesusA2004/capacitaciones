<?php

namespace App\Services\Bajas;

use App\Enums\EstadoFiniquito;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\MimeTypes;

/**
 * «Bajas y finiquitos» → Recopilación de documentos: una tarjeta por
 * persona dada de baja con TODOS sus documentos de la baja —los generados
 * (renuncia, aviso, evaluación, acta…), el finiquito y su entrega, los
 * adjuntos de la baja— con su estado (generado, firmado, pendiente) y la URL
 * protegida para verlos en el visor sin ir a Expedientes.
 *
 * Alcance: módulo «cierres» (el mismo de Bajas y finiquitos,
 * AlcanceOrganizacionalService::limitarPorModulo). Cada URL de vista previa
 * solo se entrega si quien mira puede abrirla (GeneratedDocumentPolicy,
 * finiquitos.ver, permiso de la baja): el endpoint vuelve a autorizar.
 *
 * Avance N/M = documentos OBLIGATORIOS para cerrar cumplidos / total (los
 * mismos de BajaDocumentosService, RN-05).
 */
class BajaRecopilacionService
{
    /** Bajas que se revisan para el filtro de documentos y el conteo (las más recientes). */
    public const LIMITE_CALCULO = 300;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly BajaDocumentosService $documentos,
        private readonly BajaConsultaService $consulta,
        private readonly DocumentoProcesoService $documentosProceso,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * @return Builder<Baja>
     */
    public function consulta(User $usuario): Builder
    {
        $query = Baja::query()->whereNotNull('cierre_laboral_id');

        if (! $usuario->can('cierres.ver')) {
            return $query->whereRaw('1 = 0');
        }

        $this->alcance->limitarPorModulo($query, $usuario, 'cierres', 'colaborador_id', true);

        return $query;
    }

    /**
     * Tarjetas de la página con filtros: estatus (abiertas | cerrada |
     * cancelada | todas), sucursal, forma, documentos (completos |
     * incompletos) y texto libre (sin mínimo de letras).
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function tarjetas(User $usuario, array $filtros): LengthAwarePaginator
    {
        $porPagina = Paginacion::porPagina(12, $filtros['per_page'] ?? null);
        $pagina = max(1, (int) ($filtros['page'] ?? 1));
        $documentos = in_array($filtros['documentos'] ?? null, ['completos', 'incompletos'], true) ? (string) $filtros['documentos'] : null;
        $query = $this->filtrada($usuario, $filtros);

        if ($documentos === null) {
            $total = (clone $query)->count();
            $bajas = $query->forPage($pagina, $porPagina)->get();
        } else {
            // La completitud se calcula por baja (adjuntos + lo que lleva el
            // sistema): se revisan las más recientes del filtro.
            $candidatas = $query->limit(self::LIMITE_CALCULO)->get();
            $filtradas = $candidatas->filter(fn (Baja $b): bool => ($this->documentos->faltantes($b) === []) === ($documentos === 'completos'))->values();
            $total = $filtradas->count();
            $bajas = $filtradas->forPage($pagina, $porPagina)->values();
        }

        return new LengthAwarePaginator(
            $this->construir($bajas, $usuario),
            $total,
            $porPagina,
            $pagina,
            ['path' => request()->url(), 'query' => array_filter(request()->query(), fn ($v): bool => $v !== null && $v !== '')],
        );
    }

    /**
     * Números grandes del encabezado.
     *
     * @return array{en_tramite: int, juridico: int, cerradas: int, no_procede: int, documentos_pendientes: int}
     */
    public function resumen(User $usuario): array
    {
        $base = $this->consulta($usuario);
        $conteos = (clone $base)->selectRaw('estatus, count(*) as total')->groupBy('estatus')->pluck('total', 'estatus');
        $abiertas = (clone $base)->with(['causal', 'cierre', 'documentos'])->whereIn('estatus', EstatusBaja::valoresAbiertos())->orderByDesc('id')->limit(self::LIMITE_CALCULO)->get();

        return [
            'en_tramite' => (int) ($conteos[EstatusBaja::EnRevisionRh->value] ?? 0),
            'juridico' => (int) ($conteos[EstatusBaja::EnRevisionJuridico->value] ?? 0),
            'cerradas' => (int) ($conteos[EstatusBaja::Cerrada->value] ?? 0),
            'no_procede' => (int) ($conteos[EstatusBaja::Cancelada->value] ?? 0),
            'documentos_pendientes' => $abiertas->filter(fn (Baja $b): bool => $this->documentos->faltantes($b) !== [])->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Baja>
     */
    private function filtrada(User $usuario, array $filtros): Builder
    {
        $estatus = is_string($filtros['estatus'] ?? null) && $filtros['estatus'] !== '' ? $filtros['estatus'] : 'abiertas';
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));

        return $this->consulta($usuario)
            ->with(['causal', 'cierre', 'documentos.cargadoPor', 'sucursal:id,nombre', 'puesto:id,nombre', 'colaborador' => fn ($q) => $q->withTrashed()])
            ->when($estatus === 'abiertas', fn (Builder $q) => $q->whereIn('estatus', EstatusBaja::valoresAbiertos()))
            ->when(! in_array($estatus, ['abiertas', 'todas'], true) && EstatusBaja::tryFrom($estatus) !== null, fn (Builder $q) => $q->where('estatus', $estatus))
            ->when(is_numeric($filtros['sucursal_id'] ?? null), fn (Builder $q) => $q->where('sucursal_id', (int) $filtros['sucursal_id']))
            ->when(FormaSalida::tryFrom((string) ($filtros['forma'] ?? '')) !== null, fn (Builder $q) => $q->where('forma_salida', (string) $filtros['forma']))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $b) => $b
                ->where('nombre_colaborador', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('numero_empleado', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('folio', 'like', sprintf('%%%s%%', $busqueda))))
            ->orderByDesc('fecha_baja')
            ->orderByDesc('id');
    }

    /**
     * @param  Collection<int, Baja>  $bajas
     * @return list<array<string, mixed>>
     */
    private function construir(Collection $bajas, User $usuario): array
    {
        $cierreIds = $bajas->pluck('cierre_laboral_id')->filter()->values()->all();
        $generados = GeneratedDocument::query()
            ->where('documentable_type', (new CierreLaboral)->getMorphClass())
            ->whereIn('documentable_id', $cierreIds)
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get()
            ->groupBy('documentable_id');
        $solicitudes = $bajas->map(fn (Baja $b): ?int => $b->cierre?->solicitud_interna_id)->filter()->values()->all();
        $finiquitos = FiniquitoCalculo::query()->whereIn('solicitud_interna_id', $solicitudes)->get()->keyBy('solicitud_interna_id');

        return array_values($bajas->map(function (Baja $baja) use ($usuario, $generados, $finiquitos): array {
            $cierre = $baja->cierre;
            $finiquito = $cierre?->solicitud_interna_id !== null ? $finiquitos->get($cierre->solicitud_interna_id) : null;

            return $this->tarjeta($baja, $usuario, ($generados->get((int) $baja->cierre_laboral_id) ?? collect())->unique('clave_plantilla')->values(), $finiquito);
        })->all());
    }

    /**
     * @param  Collection<int, GeneratedDocument>  $generados  Vigentes del cierre, uno por clave (el más reciente).
     * @return array<string, mixed>
     */
    private function tarjeta(Baja $baja, User $usuario, Collection $generados, ?FiniquitoCalculo $finiquito): array
    {
        $cierre = $baja->cierre;
        $espacios = $this->documentos->espacios($baja);
        $obligatorios = array_values(array_filter($espacios, fn (array $e): bool => $e['obligatorio']));
        $puedeAdjuntar = $baja->estatus->esAbierta() && ! $baja->estatus->esLegado() && $this->puedeAdjuntar($usuario, $baja);
        $porSistema = $this->espaciosPorSistema($baja);
        // Espacios ya cumplidos (adjunto o sistema): no se ofrece adjuntar otra vez.
        $cumplidos = array_column(array_filter($espacios, fn (array $e): bool => $e['id'] !== null && $e['cumplido']), 'id');
        $pendiente = fn (?array $espacio): ?int => $espacio !== null && ! in_array($espacio['id'], $cumplidos, true) ? (int) $espacio['id'] : null;
        // Claves de documentos del sistema que ya tienen su renglón (para no
        // repetir el espacio obligatorio que ellos mismos cumplen).
        $representadas = [];
        $items = [];

        // 1. Documentos que generó el sistema (renuncia, aviso, evaluación, acta…).
        $clavesEsperadas = $cierre !== null && $baja->origen->value !== 'cierre_previo' ? $this->documentosProceso->clavesCausa($cierre) : [];

        foreach ($generados as $documento) {
            $clave = (string) $documento->clave_plantilla;
            $firmado = $documento->estado_flujo?->firmaFisicaRegistrada() ?? false;
            $espacio = $porSistema[$clave] ?? null;
            // Firmado en papel o con su copia firmada adjunta a la baja.
            $conCopia = ! $firmado && $espacio !== null && in_array($espacio['id'], $cumplidos, true);
            $verPdf = $usuario->can('descargar', $documento);
            $representadas[] = $clave;

            $items[] = [
                'clave' => sprintf('gen-%d', $documento->id),
                'nombre' => $this->documentosProceso->nombreDocumento($clave),
                'origen' => 'generado',
                'estado' => $firmado || $conCopia ? 'firmado' : 'generado',
                'estado_etiqueta' => match (true) {
                    $firmado => 'Firmado',
                    $conCopia => 'Firmado · copia adjunta',
                    default => 'Generado · falta firma',
                },
                'obligatorio' => $espacio !== null,
                'requerido_id' => $firmado ? null : $pendiente($espacio),
                'url' => $verPdf ? route('rh.documentos-laborales.descargar', $documento->id) : null,
                'url_descarga' => null,
                'mime' => 'application/pdf',
                'fecha' => $documento->created_at?->toDateString(),
            ];
        }

        foreach (array_diff($clavesEsperadas, $generados->pluck('clave_plantilla')->all()) as $clave) {
            $espacio = $porSistema[$clave] ?? null;
            $representadas[] = $clave;

            $items[] = [
                'clave' => sprintf('pend-%s', $clave),
                'nombre' => $this->documentosProceso->nombreDocumento($clave),
                'origen' => 'generado',
                'estado' => 'pendiente',
                'estado_etiqueta' => 'Por generar',
                'obligatorio' => $espacio !== null,
                'requerido_id' => $pendiente($espacio),
                'url' => null,
                'url_descarga' => null,
                'mime' => null,
                'fecha' => null,
            ];
        }

        // 2. Finiquito (propuesto / calculado / entregado) y su entrega.
        if ($cierre !== null && $usuario->can('finiquitos.ver')) {
            $representadas[] = 'finiquito';
            $entregado = $finiquito !== null && (in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true) || $finiquito->pagado_en !== null);
            $url = match (true) {
                $finiquito === null => null,
                $finiquito->documento_firmado_path !== null || $finiquito->documento_generado_path !== null => route('rh.solicitudes.finiquito.descargar-pdf', $finiquito->solicitud_interna_id),
                default => route('rh.cierres.finiquito.vistaPrevia', $cierre->id),
            };
            // descargar-pdf sirve el escaneo firmado si existe (puede ser una
            // foto JPG/PNG): el visor necesita su MIME real para mostrarlo.
            $mimeFiniquito = $url === null ? null : $this->mimeFiniquito($finiquito);

            $items[] = [
                'clave' => 'finiquito',
                'nombre' => 'Finiquito',
                'origen' => 'finiquito',
                'estado' => match (true) {
                    $finiquito === null => 'pendiente',
                    $entregado => 'firmado',
                    default => 'generado',
                },
                'estado_etiqueta' => match (true) {
                    $finiquito === null => 'Por calcular',
                    $entregado => sprintf('Entregado · $%s', number_format((float) $finiquito->neto, 2)),
                    default => sprintf('Propuesto · $%s', number_format((float) $finiquito->neto, 2)),
                },
                'obligatorio' => isset($porSistema['finiquito']),
                'requerido_id' => $entregado ? null : $pendiente($porSistema['finiquito'] ?? null),
                'url' => $url,
                'url_descarga' => null,
                'mime' => $mimeFiniquito,
                'fecha' => $finiquito?->fecha_calculo?->toDateString(),
            ];

            $items[] = [
                'clave' => 'entrega_finiquito',
                'nombre' => 'Entrega de finiquito',
                'origen' => 'finiquito',
                'estado' => $finiquito?->pagado_en !== null ? 'firmado' : 'pendiente',
                'estado_etiqueta' => $finiquito?->pagado_en !== null ? sprintf('Registrada %s', $finiquito->pagado_en->format('d/m/Y')) : 'Pendiente',
                'obligatorio' => false,
                'requerido_id' => null,
                'url' => $finiquito?->pagado_en !== null ? $url : null,
                'url_descarga' => null,
                'mime' => $finiquito?->pagado_en !== null ? $mimeFiniquito : null,
                'fecha' => $finiquito?->pagado_en?->toDateString(),
            ];
        }

        // 3. Adjuntos de la baja (aviso de rescisión, entrevista de salida,
        // evidencias, autorizaciones…) y los obligatorios que faltan.
        foreach ($espacios as $espacio) {
            foreach ($espacio['archivos'] as $archivo) {
                $items[] = [
                    'clave' => sprintf('adj-%d', $archivo['id']),
                    'nombre' => $espacio['id'] === null ? (string) $archivo['nombre'] : $espacio['nombre'],
                    'origen' => 'adjunto',
                    'estado' => 'firmado',
                    'estado_etiqueta' => 'Adjunto',
                    'obligatorio' => $espacio['obligatorio'],
                    'requerido_id' => null,
                    'url' => $archivo['url'],
                    'url_descarga' => $archivo['url_descarga'],
                    'mime' => $archivo['mime'],
                    'fecha' => is_string($archivo['fecha']) ? substr($archivo['fecha'], 0, 10) : null,
                ];
            }

            $sistema = $this->claveSistema($baja, $espacio['id']);

            // Pendiente propio: un obligatorio sin adjunto que no representa
            // ya un documento del sistema listado arriba.
            if ($espacio['id'] !== null && ! $espacio['cumplido'] && $espacio['archivos'] === [] && ($sistema === null || ! in_array($sistema, $representadas, true))) {
                $items[] = [
                    'clave' => sprintf('req-%d', $espacio['id']),
                    'nombre' => $espacio['nombre'],
                    'origen' => 'adjunto',
                    'estado' => 'pendiente',
                    'estado_etiqueta' => $espacio['obligatorio'] ? 'Falta adjuntar' : 'Opcional',
                    'obligatorio' => $espacio['obligatorio'],
                    'requerido_id' => $espacio['id'],
                    'url' => null,
                    'url_descarga' => null,
                    'mime' => null,
                    'fecha' => null,
                ];
            }
        }

        $colaborador = $baja->colaborador;

        return [
            ...$this->consulta->fila($baja),
            'colaborador_id' => $baja->colaborador_id,
            'cierre_id' => $baja->cierre_laboral_id,
            'foto_url' => $colaborador !== null ? $this->fotos->url($colaborador) : null,
            'avance' => [
                'completos' => count(array_filter($obligatorios, fn (array $e): bool => $e['cumplido'])),
                'total' => count($obligatorios),
            ],
            'documentos' => $items,
            'puede_adjuntar' => $puedeAdjuntar,
            'puede_ver_baja' => $this->consulta->puedeEntrar($usuario) && $this->consulta->puedeVer($usuario, $baja),
        ];
    }

    /**
     * Espacios de la baja que cumple un documento del sistema, por la clave
     * de ese documento (carta_renuncia, aviso_terminacion, finiquito…).
     *
     * @return array<string, array<string, mixed>>
     */
    private function espaciosPorSistema(Baja $baja): array
    {
        $porId = [];

        foreach ($this->documentos->requeridos($baja) as $requerido) {
            if ($requerido->documento_sistema !== null && $requerido->obligatorio_para_cerrar) {
                $porId[$requerido->documento_sistema] = ['id' => $requerido->id, 'nombre' => $requerido->nombre];
            }
        }

        return $porId;
    }

    /**
     * MIME de lo que sirve rh.solicitudes.finiquito.descargar-pdf: el escaneo
     * firmado (PDF o imagen, por su extensión real) o el PDF generado/vista previa.
     */
    private function mimeFiniquito(?FiniquitoCalculo $finiquito): string
    {
        $ruta = $finiquito?->documento_firmado_path;

        if ($ruta === null) {
            return 'application/pdf';
        }

        return MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)))[0] ?? 'application/pdf';
    }

    private function claveSistema(Baja $baja, ?int $requeridoId): ?string
    {
        if ($requeridoId === null) {
            return null;
        }

        return $this->documentos->requeridos($baja)->firstWhere('id', $requeridoId)?->documento_sistema;
    }

    private function puedeAdjuntar(User $usuario, Baja $baja): bool
    {
        return $usuario->can('bajas.gestionar')
            || ($usuario->can('bajas.solicitar') && $baja->colaborador !== null && $usuario->colaborador_id !== $baja->colaborador_id
                && $this->alcance->alcanzaColaboradorEnModulo($usuario, $baja->colaborador, BajaConsultaService::MODULO));
    }
}
