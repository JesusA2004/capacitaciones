<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\DocumentoSalida;
use App\Enums\EntregaAvisoRescision;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Models\BajaAvisoRescision;
use App\Models\BajaDocumento;
use App\Models\BajaDocumentoEdicion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\DocumentoRequeridoBaja;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Documentos\Versiones\AlEmitirDocumento;
use App\Services\Documentos\Versiones\CampoEditable;
use App\Services\Documentos\Versiones\DatosVolatiles;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\Documentos\Versiones\Fuentes\FuenteMaestro;
use App\Services\Documentos\Versiones\Fuentes\FuenteSalidaAdministrativa;
use App\Services\Documentos\Versiones\VersionadoDocumentoService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * «Generar documento de salida» de una baja (PDF Etapa 1, Pantalla 4). Según la
 * forma: carta de renuncia (RV, documento maestro), convenio de terminación
 * (NR), aviso de rescisión (RE) o constancia (OT), estos tres con el diseño que
 * RH administra en Documentos maestros → Documentos administrativos. No es otra
 * plataforma de plantillas: el PDF pasa por MotorDocumentalService (expediente
 * en NAS, GeneratedDocument, flujo físico) y cada versión anterior se conserva
 * cancelada con su motivo. El firmado se adjunta al espacio requerido de la
 * baja, sin reemplazar nada.
 */
class BajaDocumentoSalidaService
{
    public const EVIDENCIA = 'evidencia_rescision';

    public const FIRMADO = 'firmado_salida';

    private const MINIMO_MOTIVO_NUEVA_VERSION = 5;

    private const MAXIMO_CAMPO = 5000;

    /** @var array<string, string> */
    private const ETIQUETAS = [
        'lugar_fecha' => 'Lugar y fecha',
        'cuerpo' => 'Texto principal',
        'fundamento' => 'Fundamento legal',
        'texto_adicional' => 'Texto adicional (opcional)',
    ];

    public function __construct(
        private readonly BajaDocumentosService $documentos,
        private readonly BajaRegistroService $registro,
        private readonly DocumentoProcesoService $proceso,
        private readonly DocumentoAdministrativoService $administrativos,
        private readonly DatosDocumentoAdministrativo $datos,
        private readonly FlujoDocumentalService $flujo,
        private readonly CalendarioLaboralService $calendario,
        private readonly VersionadoDocumentoService $versionado,
    ) {}

    public function documentoDe(Baja $baja): ?DocumentoSalida
    {
        if ($baja->forma_salida === null) {
            return null;
        }

        $deCausal = DocumentoSalida::tryFrom((string) $baja->causal?->documento_salida);

        return $deCausal !== null && in_array($deCausal, DocumentoSalida::opcionesDe($baja->forma_salida), true)
            ? $deCausal
            : DocumentoSalida::deForma($baja->forma_salida);
    }

