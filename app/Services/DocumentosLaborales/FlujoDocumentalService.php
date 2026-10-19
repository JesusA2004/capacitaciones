<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoDocumentoGenerado;
use App\Enums\EstadoFlujoDocumento as E;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\DocumentoEvento;
use App\Models\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\SeguimientoDocumentoFisico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flujo documental de un documento laboral emitido (GeneratedDocument):
 *
 *   generado → pendiente_firma_colaborador → firmado_digitalmente      (aceptación digital)
 *            → pendiente_impresion → impreso → pendiente_firma_fisica  (original físico)
 *            → firmado_fisicamente   (FINAL: el original firmado se resguarda en papel)
 *
 * Los pasos que aplican dependen de las banderas requiere_* que el documento
 * copió de su plantilla al generarse. Cada transición:
 *  - valida el estado actual con bloqueo de fila (lockForUpdate) para que
 *    dos operadores no la ejecuten dos veces a la vez,
 *  - registra un DocumentoEvento (quién, acción, fecha, IP, user agent,
 *    observaciones) y la auditoría general,
 *  - resuelve la tarea de la etapa anterior y abre la de la siguiente.
 *
 * Los documentos que se imprimen y firman en papel NO se vuelven a escanear:
 * registrar la firma física es el paso final. Los métodos registrarEnvio(),
 * registrarRecepcion() y registrarEscaneo() (con sus estados enviado/recibido/
 * escaneado/archivado) se conservan SOLO para leer y cerrar expedientes
 * legacy que recorrieron el flujo anterior; ningún flujo nuevo los exige.
 */
class FlujoDocumentalService
{
    public const PERMISO_OPERACION = 'documentos_laborales.operar_fisico';

