<?php

namespace App\Services\Expedientes;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoCartaCompromiso;
use App\Enums\Genero;
use App\Enums\MotorPdf;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Pdf\LogoPdfService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use App\Services\RhMobile\ResponsableResolverService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CARTA COMPROMISO DE DOCUMENTOS PENDIENTES.
 *
 * Quien se registra en la app y marca «No tengo este documento» en uno o
 * varios documentos requeridos recibe UNA sola carta con todos ellos y un
 * plazo (config('expedientes.carta_compromiso.dias_plazo'), 7 días por
 * defecto) para subirlos. Durante el plazo usa la app con normalidad; al
 * vencer con documentos faltantes, la app queda limitada a subirlos
 * (BloqueoDocumentosService / middleware documentos.pendientes).
 *
 * - Solo una carta `vigente` por persona: generar otra sustituye a la anterior
 *   (que se conserva como historial).
 * - `documentos` congela la lista comprometida al generarla.
 * - El PDF (Chrome/Browsershot) se guarda en el expediente del NAS y se
 *   registra como GeneratedDocument (MotorDocumentalService::registrarPdf),
 *   así aparece en «Documentos laborales» y en la exportación del expediente.
 *   Si la impresión falla, la carta sigue vigente y el PDF se genera al
 *   pedirlo (pdf()).
 * - sincronizar() marca `cumplida` cuando ya se subieron todos (en revisión
 *   cuenta) o `vencida` al pasar la fecha límite.
 * - Una carta cubre como máximo maxDocumentos() documentos
 *   (config('expedientes.carta_compromiso.max_documentos'), 2 por defecto).
 *   El nuevo ingreso que cerró su registro con más faltantes queda con la
 *   carta «diferida» (RegistroAppService::cartaDiferida): sin carta y con
 *   la app limitada a subir documentos. En cuanto sus faltantes bajan al
 *   máximo, sincronizar() genera la carta sola con exactamente esos
 *   documentos (el plazo empieza a correr entonces). Si llega a 0, no hace
 *   falta carta. El personal que ya laboraba nunca recibe carta automática.
 */
class CartaCompromisoService
{
    public const CLAVE = 'carta_compromiso_documentos';

    public const PROCESO = 'expediente';

    public function __construct(
        private readonly DocumentosPendientesService $pendientes,
        private readonly PdfRendererFactory $pdf,
        private readonly FirmaPatronService $firmaPatron,
        private readonly LogoPdfService $logos,
        private readonly DocumentoStorageService $storage,
        private readonly AuditoriaService $auditoria,
        private readonly NotificacionEventosService $eventos,
        private readonly ResponsableResolverService $responsables,
    ) {}

    public function diasPlazo(): int
    {
        return max(1, (int) config('expedientes.carta_compromiso.dias_plazo', 7));
    }

    /** Máximo de documentos que puede cubrir una sola carta. */
    public function maxDocumentos(): int
    {
        return max(1, (int) config('expedientes.carta_compromiso.max_documentos', 2));
    }

    /**
     * ¿Nuevo ingreso que cerró su registro con más documentos faltantes que
     * el máximo de una carta y todavía espera que se le genere la suya?
     */
    public function esperaCartaAutomatica(Colaborador $colaborador): bool
    {
        $usuario = $colaborador->user;

        if ($usuario === null || ! $this->registroApp()->cartaDiferida($usuario)) {
            return false;
        }

        return ! CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->exists();
    }

    /** La carta más reciente que no fue sustituida (vigente, cumplida o vencida). */
    public function ultima(Colaborador $colaborador): ?CartaCompromiso
    {
        return CartaCompromiso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', '!=', EstadoCartaCompromiso::Sustituida->value)
            ->latest('id')
            ->first();
    }