    /**
     * Lo que muestra el panel «Generar documento de salida».
     *
     * @return array<string, mixed>
     */
    public function panel(Baja $baja, User $usuario): array
    {
        $baja->loadMissing(['causal', 'aviso.validadoPor', 'documentos.cargadoPor', 'cierre', 'colaborador']);
        $documento = $this->documentoDe($baja);
        $vigente = $documento !== null && $baja->cierre !== null ? $this->proceso->documentoVigente($baja->cierre, $documento->value) : null;
        $aviso = $baja->aviso;

        return [
            'baja_id' => $baja->id,
            'folio' => $baja->folio,
            'colaborador' => $baja->nombre_colaborador,
            'forma' => $baja->forma_salida?->value,
            'causal' => $baja->causal?->etiquetaConClave(),
            'fundamento_catalogo' => $baja->causal?->fundamento_legal,
            'documento' => $documento !== null ? ['clave' => $documento->value, 'etiqueta' => $documento->etiqueta()] : null,
            'abierta' => $baja->estatus->esAbierta(),
            'faltantes' => $this->faltantes($baja),
            'generado' => $vigente !== null ? [
                'id' => $vigente->id,
                'version' => $this->proceso->numeroVersion($vigente),
                'fecha' => $vigente->created_at?->toDateTimeString(),
                'firmado' => $vigente->estado_flujo?->firmaFisicaRegistrada() ?? false,
                'requiere_huella' => (bool) $vigente->requiere_huella,
                'desactualizado' => ($aviso !== null && $vigente->created_at !== null && $aviso->updated_at !== null && $aviso->updated_at->gt($vigente->created_at)) || $this->fuenteCambio($baja, $documento, $vigente, $usuario),
            ] : null,
            'ediciones' => $documento !== null ? $this->ediciones($baja, $documento) : [],
            'firmados' => array_values($baja->documentos->where('clasificacion', self::FIRMADO)->map(fn (BajaDocumento $d): array => $this->documentos->archivoArray($d))->all()),
            'evidencias' => array_values($baja->documentos->where('clasificacion', self::EVIDENCIA)->map(fn (BajaDocumento $d): array => $this->documentos->archivoArray($d))->all()),
            'aviso' => $aviso !== null ? [
                'fundamento' => $aviso->fundamento,
                'que_ocurrio' => $aviso->que_ocurrio,
                'hechos_en' => $aviso->hechos_en->format('Y-m-d\TH:i'),
                'lugar' => $aviso->lugar,
                'constataron' => $aviso->constataron,
                'relato' => $aviso->relato,
                'testigos' => $aviso->testigos,
                'fecha_aviso' => $aviso->fecha_aviso->toDateString(),
                'metodo_entrega' => $aviso->metodo_entrega->value,
                'fecha_entrega' => $aviso->fecha_entrega->toDateString(),
            ] : null,
            'plazo' => $this->plazoNegativa($baja),
            'testigos_minimos' => $this->testigosMinimos(),
            'puede_validar_plazo' => $usuario->can(BajaService::PERMISO_GESTIONAR) || $usuario->can(BajaService::PERMISO_JURIDICO),
        ];
    }

    /**
     * Datos que faltan para generar el documento: nunca se llena con supuestos.
     *
     * @return list<string>
     */
    public function faltantes(Baja $baja): array
    {
        $baja->loadMissing(['causal', 'aviso', 'documentos', 'cierre']);
        $faltan = [];
        $datos = [
            'la forma de salida' => $baja->forma_salida,
            'la causal' => $baja->causal,
            'el último día laborado' => $baja->fecha_baja,
            'la fecha de ingreso' => $baja->fecha_ingreso,
            'el puesto' => $baja->puesto_id,
            'la sucursal' => $baja->sucursal_id,
            'el cierre laboral de la baja' => $baja->cierre,
        ];

        foreach ($datos as $etiqueta => $valor) {
            if ($valor === null) {
                $faltan[] = $etiqueta;
            }
        }

        if ($baja->forma_salida === FormaSalida::Rescision) {
            if ($baja->aviso === null) {
                $faltan[] = 'los hechos y la entrega del aviso';
            }

            if ($baja->documentos->where('clasificacion', self::EVIDENCIA)->isEmpty()) {
                $faltan[] = 'al menos una evidencia';
            }
        }

        return $faltan;
    }