    /** @var list<TipoTarea> */
    private const TAREAS_FLUJO = [
        TipoTarea::FirmaPendiente, TipoTarea::ImpresionPendiente, TipoTarea::FirmaFisicaPendiente,
        TipoTarea::EnvioOriginalPendiente, TipoTarea::RecepcionOriginalPendiente, TipoTarea::EscaneoPendiente,
    ];

    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly DocumentoStorageService $expediente,
        private readonly TestigoDocumentalService $testigos,
        private readonly ?Request $request = null,
    ) {}

    /**
     * Primer paso tras generar: lleva el documento a su primera etapa
     * pendiente según sus banderas. Se llama dentro de la transacción de
     * MotorDocumentalService::generar().
     */
    public function iniciar(GeneratedDocument $documento, User $actor): GeneratedDocument
    {
        $this->evento($documento, 'generado', null, E::Generado, $actor);

        $siguiente = match (true) {
            $documento->requiere_firma_digital => E::PendienteFirmaColaborador,
            $this->requiereOriginalFisico($documento) => E::PendienteImpresion,
            default => E::Generado,
        };

        if ($siguiente !== E::Generado) {
            $this->mover($documento, $siguiente, $actor, 'enviado_a_firma');
        }

        return $documento;
    }

    /**
     * Aceptación/firma digital del propio colaborador desde la app/portal.
     * Guarda fecha, IP, user agent y el hash del PDF aceptado.
     */
    public function firmarDigitalmente(GeneratedDocument $documento, User $colaboradorUsuario, ?string $observaciones = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $colaboradorUsuario, $observaciones): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirEstado($documento, [E::PendienteFirmaColaborador], 'firmar digitalmente');

            if ($colaboradorUsuario->colaborador_id === null || $colaboradorUsuario->colaborador_id !== $documento->colaborador_id) {
                throw ValidationException::withMessages(['documento' => 'Solo el colaborador titular puede firmar digitalmente este documento.']);
            }

            $documento->update([
                'firmado_digital_en' => now(),
                'firmado_digital_por' => $colaboradorUsuario->id,
                'firma_digital_ip' => $this->request?->ip(),
                'firma_digital_user_agent' => mb_substr((string) $this->request?->userAgent(), 0, 500),
                'firma_digital_hash' => $documento->checksum,
            ]);

            $this->mover($documento, E::FirmadoDigitalmente, $colaboradorUsuario, 'firmado_digitalmente', $observaciones, ['hash' => $documento->checksum]);

            if ($this->requiereOriginalFisico($documento)) {
                $this->mover($documento, E::PendienteImpresion, $colaboradorUsuario, 'pendiente_impresion');
            } else {
                $this->mover($documento, E::Archivado, $colaboradorUsuario, 'archivado');
            }

            return $documento;
        });
    }

    public function marcarImpreso(GeneratedDocument $documento, User $actor, ?string $observaciones = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $observaciones): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirEstado($documento, [E::PendienteImpresion, E::Generado, E::FirmadoDigitalmente], 'marcar como impreso');

            $this->seguimiento($documento)->fill([
                'impreso_en' => now(),
                'impreso_por' => $actor->id,
                'sucursal_origen_id' => $documento->sucursal_id,
            ])->save();

            $documento->update(['status' => EstadoDocumentoGenerado::Entregado]);
            $this->mover($documento, E::Impreso, $actor, 'impreso', $observaciones);

            if ($documento->requiere_firma_fisica) {
                $this->mover($documento, E::PendienteFirmaFisica, $actor, 'pendiente_firma_fisica');
            }

            return $documento;
        });
    }

    /**
     * Testigos de la firma física: se eligen del buscador de personas activas
     * del alcance (TestigoDocumentalService) y se CONGELAN como snapshot
     * inmutable (colaborador_id, nombre, puesto, sucursal) del titular del
     * acto; el titular del documento nunca es su propio testigo, no se
     * admiten duplicados y solo un testigo externo se captura a mano.
     *
     * @param  array{huella_registrada?: bool, testigos?: list<array{colaborador_id?: int|string|null, nombre?: string|null, puesto?: string|null, cargo?: string|null, externo?: bool}>, observaciones?: string|null, fecha?: string|null}  $datos
     */
    public function registrarFirmaFisica(GeneratedDocument $documento, User $actor, array $datos = []): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $datos): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirEstado($documento, [E::PendienteFirmaFisica, E::Impreso], 'registrar la firma física');

            if ($documento->requiere_huella && ! ($datos['huella_registrada'] ?? false)) {
                throw ValidationException::withMessages(['huella_registrada' => 'Este documento requiere huella: confirma que se recabó.']);
            }

            $documento->loadMissing('colaborador');
            $capturados = is_array($datos['testigos'] ?? null) ? $datos['testigos'] : [];
            $testigos = $this->testigos->resolver($actor, $documento->colaborador, $capturados, max(1, count($capturados)));

            if ($documento->requiere_testigos && $testigos === []) {
                throw ValidationException::withMessages(['testigos' => 'Este documento requiere testigos: elige al menos uno del buscador.']);
            }

            $this->seguimiento($documento)->fill([
                'firmado_fisico_en' => $this->fecha($datos['fecha'] ?? null),
                'firma_fisica_registrada_por' => $actor->id,
                'huella_registrada' => (bool) ($datos['huella_registrada'] ?? false),
                'testigos' => $testigos !== [] ? $testigos : null,
            ])->save();

            $documento->update(['status' => EstadoDocumentoGenerado::Firmado]);
            $this->mover($documento, E::FirmadoFisicamente, $actor, 'firmado_fisicamente', $datos['observaciones'] ?? null);

            return $documento;
        });
    }

    /**
     * @param  array{paqueteria: string, numero_guia: string, observaciones?: string|null, fecha?: string|null}  $datos
     */
    public function registrarEnvio(GeneratedDocument $documento, User $actor, array $datos, ?UploadedFile $comprobante = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $datos, $comprobante): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirFlujoLegacyHabilitado($documento, 'registrar el envío a corporativo');
            // El envío SOLO procede desde el original ya firmado físicamente:
            // nunca desde «Impreso», para que un documento no salte a enviado
            // sin pasar por registrarFirmaFisica() (firmaFisicaRegistrada()
            // mentiría). Ver P1-8.
            $this->exigirEstado($documento, [E::FirmadoFisicamente], 'registrar el envío');

            $seguimiento = $this->seguimiento($documento);
            $seguimiento->fill([
                'enviado_en' => $this->fecha($datos['fecha'] ?? null),
                'enviado_por' => $actor->id,
                'paqueteria' => $datos['paqueteria'],
                'numero_guia' => $datos['numero_guia'],
                'sucursal_origen_id' => $seguimiento->sucursal_origen_id ?? $documento->sucursal_id,
            ]);

            if ($comprobante !== null) {
                $documento->loadMissing('colaborador');
                $colaborador = $documento->colaborador;
                abort_if($colaborador === null, 422, 'El documento no tiene colaborador.');

                $ruta = $this->expediente->guardarContenidoEnExpediente(
                    $colaborador,
                    $documento->categoria ?? CategoriaDocumento::Otros,
                    sprintf('Comprobante de envio - %s - guia %s.%s', $documento->titulo ?? 'documento', $datos['numero_guia'], $comprobante->getClientOriginalExtension()),
                    (string) file_get_contents($comprobante->getRealPath()),
                );

                $seguimiento->fill([
                    'comprobante_disk' => config('expedientes.disk'),
                    'comprobante_path' => $ruta,
                    'comprobante_nombre' => $comprobante->getClientOriginalName(),
                ]);
            }

            $seguimiento->save();
            $this->mover($documento, E::EnviadoCorporativo, $actor, 'enviado_corporativo', $datos['observaciones'] ?? null, [
                'paqueteria' => $datos['paqueteria'],
                'numero_guia' => $datos['numero_guia'],
            ]);

            return $documento;
        });
    }

    public function registrarRecepcion(GeneratedDocument $documento, User $actor, ?string $observaciones = null, ?string $fecha = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $observaciones, $fecha): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirFlujoLegacyHabilitado($documento, 'registrar la recepción en corporativo');
            $this->exigirEstado($documento, [E::EnviadoCorporativo], 'registrar la recepción');

            $this->seguimiento($documento)->fill(['recibido_en' => $this->fecha($fecha), 'recibido_por' => $actor->id])->save();
            $this->mover($documento, E::RecibidoCorporativo, $actor, 'recibido_corporativo', $observaciones);

            return $documento;
        });
    }

    /**
     * LEGACY (flujo anterior): sube el escaneo del original firmado y lo deja
     * en el expediente como EmployeeDocument aprobado del tipo documental de
     * la plantilla. El flujo nuevo NO escanea (el original se resguarda en
     * papel y la firma física es el paso final); este método se conserva solo
     * para cerrar expedientes legacy y no se ofrece como acción en la UI nueva.
     */
    public function registrarEscaneo(GeneratedDocument $documento, User $actor, UploadedFile $archivo, ?string $observaciones = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $archivo, $observaciones): GeneratedDocument {
            $documento = $this->bloquear($documento);
            $this->exigirFlujoLegacyHabilitado($documento, 'registrar el escaneo');
            $this->exigirEstado($documento, [E::RecibidoCorporativo, E::FirmadoFisicamente, E::Impreso], 'registrar el escaneo');

            $documento->loadMissing(['colaborador', 'plantilla.tipoDocumento']);
            $colaborador = $documento->colaborador;
            abort_if($colaborador === null, 422, 'El documento no tiene colaborador.');

            $tipo = $this->tipoDocumentalPara($documento);
            $escaneado = $this->expediente->subirVersion($colaborador, $tipo, $archivo, $actor->id, EstadoDocumento::Aprobado, 'generado');

            $this->seguimiento($documento)->fill(['escaneado_en' => now(), 'escaneado_por' => $actor->id])->save();
            $documento->update(['signed_document_id' => $escaneado->id, 'status' => EstadoDocumentoGenerado::Firmado]);
            $this->mover($documento, E::Escaneado, $actor, 'escaneado', $observaciones, ['employee_document_id' => $escaneado->id]);

            return $documento;
        });
    }

    public function archivar(GeneratedDocument $documento, User $actor, ?string $observaciones = null): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $observaciones): GeneratedDocument {
            $documento = $this->bloquear($documento);

            $permitidos = [E::Escaneado];

            if (! $this->requiereOriginalFisico($documento)) {
                $permitidos[] = E::Generado;
                $permitidos[] = E::FirmadoDigitalmente;
            }

            if (! $documento->requiere_firma_fisica) {
                $permitidos[] = E::Impreso;
            }

            $this->exigirEstado($documento, $permitidos, 'archivar');
            $this->mover($documento, E::Archivado, $actor, 'archivado', $observaciones);

            return $documento;
        });
    }

    /**
     * Fecha real del paso físico (la captura puede hacerse después de que
     * ocurrió); nunca futura. Sin fecha = ahora.
     */
    private function fecha(?string $fecha): CarbonImmutable
    {
        if ($fecha === null || trim($fecha) === '') {
            return CarbonImmutable::now();
        }

        $valor = CarbonImmutable::parse($fecha);

        if ($valor->isFuture()) {
            throw ValidationException::withMessages(['fecha' => 'La fecha no puede ser futura.']);
        }

        return $valor->isStartOfDay() && $valor->isToday() ? CarbonImmutable::now() : $valor;
    }

    public function cancelar(GeneratedDocument $documento, User $actor, string $motivo): GeneratedDocument
    {
        return DB::transaction(function () use ($documento, $actor, $motivo): GeneratedDocument {
            $documento = $this->bloquear($documento);

            if ($documento->estado_flujo?->estaConcluido()) {
                throw ValidationException::withMessages(['documento' => 'Un documento ya firmado físicamente, archivado o cancelado no puede cancelarse: usa el proceso de anulación auditado para dejar sin efecto un original ya firmado.']);
            }

            $documento->update(['motivo_cancelacion' => $motivo]);
            $this->mover($documento, E::Cancelado, $actor, 'cancelado', $motivo);

            return $documento;
        });
    }

    /**
     * Etapas del control físico que RH consulta: pendientes de imprimir,
     * de firma del colaborador y de firma física. El flujo nuevo concluye en
     * la firma física (FirmadoFisicamente es final): ya no hay etapas de
     * envío a corporativo, recepción ni escaneo. Los estados legacy de ese
     * flujo anterior subsisten solo para lectura, nunca como pendiente.
     *
     * @return array<string, list<E>>
     */
    public static function etapasPendientes(): array
    {
        return [
            'imprimir' => [E::PendienteImpresion],
            'firma_colaborador' => [E::PendienteFirmaColaborador],
            'firma_fisica' => [E::PendienteFirmaFisica],
        ];
    }

    private function requiereOriginalFisico(GeneratedDocument $documento): bool
    {
        return $documento->requiere_impresion || $documento->requiere_firma_fisica;
    }

    private function bloquear(GeneratedDocument $documento): GeneratedDocument
    {
        $bloqueado = GeneratedDocument::query()->lockForUpdate()->findOrFail($documento->id);

        return $bloqueado;
    }

    /**
     * Compuerta de COMPATIBILIDAD LEGACY (envío a corporativo / recepción /
     * escaneo): el flujo nuevo concluye al registrar la firma física y ya no
     * exige estos pasos. Se permiten SOLO para cerrar expedientes antiguos:
     *  - cuando el documento ya venía dentro del tramo legacy
     *    (EnviadoCorporativo/RecibidoCorporativo), para poder finalizarlo, o
     *  - cuando se habilita explícitamente la bandera features.documentos_flujo_legacy.
     * Para cualquier documento NUEVO (que concluye en FirmadoFisicamente) estos
     * pasos quedan bloqueados, evitando que un original firmado "retroceda"
     * al flujo anterior.
     */
    private function exigirFlujoLegacyHabilitado(GeneratedDocument $documento, string $accion): void
    {
        $legacyEnCurso = in_array($documento->estado_flujo, [E::EnviadoCorporativo, E::RecibidoCorporativo], true);

        if ($legacyEnCurso || (bool) config('features.documentos_flujo_legacy', false)) {
            return;
        }

        throw ValidationException::withMessages([
            'estado' => sprintf('No se puede %s: ese paso pertenece al flujo documental anterior (envío a corporativo, recepción y escaneo). El documento concluye al registrar su firma física; esos pasos solo se conservan para cerrar expedientes antiguos.', $accion),
        ]);
    }

    /**
     * @param  list<E>  $permitidos
     */
    private function exigirEstado(GeneratedDocument $documento, array $permitidos, string $accion): void
    {
        if (! in_array($documento->estado_flujo, $permitidos, true)) {
            $actual = $documento->estado_flujo?->etiqueta() ?? 'sin flujo';

            throw ValidationException::withMessages([
                'estado' => "No se puede {$accion}: el documento está en «{$actual}».",
            ]);
        }
    }

    private function seguimiento(GeneratedDocument $documento): SeguimientoDocumentoFisico
    {
        return SeguimientoDocumentoFisico::query()->firstOrNew(['generated_document_id' => $documento->id]);
    }

    private function tipoDocumentalPara(GeneratedDocument $documento): DocumentType
    {
        $tipo = $documento->plantilla?->tipoDocumento;

        if ($tipo !== null) {
            return $tipo;
        }

        $clave = $documento->clave_plantilla ?? 'documento_laboral';
        $tipo = DocumentType::query()->firstOrCreate(
            ['clave' => $clave],
            [
                'nombre' => (string) ($documento->titulo ?? config("contratos.plantillas.{$clave}.nombre", 'Documento laboral firmado')),
                'categoria' => $documento->categoria->value ?? 'otros',
                'requerido' => false,
                'aplica_alta' => false,
                'activo' => true,
            ],
        );

        return $tipo;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function mover(GeneratedDocument $documento, E $nuevo, User $actor, string $accion, ?string $observaciones = null, array $datos = []): void
    {
        $anterior = $documento->estado_flujo;
        $documento->update(['estado_flujo' => $nuevo]);

        $this->evento($documento, $accion, $anterior, $nuevo, $actor, $observaciones, $datos);
        $this->auditoria->registrar("documento_{$accion}", $documento, $actor, ['estado_anterior' => $anterior?->value, 'estado_nuevo' => $nuevo->value, ...$datos]);

        $this->tareas->resolver(self::TAREAS_FLUJO, $documento, $actor);
        $this->abrirTareaDeEtapa($documento, $nuevo);
    }

    private function abrirTareaDeEtapa(GeneratedDocument $documento, E $estado): void
    {
        $documento->loadMissing(['colaborador.user']);
        $colaborador = $documento->colaborador;
        $titulo = $documento->titulo ?? 'Documento laboral';

        if ($estado === E::PendienteFirmaColaborador) {
            $usuario = $colaborador?->user;

            $this->tareas->abrir(TipoTarea::FirmaPendiente, $documento, [
                'titulo' => "Firma pendiente: {$titulo}",
                'prioridad' => PrioridadTarea::Alta,
                'colaborador' => $colaborador,
                'usuario' => $usuario,
                'permiso' => $usuario === null ? self::PERMISO_OPERACION : null,
                'accion' => 'firmar_documento',
            ]);

            if ($usuario !== null) {
                $this->notificador->notificar([$usuario], 'documento_firma_pendiente', 'Tienes un documento por firmar', "Revisa y firma: {$titulo}.", $documento, 'firmar_documento', 'alta');
            }

            return;
        }

        // Flujo nuevo: solo se abren tareas hasta la firma física. Al quedar
        // FirmadoFisicamente el documento está CONCLUIDO (el original se
        // resguarda en papel), así que no se abre tarea de envío, recepción
        // ni escaneo — esos pasos solo existían en el flujo anterior.
        $tipo = match ($estado) {
            E::PendienteImpresion => TipoTarea::ImpresionPendiente,
            E::PendienteFirmaFisica => TipoTarea::FirmaFisicaPendiente,
            default => null,
        };

        if ($tipo === null) {
            return;
        }

        $this->tareas->abrir($tipo, $documento, [
            'titulo' => "{$tipo->etiqueta()}: {$titulo}",
            'colaborador' => $colaborador,
            'permiso' => self::PERMISO_OPERACION,
            'accion' => $tipo->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function evento(GeneratedDocument $documento, string $accion, ?E $anterior, ?E $nuevo, ?User $actor, ?string $observaciones = null, array $datos = []): void
    {
        DocumentoEvento::query()->create([
            'generated_document_id' => $documento->id,
            'accion' => $accion,
            'estado_anterior' => $anterior?->value,
            'estado_nuevo' => $nuevo?->value,
            'user_id' => $actor?->id,
            'ip' => $this->request?->ip(),
            'user_agent' => $this->request !== null ? mb_substr((string) $this->request->userAgent(), 0, 500) : null,
            'observaciones' => $observaciones,
            'datos' => $datos !== [] ? $datos : null,
        ]);
    }
}