    public function cartaVigente(Colaborador $colaborador): ?CartaCompromiso
    {
        return CartaCompromiso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoCartaCompromiso::Vigente->value)
            ->latest('id')
            ->first();
    }

    /**
     * Genera la carta con TODOS los documentos indicados (deben estar entre
     * los pendientes de la persona), guarda el PDF en su expediente y avisa a
     * RH. La carta se crea aunque la impresión falle.
     *
     * @param  array<int, int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function generar(Colaborador $colaborador, array $documentTypeIds, User $actor): CartaCompromiso
    {
        return $this->completarGeneracion($this->crear($colaborador, $documentTypeIds, $actor), $actor);
    }

    /**
     * Solo la fila (sustituye a la vigente anterior). Pensado para usarse
     * dentro de la transacción de quien llama (p. ej. el cierre del registro);
     * después se llama completarGeneracion() ya fuera de la transacción.
     *
     * @param  array<int, int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function crear(Colaborador $colaborador, array $documentTypeIds, User $actor): CartaCompromiso
    {
        $documentos = $this->snapshotDocumentos($colaborador, $documentTypeIds);

        return DB::transaction(function () use ($colaborador, $documentos, $actor): CartaCompromiso {
            CartaCompromiso::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('estado', EstadoCartaCompromiso::Vigente->value)
                ->update(['estado' => EstadoCartaCompromiso::Sustituida->value, 'sustituida_en' => now()]);

            $carta = CartaCompromiso::query()->create([
                'colaborador_id' => $colaborador->id,
                'estado' => EstadoCartaCompromiso::Vigente,
                'documentos' => $documentos,
                'fecha_limite' => now()->startOfDay()->addDays($this->diasPlazo())->toDateString(),
                'generada_en' => now(),
                'generada_por' => $actor->id,
            ]);
            $carta->update(['folio' => sprintf('CC-%d-%06d', (int) now()->format('Y'), $carta->id)]);

            $this->auditoria->registrar('carta_compromiso_generada', $carta, $actor, [
                'colaborador_id' => $colaborador->id,
                'folio' => $carta->folio,
                'documentos' => array_column($documentos, 'clave'),
                'fecha_limite' => $carta->fecha_limite->toDateString(),
            ]);

            return $carta;
        });
    }

    /**
     * PDF al expediente + aviso a RH. Ninguno de los dos deshace la carta.
     */
    public function completarGeneracion(CartaCompromiso $carta, User $actor): CartaCompromiso
    {
        $this->asegurarPdf($carta, $actor);
        $this->avisarRh($carta);

        return $carta->refresh();
    }

    /**
     * Vista previa (sin guardar nada) de la carta que se generaría con estos
     * documentos: marca «VISTA PREVIA» y folio provisional.
     *
     * @param  array<int, int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function vistaPrevia(Colaborador $colaborador, array $documentTypeIds): string
    {
        $documentos = $this->snapshotDocumentos($colaborador, $documentTypeIds);
        $datos = $this->datos($colaborador, $documentos, 'VISTA PREVIA', now()->startOfDay()->addDays($this->diasPlazo()), now());

        return $this->renderizar($datos, true);
    }

    /**
     * Bytes del PDF de la carta: el guardado en el expediente o, si no se
     * pudo guardar al generarla, uno nuevo con los mismos datos (y se
     * intenta guardar de nuevo).
     *
     * @throws ValidationException
     */
    public function pdf(CartaCompromiso $carta, ?User $actor = null): string
    {
        if ($carta->path !== null) {
            try {
                if ($this->storage->existe($carta->path)) {
                    return (string) $this->storage->disco()->get($carta->path);
                }
            } catch (Throwable $e) {
                Log::warning('CartaCompromiso: no se pudo leer el PDF guardado; se vuelve a imprimir.', ['carta_id' => $carta->id, 'error' => $e->getMessage()]);
            }
        }

        if ($actor !== null) {
            $guardado = $this->asegurarPdf($carta, $actor);

            if ($guardado !== null) {
                return $guardado;
            }
        }

        return $this->renderizar($this->datosDeCarta($carta), false);
    }

    /**
     * Pone el estado guardado al día: `cumplida` si ya no falta ningún
     * documento comprometido, `vencida` si pasó la fecha límite. Devuelve
     * la carta más reciente (o null si nunca tuvo).
     */
    public function sincronizar(Colaborador $colaborador): ?CartaCompromiso
    {
        $carta = $this->ultima($colaborador);

        if ($carta === null) {
            return $this->generarDiferida($colaborador);
        }

        // Vigente o vencida: una carta vencida también se cumple al subir lo que faltaba.
        if ($carta->estado === EstadoCartaCompromiso::Cumplida) {
            return $carta;
        }

        $pendientes = $this->pendientes->idsPendientes($colaborador);
        $comprometidos = array_map(fn (array $d): int => (int) $d['document_type_id'], $carta->documentos);

        if (array_intersect($comprometidos, $pendientes) === []) {
            $carta->update(['estado' => EstadoCartaCompromiso::Cumplida, 'cumplida_en' => now()]);
            $this->auditoria->registrar('carta_compromiso_cumplida', $carta, null, ['colaborador_id' => $colaborador->id, 'folio' => $carta->folio]);
        } elseif ($carta->estado === EstadoCartaCompromiso::Vigente && $carta->plazoVencido()) {
            $carta->update(['estado' => EstadoCartaCompromiso::Vencida, 'vencida_en' => now()]);
        }

        return $carta->refresh();
    }

    /**
     * Tras subir un documento (app o RH): pone al día la carta sin romper
     * nunca la subida, que ya quedó guardada.
     */
    public function sincronizarSinFallar(Colaborador $colaborador): ?CartaCompromiso
    {
        try {
            return $this->sincronizar($colaborador);
        } catch (Throwable $e) {
            Log::warning('CartaCompromiso: no se pudo sincronizar tras subir un documento.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Carta diferida (más faltantes que el máximo al cerrar el registro):
     * con 1..max faltantes la genera con exactamente esos documentos, a
     * nombre de la propia persona (es su compromiso); con 0 ya no hace
     * falta. Mientras sigan siendo más, no hace nada.
     */
    private function generarDiferida(Colaborador $colaborador): ?CartaCompromiso
    {
        $usuario = $colaborador->user;

        if ($usuario === null || ! $this->esperaCartaAutomatica($colaborador)) {
            return null;
        }

        $pendientes = $this->pendientes->idsPendientes($colaborador);

        if (count($pendientes) > $this->maxDocumentos()) {
            return null;
        }

        if ($pendientes === []) {
            $this->registroApp()->resolverCartaDiferida($usuario, null);

            return null;
        }

        $carta = $this->crear($colaborador, $pendientes, $usuario);
        $this->registroApp()->resolverCartaDiferida($usuario, $carta);
        $this->auditoria->registrar('carta_compromiso_generada_automaticamente', $carta, $usuario, [
            'colaborador_id' => $colaborador->id,
            'folio' => $carta->folio,
        ]);

        return $this->completarGeneracion($carta, $usuario);
    }

    /** Resuelto al vuelo: RegistroAppService ya depende de este servicio. */
    private function registroApp(): RegistroAppService
    {
        return app(RegistroAppService::class);
    }

    /**
     * Contrato de la carta para la app (GET colaborador/carta-compromiso,
     * cierre del registro) y para el expediente en RH.
     *
     * @return array{id: int, folio: string|null, estado: string, estado_etiqueta: string, fecha_limite: string, dias_restantes: int, documentos: list<array{document_type_id: int, nombre: string}>, generada_en: string|null}
     */
    public function aArray(CartaCompromiso $carta): array
    {
        $estado = $this->estadoEfectivo($carta);

        return [
            'id' => $carta->id,
            'folio' => $carta->folio,
            'estado' => $estado->value,
            'estado_etiqueta' => $estado->etiqueta(),
            'fecha_limite' => $carta->fecha_limite->toDateString(),
            'dias_restantes' => $carta->diasRestantes(),
            'documentos' => array_map(fn (array $d): array => [
                'document_type_id' => (int) $d['document_type_id'],
                'nombre' => (string) $d['nombre'],
            ], $carta->documentos),
            'generada_en' => $carta->generada_en->toIso8601String(),
        ];
    }

    /**
     * Tarjeta del expediente web (RH y «Mi expediente»): la carta más
     * reciente con su enlace al PDF, o null si nunca tuvo.
     *
     * @return array<string, mixed>|null
     */
    public function paraExpediente(Colaborador $colaborador): ?array
    {
        $carta = $this->ultima($colaborador);

        if ($carta === null) {
            return null;
        }

        return [
            ...$this->aArray($carta),
            'cumplida_en' => $carta->cumplida_en?->toIso8601String(),
            'pdf_url' => route('rh.expedientes.carta-compromiso.pdf', $colaborador),
        ];
    }

    /**
     * Versión corta para /me y login.
     *
     * @return array{folio: string|null, fecha_limite: string, dias_restantes: int, estado: string}
     */
    public function resumenCorto(CartaCompromiso $carta): array
    {
        return [
            'folio' => $carta->folio,
            'fecha_limite' => $carta->fecha_limite->toDateString(),
            'dias_restantes' => $carta->diasRestantes(),
            'estado' => $this->estadoEfectivo($carta)->value,
        ];
    }

    /** Estado guardado, con `vencida` derivada de la fecha si aún no se sincronizó. */
    public function estadoEfectivo(CartaCompromiso $carta): EstadoCartaCompromiso
    {
        return $carta->estado === EstadoCartaCompromiso::Vigente && $carta->plazoVencido()
            ? EstadoCartaCompromiso::Vencida
            : $carta->estado;
    }

    /**
     * Datos de impresión (solo texto). Nunca imprime al usuario que capturó;
     * las piezas vacías (CURP, número…) se omiten, no se dejan huecos.
     *
     * @param  list<array{document_type_id: int, clave: string, nombre: string}>  $documentos
     * @return array<string, mixed>
     */
    public function datos(Colaborador $colaborador, array $documentos, string $folio, CarbonInterface $fechaLimite, CarbonInterface $fechaEmision): array
    {
        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'puesto']);
        $sucursal = $colaborador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $texto = fn (?string $v): ?string => $v !== null && trim($v) !== '' ? trim($v) : null;

        $lugar = $texto(implode(', ', array_filter([$texto($sucursal?->ciudad), $texto($sucursal?->estado)])))
            ?? $texto($empresa?->ciudad_firma);
        $fecha = $fechaEmision->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY');

        return [
            'folio' => $folio,
            'lugar_fecha' => $lugar !== null ? sprintf('%s, a %s', $lugar, $fecha) : $fecha,
            'nombre' => mb_strtoupper($colaborador->nombreCompleto(), 'UTF-8'),
            'tratamiento' => match ($colaborador->genero) {
                Genero::Femenino => 'colaboradora',
                Genero::Masculino => 'colaborador',
                default => 'colaborador(a)',
            },
            'empresa' => $this->firmaPatron->razonSocialDeEmpresa($empresa),
            'puesto' => $texto($colaborador->puesto?->nombre),
            'sucursal' => $texto($sucursal?->nombre),
            'numero_empleado' => $texto($colaborador->numero_empleado),
            'curp' => $texto($colaborador->curp) !== null ? mb_strtoupper((string) $colaborador->curp, 'UTF-8') : null,
            'fecha_limite' => $fechaLimite->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY'),
            'dias_plazo' => $this->diasPlazo(),
            'documentos' => array_map(fn (array $d): string => (string) $d['nombre'], $documentos),
            'logo' => $this->logos->dataUri($empresa?->logo_path),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function html(array $datos, MotorPdf $motor = MotorPdf::Browsershot, bool $borrador = false): string
    {
        return view('pdf.expedientes.carta-compromiso', ['d' => $datos, 'motor' => $motor->value, 'borrador' => $borrador])->render();
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function renderizar(array $datos, bool $borrador): string
    {
        try {
            $render = $this->pdf->renderizarCon(
                MotorPdf::porDefecto(),
                fn (MotorPdf $m): string => $this->html($datos, $m, $borrador),
                fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
                    tamano: 'letter',
                    numerarPaginas: true,
                    textoPie: $borrador
                        ? 'Vista previa · sin validez'
                        : sprintf('Carta compromiso %s · Documento generado electrónicamente por MR. LANA PEOPLE', (string) $datos['folio']),
                    colorPie: (string) config('pdf_identidad.texto_tenue', '#8C9A94'),
                    margenInferiorMm: 22.0,
                ),
            );
        } catch (PdfRendererException $e) {
            Log::warning('CartaCompromiso: no se pudo imprimir el PDF.', ['folio' => $datos['folio'] ?? null, 'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);

            throw ValidationException::withMessages(['carta_compromiso' => sprintf('%s Intenta de nuevo en unos minutos; si continúa, avisa a Recursos Humanos.', $e->getMessage())]);
        }

        return $render['pdf'];
    }

    /**
     * Imprime y guarda el PDF en el expediente si todavía no está. Nunca
     * lanza: un fallo de Chrome o del NAS queda en el log.
     */
    private function asegurarPdf(CartaCompromiso $carta, User $actor): ?string
    {
        if ($carta->path !== null) {
            return null;
        }

        $colaborador = $carta->colaborador;

        if ($colaborador === null) {
            return null;
        }

        try {
            $pdf = $this->renderizar($this->datosDeCarta($carta), false);
            $documento = app(MotorDocumentalService::class)->registrarPdf($colaborador, $pdf, sprintf('Carta compromiso de documentos %s', (string) $carta->folio), $actor, [
                'clave' => self::CLAVE,
                'categoria' => CategoriaDocumento::Personales,
                'documentable' => $carta,
                'proceso' => self::PROCESO,
                // Se imprime para la firma autógrafa y la huella del colaborador.
                'requiere_impresion' => true,
                'requiere_firma_fisica' => true,
                'requiere_huella' => true,
                'nombre_archivo' => sprintf('Carta compromiso %s.pdf', (string) $carta->folio),
            ]);

            $carta->update([
                'generated_document_id' => $documento->id,
                'disk' => $documento->disk,
                'path' => $documento->path,
            ]);

            return $pdf;
        } catch (Throwable $e) {
            Log::warning('CartaCompromiso: no se pudo guardar el PDF en el expediente.', ['carta_id' => $carta->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function avisarRh(CartaCompromiso $carta): void
    {
        try {
            $colaborador = $carta->colaborador;
            $usuario = $colaborador?->user;

            if ($colaborador === null || $usuario === null) {
                return;
            }

            $this->eventos->crearPendiente(
                $this->responsables->paraColaborador($usuario, 'rh.documentos.ver'),
                new Aviso(
                    'carta_compromiso_generada',
                    'rh_incorporacion',
                    'Carta compromiso de documentos',
                    sprintf(
                        '%s se comprometió a entregar %d documento(s) a más tardar el %s (carta %s).',
                        $colaborador->nombreCompleto(),
                        count($carta->documentos),
                        $carta->fecha_limite->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM'),
                        (string) $carta->folio,
                    ),
                    $colaborador,
                    'revisar_carta_compromiso',
                    'media',
                    ['carta_compromiso_id' => $carta->id],
                    sprintf('carta-%d', $carta->id),
                ),
            );
        } catch (Throwable $e) {
            Log::warning('CartaCompromiso: no se pudo avisar a RH.', ['carta_id' => $carta->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDeCarta(CartaCompromiso $carta): array
    {
        $colaborador = $carta->colaborador;

        abort_if($colaborador === null, 404);

        return $this->datos($colaborador, $carta->documentos, (string) $carta->folio, $carta->fecha_limite, $carta->generada_en);
    }

    /**
     * Valida que cada documento esté entre los pendientes de la persona y
     * congela id + clave + nombre (en el orden del catálogo).
     *
     * @param  array<int, int>  $documentTypeIds
     * @return list<array{document_type_id: int, clave: string, nombre: string}>
     *
     * @throws ValidationException
     */
    private function snapshotDocumentos(Colaborador $colaborador, array $documentTypeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $documentTypeIds)));

        if ($ids === []) {
            throw ValidationException::withMessages(['documentos' => 'Elige al menos un documento pendiente.']);
        }

        $maximo = $this->maxDocumentos();

        if (count($ids) > $maximo) {
            throw ValidationException::withMessages(['documentos' => sprintf(
                'Una carta compromiso puede cubrir como máximo %d %s y elegiste %d. Sube los demás para poder generarla.',
                $maximo,
                $maximo === 1 ? 'documento pendiente' : 'documentos pendientes',
                count($ids),
            )]);
        }

        $pendientes = $this->pendientes->idsPendientes($colaborador);
        $ajenos = array_diff($ids, $pendientes);

        if ($ajenos !== []) {
            $nombres = DocumentType::query()->whereIn('id', $ajenos)->pluck('nombre')->all();

            throw ValidationException::withMessages(['documentos' => sprintf(
                'Solo puedes comprometer documentos que te faltan por subir%s.',
                $nombres !== [] ? sprintf(' (no aplica: %s)', implode(', ', $nombres)) : '',
            )]);
        }

        $documentos = [];

        foreach ($this->pendientes->tiposExigibles() as $tipo) {
            if (in_array($tipo->id, $ids, true)) {
                $documentos[] = ['document_type_id' => $tipo->id, 'clave' => $tipo->clave, 'nombre' => $tipo->nombre];
            }
        }

        return $documentos;
    }
}