    /**
     * Captura o corrige el aviso de rescisión. Sincroniza la entrega con la
     * baja (la usan sus reglas y alertas) y deja cada cambio en la bitácora.
     *
     * @param  array<string, mixed>  $datos  Validados por GuardarAvisoRescisionRequest.
     */
    public function guardarAviso(Baja $baja, array $datos, User $actor): BajaAvisoRescision
    {
        $this->exigirAbierta($baja);

        if ($baja->forma_salida !== FormaSalida::Rescision) {
            throw ValidationException::withMessages(['aviso' => 'El aviso de rescisión solo aplica a una baja por rescisión.']);
        }

        $testigos = array_values(array_filter(array_map(fn (array $t): array => [
            'nombre' => trim((string) ($t['nombre'] ?? '')),
            'cargo' => trim((string) ($t['cargo'] ?? '')),
        ], (array) $datos['testigos']), fn (array $t): bool => $t['nombre'] !== ''));

        if (count($testigos) < $this->testigosMinimos()) {
            throw ValidationException::withMessages(['testigos' => sprintf('Registra al menos %d testigos con su nombre.', $this->testigosMinimos())]);
        }

        $metodo = EntregaAvisoRescision::from((string) $datos['metodo_entrega']);
        $fechaAviso = Carbon::parse((string) $datos['fecha_aviso'])->startOfDay();
        $fechaEntrega = Carbon::parse((string) $datos['fecha_entrega'])->startOfDay();

        if ($fechaEntrega->lt($fechaAviso)) {
            throw ValidationException::withMessages(['fecha_entrega' => 'La fecha de entrega o negativa no puede ser anterior a la fecha del aviso.']);
        }

        return DB::transaction(function () use ($baja, $datos, $actor, $testigos, $metodo, $fechaAviso, $fechaEntrega): BajaAvisoRescision {
            $aviso = BajaAvisoRescision::query()->lockForUpdate()->where('baja_id', $baja->id)->first();
            $anterior = $aviso?->only(['fundamento', 'que_ocurrio', 'lugar', 'constataron', 'relato']);
            $cambioEntrega = $aviso === null || $aviso->metodo_entrega !== $metodo || ! $aviso->fecha_aviso->equalTo($fechaAviso);

            $aviso = BajaAvisoRescision::query()->updateOrCreate(['baja_id' => $baja->id], [
                'fundamento' => trim((string) $datos['fundamento']),
                'que_ocurrio' => trim((string) $datos['que_ocurrio']),
                'hechos_en' => Carbon::parse((string) $datos['hechos_en']),
                'lugar' => trim((string) $datos['lugar']),
                'constataron' => trim((string) $datos['constataron']),
                'relato' => trim((string) $datos['relato']),
                'testigos' => $testigos,
                'fecha_aviso' => $fechaAviso,
                'metodo_entrega' => $metodo,
                'fecha_entrega' => $fechaEntrega,
                // Si cambia la fecha o el método, la validación anterior del plazo ya no aplica.
                ...($cambioEntrega ? ['plazo_validado' => false, 'plazo_validado_por' => null, 'plazo_validado_en' => null] : []),
                'capturado_por' => $aviso->capturado_por ?? $actor->id,
                'actualizado_por' => $actor->id,
            ]);

            $baja->update([
                'entrega_aviso_rescision' => $metodo,
                'fecha_entrega_aviso' => $fechaEntrega,
                'fecha_conocimiento_falta' => $baja->fecha_conocimiento_falta ?? $aviso->hechos_en->copy()->startOfDay(),
            ]);

            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::AvisoRescision, 'aviso', $anterior !== null ? sprintf('%s · %s', $anterior['fundamento'], $anterior['lugar']) : null, sprintf('%s · %s · %s (%s)', $aviso->fundamento, $aviso->lugar, $metodo->etiqueta(), $fechaEntrega->format('d/m/Y')));

            return $aviso;
        });
    }

    /**
     * Plazo que se muestra cuando el colaborador se negó a recibir el aviso:
     * días hábiles sin festivos desde la fecha del aviso. Es apoyo
     * administrativo, nunca una garantía legal: lo valida el área competente.
     *
     * @return array{dias: int, fecha_limite: string, mensaje: string, nota: string, validado: bool, validado_por: string|null, validado_en: string|null}|null
     */
    public function plazoNegativa(Baja $baja): ?array
    {
        $baja->loadMissing('aviso.validadoPor');
        $aviso = $baja->aviso;

        if ($aviso === null || $aviso->metodo_entrega !== EntregaAvisoRescision::SeNego) {
            return null;
        }

        $dias = (int) config('bajas.reglas.dias_habiles_aviso_tribunal', 5);
        $limite = $this->calendario->sumarDiasHabilesSinFestivos($aviso->fecha_aviso->copy(), $dias);
        $limite->setLocale('es');

        return [
            'dias' => $dias,
            'fecha_limite' => $limite->toDateString(),
            'mensaje' => sprintf('Plazo para depositar el aviso ante el Tribunal Laboral: %d días hábiles. Fecha límite calculada: %s.', $dias, $limite->isoFormat('dddd D [de] MMMM [de] YYYY')),
            'nota' => 'Apoyo administrativo con días hábiles y festivos configurados; el área competente valida el plazo aplicable.',
            'validado' => $aviso->plazo_validado,
            'validado_por' => $aviso->validadoPor !== null ? trim(sprintf('%s %s', $aviso->validadoPor->name, $aviso->validadoPor->apellidos)) : null,
            'validado_en' => $aviso->plazo_validado_en?->toDateTimeString(),
        ];
    }

    public function validarPlazo(Baja $baja, User $actor): void
    {
        $baja->loadMissing('aviso');
        $plazo = $this->plazoNegativa($baja);
        $aviso = $baja->aviso;

        if ($plazo === null || $aviso === null) {
            throw ValidationException::withMessages(['plazo' => 'Solo se valida el plazo cuando el colaborador se negó a recibir el aviso.']);
        }

        DB::transaction(function () use ($baja, $actor, $plazo, $aviso): void {
            $aviso->update(['plazo_validado' => true, 'plazo_validado_por' => $actor->id, 'plazo_validado_en' => now()]);
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::ValidoPlazo, 'plazo', null, $plazo['fecha_limite']);
        });
    }

    /**
     * Campos que RH puede corregir, con el valor vigente y el original del
     * expediente. En la carta de renuncia (documento maestro) son sus datos
     * variables, sin los de la empresa ni su representante legal: el texto
     * base del maestro lo administra Jurídico.
     *
     * @return list<array{clave: string, etiqueta: string, tipo: string, valor: string, original: string, obsoleta: string|null}>
     */
    public function camposEditables(Baja $baja, User $actor): array
    {
        return array_map(fn (CampoEditable $c): array => $c->aArray(), $this->camposSalida($baja, $actor));
    }

    /**
     * @return list<CampoEditable>
     */
    public function camposSalida(Baja $baja, User $actor): array
    {
        [$documento, $cierre, $colaborador] = $this->preparar($baja);
        $originales = $this->originales($baja, $documento, $cierre, $colaborador, $actor);
        $vigentes = $this->camposVigentes($baja, $documento, $originales);
        $obsoletas = $this->camposObsoletos($baja, $documento, $originales);
        $campos = [];

        foreach ($originales as $clave => $original) {
            $campos[] = new CampoEditable(
                $clave,
                $this->etiquetaCampo($clave),
                in_array($clave, ['cuerpo', 'texto_adicional'], true) || mb_strlen($original) > 80 ? 'area' : 'texto',
                $vigentes[$clave] ?? $original,
                $original,
                $obsoletas[$clave] ?? null,
            );
        }

        return $campos;
    }

    /**
     * Vista previa real: mismo motor y datos que el definitivo, sin guardar
     * nada. Con $campos muestra lo que RH está corrigiendo; sin ellos, la
     * última corrección guardada.
     *
     * @param  array<string, mixed>|null  $campos
     */
    public function vistaPrevia(Baja $baja, User $actor, ?array $campos = null): string
    {
        [$documento, $cierre, $colaborador] = $this->preparar($baja);
        $originales = $this->originales($baja, $documento, $cierre, $colaborador, $actor);
        $corregidos = $campos !== null ? $this->limpiarCampos($originales, $campos) : $this->camposVigentes($baja, $documento, $originales);

        return $documento->familia() === null
            ? $this->proceso->vistaPrevia('baja', $cierre, $documento->value, $actor, correcciones: FuenteMaestro::conVariantes($corregidos))['pdf']
            : $this->fuente($baja, $documento, $cierre, $colaborador, $actor)->vistaPrevia($corregidos, $actor);
    }

    /**
     * Genera el PDF para firma por VersionadoDocumentoService: sin cambios devuelve
     * la vigente; una versión nueva exige motivo y conserva la anterior (cancelada,
     * en el NAS y en el historial); un original firmado nunca se sustituye desde
     * aquí. Con $campos guarda además la corrección. Edición y bitácora se escriben
     * en la misma transacción que la versión: si fallan, no queda a medias.
     *
     * Con $rectificar emite la rectificación de un firmado, también con la baja concluida
     * (el original no se toca) y con la misma bitácora: lo corregido no se pierde después.
     *
     * @param  array<string, mixed>|null  $campos
     */
    public function generar(Baja $baja, User $actor, ?string $motivo = null, ?array $campos = null, bool $rectificar = false): GeneratedDocument
    {
        if (! $rectificar) {
            $this->exigirAbierta($baja);
        }

        [$documento, $cierre, $colaborador] = $this->preparar($baja);
        $anterior = $this->proceso->documentoVigente($cierre, $documento->value);
        $motivo = trim((string) $motivo);
        $originales = $this->originales($baja, $documento, $cierre, $colaborador, $actor);
        $vigentes = $this->camposVigentes($baja, $documento, $originales);
        $corregidos = $campos !== null ? $this->limpiarCampos($originales, $campos) : $vigentes;

        if ($corregidos != $vigentes && mb_strlen($motivo) < self::MINIMO_MOTIVO_NUEVA_VERSION) {
            throw ValidationException::withMessages(['motivo' => 'Explica el motivo de la corrección: queda en la bitácora.']);
        }

        if (! $rectificar && ($anterior?->estado_flujo?->firmaFisicaRegistrada() ?? false)) {
            throw ValidationException::withMessages(['documento' => 'Este documento ya está firmado: el original se conserva y no se sustituye desde aquí.']);
        }

        $alEmitir = new class($this->registro, $this->proceso, $baja, $documento, $vigentes, $corregidos) implements AlEmitirDocumento
        {
            public function __construct(
                private readonly BajaRegistroService $registro,
                private readonly DocumentoProcesoService $proceso,
                private readonly Baja $baja,
                private readonly DocumentoSalida $salida,
                /** @var array<string, string> */
                private readonly array $vigentes,
                /** @var array<string, string> */
                private readonly array $corregidos,
            ) {}

            public function __invoke(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
            {
                if ($this->corregidos != $this->vigentes) {
                    BajaDocumentoEdicion::query()->create([
                        'baja_id' => $this->baja->id,
                        'clave' => $this->salida->value,
                        'campos' => $this->corregidos,
                        'campos_anteriores' => $this->vigentes,
                        'motivo' => mb_substr((string) $motivo, 0, 500),
                        'user_id' => $actor->id,
                        'generated_document_id' => $documento->id,
                    ]);
                    $this->registro->bitacora($this->baja, $actor, AccionBitacoraBaja::CorrigioDocumento, 'documento', null, implode(', ', array_keys($this->corregidos)) ?: 'Valores originales', $motivo);
                }

                $this->registro->bitacora($this->baja, $actor, AccionBitacoraBaja::DocumentoSalida, 'documento', $anterior !== null ? sprintf('Versión %d', $this->proceso->numeroVersion($anterior)) : null, sprintf('%s · versión %d', $this->salida->etiqueta(), $this->proceso->numeroVersion($documento)), $anterior !== null ? $motivo : null);
            }
        };

        if ($documento->familia() === null) {
            return $this->proceso->generar('baja', $cierre, $documento->value, $actor, regenerar: $anterior !== null && ! $rectificar, revision: $rectificar, motivoRevision: $motivo !== '' ? $motivo : null, correcciones: $corregidos, alEmitir: $alEmitir);
        }

        return $this->versionado->emitir($this->fuente($baja, $documento, $cierre, $colaborador, $actor, $alEmitir), $corregidos, $motivo !== '' ? $motivo : null, $actor, $rectificar)->documento;
    }

    public function descargar(Baja $baja): StreamedResponse
    {
        $baja->loadMissing('cierre');
        $documento = $this->documentoDe($baja);
        $vigente = $documento !== null && $baja->cierre !== null ? $this->proceso->documentoVigente($baja->cierre, $documento->value) : null;
        abort_if($vigente === null, 404, 'Genera el documento antes de descargarlo.');
        abort_unless(Storage::disk($vigente->disk)->exists($vigente->path), 404, 'El archivo ya no está en el NAS.');
        $nombre = str_replace('"', '', $vigente->original_name);

        return Storage::disk($vigente->disk)->response($vigente->path, $nombre, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nombre),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Carga el documento físicamente firmado al espacio requerido de la baja:
     * pasa de «Pendiente» a «Adjunto» y el generado queda como firmado. Es
     * un archivo más: no reemplaza al generado ni a cargas anteriores.
     */
    public function subirFirmado(Baja $baja, UploadedFile $archivo, User $actor, bool $huellaRegistrada = false): BajaDocumento
    {
        $this->exigirAbierta($baja);
        [$documento, $cierre] = $this->preparar($baja);
        $vigente = $this->proceso->documentoVigente($cierre, $documento->value);

        if ($vigente === null) {
            throw ValidationException::withMessages(['archivo' => 'Primero genera el documento para firma.']);
        }

        // Sin esta confirmación el flujo no avanzaría y el original firmado quedaría sustituible.
        if ($vigente->requiere_huella && ! $huellaRegistrada) {
            throw ValidationException::withMessages(['huella_registrada' => 'Este documento requiere huella: confirma que se recabó antes de subir el firmado.']);
        }

        // Nadie firma una versión con datos viejos: primero se regenera.
        if ($this->fuenteCambio($baja, $documento, $vigente, $actor)) {
            throw ValidationException::withMessages(['archivo' => 'El documento ya no corresponde a los datos actuales de la baja. Regenéralo y firma la versión nueva.']);
        }

        // El firmado cumple el espacio de la forma, aunque la causal genere otro documento.
        $espacio = DocumentoRequeridoBaja::query()->where('clave', DocumentoSalida::deForma($baja->forma_salida ?? FormaSalida::OtraTerminacion)->espacioRequerido())->first();

        // Adjunto, firma del generado y bitácora van juntos: si el flujo no admite el
        // paso, no queda un firmado adjunto con el generado todavía sustituible.
        return DB::transaction(function () use ($baja, $espacio, $archivo, $actor, $vigente, $documento, $huellaRegistrada): BajaDocumento {
            if (in_array($vigente->estado_flujo, [EstadoFlujoDocumento::Generado, EstadoFlujoDocumento::PendienteImpresion, EstadoFlujoDocumento::PendienteFirmaFisica, EstadoFlujoDocumento::Impreso], true)) {
                $paraFirmar = in_array($vigente->estado_flujo, [EstadoFlujoDocumento::Generado, EstadoFlujoDocumento::PendienteImpresion], true) ? $this->flujo->marcarImpreso($vigente, $actor) : $vigente;
                $this->flujo->registrarFirmaFisica($paraFirmar, $actor, ['huella_registrada' => $huellaRegistrada, 'observaciones' => sprintf('Firmado cargado a la baja %s.', $baja->folio)]);
            }

            $cargado = $this->documentos->adjuntar($baja, $espacio, $archivo, $actor, self::FIRMADO, $vigente->id);
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::FirmadoSalida, 'documento', null, sprintf('%s (%s)', $documento->etiqueta(), $cargado->nombre_original));

            return $cargado;
        });
    }

    public function subirEvidencia(Baja $baja, UploadedFile $archivo, User $actor): BajaDocumento
    {
        $this->exigirAbierta($baja);

        if ($baja->forma_salida !== FormaSalida::Rescision) {
            throw ValidationException::withMessages(['archivo' => 'Las evidencias del aviso solo aplican a una rescisión.']);
        }

        return $this->documentos->adjuntar($baja, null, $archivo, $actor, self::EVIDENCIA);
    }

    private function etiquetaCampo(string $clave): string
    {
        return self::ETIQUETAS[$clave] ?? ucfirst(str_replace('_', ' ', str_replace('_mayusculas', '', $clave)));
    }

    /**
     * Historial de correcciones del documento (más reciente primero).
     *
     * @return list<array{id: int, motivo: string, usuario: string|null, fecha: string|null, campos: list<string>}>
     */
    private function ediciones(Baja $baja, DocumentoSalida $documento): array
    {
        return array_values(BajaDocumentoEdicion::query()->with('usuario:id,name,apellidos')->where('baja_id', $baja->id)->where('clave', $documento->value)->latest('id')->get()
            ->map(fn (BajaDocumentoEdicion $e): array => [
                'id' => $e->id,
                'motivo' => $e->motivo,
                'usuario' => $e->usuario !== null ? trim(sprintf('%s %s', $e->usuario->name, $e->usuario->apellidos)) : null,
                'fecha' => $e->created_at?->toDateTimeString(),
                'campos' => array_map(fn (string $c): string => $this->etiquetaCampo($c), array_keys($e->campos)),
            ])->all());
    }

    /**
     * Última corrección que sigue siendo válida. Si la fuente cambió después de
     * hacerla (otra causal, otra fecha), no se reaplica: RH decide en el editor.
     *
     * @param  array<string, string>  $originales
     * @return array<string, string>
     */
    private function camposVigentes(Baja $baja, DocumentoSalida $documento, array $originales): array
    {
        return array_diff_key($this->ultimaEdicion($baja, $documento)['campos'], $this->camposObsoletos($baja, $documento, $originales));
    }

    /**
     * @param  array<string, string>  $originales
     * @return array<string, string> Campo => corrección que ya no aplica.
     */
    private function camposObsoletos(Baja $baja, DocumentoSalida $documento, array $originales): array
    {
        $ultima = $this->ultimaEdicion($baja, $documento);
        $obsoletos = [];

        foreach ($ultima['campos'] as $clave => $valor) {
            $fuente = $ultima['detalle'][$clave]['original_fuente'] ?? null;

            if (! DatosVolatiles::esVolatil($clave) && is_string($fuente) && trim($fuente) !== trim($originales[$clave] ?? $fuente)) {
                $obsoletos[$clave] = $valor;
            }
        }

        return $obsoletos;
    }

    /**
     * @return array{campos: array<string, string>, detalle: array<string, array<string, mixed>>}
     */
    private function ultimaEdicion(Baja $baja, DocumentoSalida $documento): array
    {
        $ultima = BajaDocumentoEdicion::query()->where('baja_id', $baja->id)->where('clave', $documento->value)->latest('id')->first();

        if ($ultima === null) {
            return ['campos' => [], 'detalle' => []];
        }

        $generado = $ultima->generated_document_id !== null ? GeneratedDocument::query()->whereKey($ultima->generated_document_id)->first() : null;

        return ['campos' => $ultima->campos, 'detalle' => $generado->correcciones ?? []];
    }

    /**
     * ¿La versión ya no corresponde a los datos de la baja? Solo en administrativos
     * y cartas con huella; una versión anterior a la huella no se puede comparar.
     */
    private function fuenteCambio(Baja $baja, DocumentoSalida $documento, GeneratedDocument $vigente, User $actor): bool
    {
        if ($vigente->huella_datos === null || ! $baja->cierre instanceof CierreLaboral || $baja->colaborador === null) {
            return false;
        }

        try {
            $fuente = $documento->familia() === null
                ? new FuenteMaestro($this->proceso, 'baja', $baja->cierre, $documento->value)
                : $this->fuente($baja, $documento, $baja->cierre, $baja->colaborador, $actor);

            return $this->versionado->estaDesactualizado($fuente, $vigente, $actor);
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface) {
            return false;
        }
    }

    public function fuenteDe(Baja $baja, User $actor, bool $soloConsulta = false): FuenteDocumento
    {
        [$documento, $cierre, $colaborador] = $this->preparar($baja, $soloConsulta);

        if ($documento->familia() !== null) {
            return $this->fuente($baja, $documento, $cierre, $colaborador, $actor);
        }

        return new FuenteMaestro($this->proceso, 'baja', $cierre, $documento->value);
    }

    private function fuente(Baja $baja, DocumentoSalida $documento, CierreLaboral $cierre, Colaborador $colaborador, User $actor, ?AlEmitirDocumento $alEmitir = null): FuenteSalidaAdministrativa
    {
        $familia = $documento->familia() ?? throw ValidationException::withMessages(['documento' => 'Este documento no es administrativo.']);

        return new FuenteSalidaAdministrativa($this->administrativos, $this->datos, $baja, $documento, $familia, $cierre, $colaborador, $this->camposSalida($baja, $actor), $alEmitir);
    }

    /**
     * @return array<string, string>
     */
    private function originales(Baja $baja, DocumentoSalida $documento, CierreLaboral $cierre, Colaborador $colaborador, User $actor): array
    {
        if ($documento->familia() === null) {
            $valores = $this->proceso->camposDelDocumento('baja', $cierre, $documento->value, $actor);

            return array_filter($valores, fn (string $clave): bool => preg_match(FuenteMaestro::CAMPOS_NO_EDITABLES, $clave) !== 1, ARRAY_FILTER_USE_KEY);
        }

        $datos = $this->datos->salida($baja, $colaborador);

        return [
            'lugar_fecha' => (string) $datos['lugar_fecha'],
            'cuerpo' => (string) $datos['cuerpo'],
            ...($documento === DocumentoSalida::AvisoRescision ? ['fundamento' => (string) $datos['fundamento']] : []),
            'texto_adicional' => '',
        ];
    }

    /**
     * Solo campos permitidos y distintos del original: lo no corregido sigue
     * saliendo del expediente. Un campo vacío regresa a su valor original.
     *
     * @param  array<string, string>  $originales
     * @param  array<string, mixed>  $campos
     * @return array<string, string>
     */
    private function limpiarCampos(array $originales, array $campos): array
    {
        $limpios = [];

        foreach ($originales as $clave => $original) {
            $valor = is_string($campos[$clave] ?? null) ? trim($campos[$clave]) : '';

            if ($valor !== '' && $valor !== trim($original)) {
                $limpios[$clave] = mb_substr($valor, 0, self::MAXIMO_CAMPO);
            }
        }

        return $limpios;
    }

    /**
     * @return array{0: DocumentoSalida, 1: CierreLaboral, 2: Colaborador}
     */
    private function preparar(Baja $baja, bool $soloConsulta = false): array
    {
        $baja->unsetRelation('documentos');
        // Lo que falte impide emitir, no consultar lo ya emitido.
        $faltantes = $soloConsulta ? [] : $this->faltantes($baja);

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['documento' => sprintf('Falta capturar: %s.', implode(', ', $faltantes))]);
        }

        $documento = $this->documentoDe($baja);
        $cierre = $baja->cierre;
        $colaborador = $baja->colaborador;

        if ($documento === null || $cierre === null || $colaborador === null) {
            throw ValidationException::withMessages(['documento' => 'La baja no tiene colaborador o cierre laboral ligado.']);
        }

        return [$documento, $cierre, $colaborador];
    }

    private function exigirAbierta(Baja $baja): void
    {
        if (! $baja->estatus->esAbierta()) {
            throw ValidationException::withMessages(['estatus' => sprintf('La baja está «%s»: RH puede reabrirla con motivo si hace falta.', $baja->estatus->etiqueta())]);
        }
    }

    private function testigosMinimos(): int
    {
        return (int) config('bajas.reglas.testigos_minimos_aviso', 2);
    }
}
