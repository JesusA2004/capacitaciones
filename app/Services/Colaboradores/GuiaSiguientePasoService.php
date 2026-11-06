<?php

namespace App\Services\Colaboradores;

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoAcuseDescriptivoPuesto;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Expedientes\DocumentosPendientesService;
use App\Services\Expedientes\ExpedienteIncompletoService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Expedientes\RequisitoDocumentoGeneradoService;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\ProgresoInduccionService;

/**
 * «Tu siguiente paso»: los pendientes del colaborador ORDENADOS en una sola
 * guía (app y, cuando se requiera, web), para que nunca tenga que buscar:
 *
 *   1. Completar información (datos personales)
 *   2. Fotografía para expediente
 *   3. Documentos requeridos, uno por uno (rechazados primero)
 *   4. Inducción: documento O video (basta uno) → «Terminé» → evaluación
 *   5. Firmas y lecturas pendientes (documentos laborales, descriptivo)
 *
 * Cada paso trae su estado (hecho | en_revision | pendiente | rechazado |
 * espera), un motivo cuando RH rechazó, y la acción exacta (`accion.app` +
 * `params`) que la app traduce a su pantalla. `siguiente` es el primer paso
 * accionable. La regla del bloqueo NO se duplica aquí: viene de
 * ExpedienteIncompletoService (`bloqueo` y `bloquea_app` de cada paso).
 * Solo lectura: nunca registra avance.
 *
 * @phpstan-type AccionPaso array{etiqueta: string, app: string, params: array<string, int|string>}
 * @phpstan-type Paso array{clave: string, grupo: string, titulo: string, descripcion: string, estado: string, estado_etiqueta: string, motivo: string|null, detalle: list<string>, accion: AccionPaso|null, bloquea_app: bool}
 */
class GuiaSiguientePasoService
{
    public const HECHO = 'hecho';

    public const EN_REVISION = 'en_revision';

    public const PENDIENTE = 'pendiente';

    public const RECHAZADO = 'rechazado';

    public const ESPERA = 'espera';

    /** Máximo de páginas faltantes que se enumeran («y 4 más»). */
    private const MAX_PAGINAS_LISTADAS = 8;

    public function __construct(
        private readonly ExpedienteIncompletoService $expedienteIncompleto,
        private readonly DatosFaltantesService $datos,
        private readonly DocumentosPendientesService $documentos,
        private readonly ExpedienteService $expediente,
        private readonly FotoColaboradorService $fotos,
        private readonly ProgresoInduccionService $progresoInduccion,
        private readonly AccesoInduccionColaboradorService $accesoInduccion,
        private readonly AcuseDescriptivoPuestoService $descriptivos,
        private readonly RequisitoDocumentoGeneradoService $requisitosGenerados,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function guia(User $usuario, Colaborador $colaborador): array
    {
        $bloqueo = $this->expedienteIncompleto->estado($usuario);
        $induccion = $this->progresoInduccion->resumen($colaborador);

        $pasos = [
            ...$this->pasoDatos($colaborador, $bloqueo['datos_faltantes'] > 0),
            ...$this->pasoFoto($colaborador),
            ...$this->pasosDocumentos($colaborador, $bloqueo['bloqueado'], $bloqueo['documentos_por_subir'] > 0),
            ...$this->pasosInduccion($colaborador, $induccion),
            ...$this->pasosFirmas($usuario, $colaborador),
        ];

        $siguiente = null;

        foreach ($pasos as $paso) {
            if (in_array($paso['estado'], [self::PENDIENTE, self::RECHAZADO], true) && $paso['accion'] !== null) {
                $siguiente = $paso;
                break;
            }
        }

        $cuenta = fn (array $estados): int => count(array_filter($pasos, fn (array $p): bool => in_array($p['estado'], $estados, true)));
        $porHacer = $cuenta([self::PENDIENTE, self::RECHAZADO]);
        $estadoInduccion = (string) ($induccion['estado'] ?? AccesoInduccionColaborador::SinAsignar->value);

        return [
            'pasos' => $pasos,
            'siguiente' => $siguiente,
            'resumen' => [
                'total' => count($pasos),
                'hechos' => $cuenta([self::HECHO]),
                'en_revision' => $cuenta([self::EN_REVISION]),
                'por_hacer' => $porHacer,
                'en_espera' => $cuenta([self::ESPERA]),
                'todo_listo' => $porHacer === 0,
            ],
            // Inducción en curso: la app la pone a la vista (no escondida en Perfil).
            'induccion' => [
                'activa' => in_array($estadoInduccion, [AccesoInduccionColaborador::Disponible->value, AccesoInduccionColaborador::EvaluacionPendiente->value, AccesoInduccionColaborador::EnRevision->value], true),
                'estado' => $estadoInduccion,
                'porcentaje' => (int) ($induccion['porcentaje'] ?? 0),
            ],
            'bloqueo' => $bloqueo,
        ];
    }

    /**
     * @return list<Paso>
     */
    private function pasoDatos(Colaborador $colaborador, bool $bloquea): array
    {
        $personales = $this->datos->faltantes($colaborador)['personales'];

        if ($personales === []) {
            return [$this->paso('datos', 'datos', 'Tus datos personales', 'Están completos.', self::HECHO)];
        }

        if ($this->datos->solicitudAbierta($colaborador)) {
            return [$this->paso('datos', 'datos', 'Tus datos personales', 'Recursos Humanos está revisando los datos que enviaste. No necesitas hacer nada más.', self::EN_REVISION)];
        }

        $secciones = $this->expedienteIncompleto->seccionesPersonales($personales);

        return [$this->paso(
            'datos',
            'datos',
            'Completa tu información',
            $this->expedienteIncompleto->textoDatos(count($personales), count($secciones)),
            self::PENDIENTE,
            detalle: array_map(fn (array $s): string => sprintf('%s: %s', $s['etiqueta'], implode(', ', $s['faltantes'])), $secciones),
            accion: $this->accion('Completar mis datos', 'completar-datos'),
            bloqueaApp: $bloquea,
        )];
    }

    /**
     * @return list<Paso>
     */
    private function pasoFoto(Colaborador $colaborador): array
    {
        $foto = $this->fotos->estadoPara($colaborador);
        $ultimo = $foto['ultimo_cambio'];
        $rechazada = is_array($ultimo) && ($ultimo['estado'] ?? null) === 'rechazado';

        return [match (true) {
            $foto['estado'] === 'oficial' => $this->paso('foto', 'foto', 'Fotografía para tu expediente', 'Ya tienes tu foto oficial.', self::HECHO),
            $foto['estado'] === 'cambio_pendiente' && $colaborador->foto_path !== null => $this->paso('foto', 'foto', 'Fotografía para tu expediente', 'Ya tienes tu foto oficial; Recursos Humanos revisa la nueva.', self::HECHO),
            $foto['estado'] === 'cambio_pendiente' => $this->paso('foto', 'foto', 'Fotografía para tu expediente', 'Recursos Humanos está revisando tu foto.', self::EN_REVISION),
            $rechazada => $this->paso('foto', 'foto', 'Fotografía para tu expediente', 'Recursos Humanos pidió otra foto.', self::RECHAZADO, motivo: is_string($ultimo['motivo_rechazo'] ?? null) ? $ultimo['motivo_rechazo'] : null, accion: $this->accion('Tomar otra foto', 'foto')),
            default => $this->paso('foto', 'foto', 'Fotografía para tu expediente', 'Tómate una foto de frente, con buena luz y sin lentes oscuros.', self::PENDIENTE, accion: $this->accion('Tomar mi foto', 'foto')),
        }];
    }

    /**
     * Un paso por documento requerido que la persona sube: primero los
     * rechazados, luego los que faltan, después los que RH revisa y al final
     * los aprobados.
     *
     * @return list<Paso>
     */
    private function pasosDocumentos(Colaborador $colaborador, bool $bloqueoActivo, bool $faltantesBloquean): array
    {
        $vigentes = $this->expediente->documentosVigentes($colaborador);
        $grupos = [self::RECHAZADO => [], self::PENDIENTE => [], self::EN_REVISION => [], self::ESPERA => [], self::HECHO => []];
        $generados = null;

        foreach ($this->documentos->tiposExigibles() as $tipo) {
            $documento = $vigentes->get($tipo->id);
            $estado = $documento instanceof EmployeeDocument ? $documento->status : EstadoDocumento::Pendiente;
            $clave = sprintf('documento_%d', $tipo->id);
            $params = ['document_type_id' => $tipo->id];

            // Solicitud de empleo / Estudio socioeconómico: los genera PEOPLE
            // con tus datos; nunca «Subir documento» ni bloquean la app.
            if ($this->requisitosGenerados->esGenerable($tipo)) {
                $generados ??= $this->requisitosGenerados->generadosDe($colaborador);
                $presentacion = $this->requisitosGenerados->presentacion($tipo, $documento, $generados, false);
                $paso = $this->paso($clave, 'documentos', $tipo->nombre, $presentacion['mensaje'], $presentacion['cumplido'] ? self::HECHO : self::ESPERA);
                $grupos[$paso['estado']][] = $paso;

                continue;
            }

            $paso = match ($estado) {
                EstadoDocumento::Aprobado => $this->paso($clave, 'documentos', $tipo->nombre, 'Aprobado por Recursos Humanos.', self::HECHO),
                EstadoDocumento::Cargado, EstadoDocumento::EnRevision, EstadoDocumento::CambioSolicitado => $this->paso($clave, 'documentos', $tipo->nombre, 'Ya lo subiste. Recursos Humanos lo está revisando.', self::EN_REVISION),
                EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion => $this->paso(
                    $clave,
                    'documentos',
                    $tipo->nombre,
                    'Recursos Humanos lo rechazó. Súbelo de nuevo.',
                    self::RECHAZADO,
                    motivo: $documento instanceof EmployeeDocument ? ($documento->rejection_reason ?? $documento->comments) : null,
                    accion: $this->accion('Corregir documento', 'documento', $params),
                    bloqueaApp: $bloqueoActivo,
                ),
                EstadoDocumento::Vencido => $this->paso($clave, 'documentos', $tipo->nombre, 'Tu documento venció. Sube uno vigente.', self::PENDIENTE, accion: $this->accion('Subir documento', 'documento', $params), bloqueaApp: $faltantesBloquean),
                default => $this->paso($clave, 'documentos', $tipo->nombre, $tipo->descripcion !== null && $tipo->descripcion !== '' ? $tipo->descripcion : 'Pendiente de subir.', self::PENDIENTE, accion: $this->accion('Subir documento', 'documento', $params), bloqueaApp: $faltantesBloquean),
            };

            $grupos[$paso['estado']][] = $paso;
        }

        return array_merge(...array_values($grupos));
    }

    /**
     * Inducción Mr. Lana: documento O video (basta con uno) → «Terminé» →
     * evaluación (con la misma verdad de ProgresoInduccionService).
     *
     * @param  array<string, mixed>  $resumen
     * @return list<Paso>
     */
    private function pasosInduccion(Colaborador $colaborador, array $resumen): array
    {
        $estado = AccesoInduccionColaborador::tryFrom((string) ($resumen['estado'] ?? '')) ?? AccesoInduccionColaborador::SinAsignar;

        if ($estado === AccesoInduccionColaborador::SinAsignar) {
            return [];
        }

        if ($estado === AccesoInduccionColaborador::Completada) {
            return [$this->paso('induccion_evaluacion', 'induccion', 'Inducción Mr. Lana', 'Aprobaste tu evaluación de inducción.', self::HECHO)];
        }

        $pasos = [];
        $contenidoVisto = (bool) ($resumen['contenido_visto'] ?? false);
        $presentacion = is_array($resumen['presentacion'] ?? null) ? $resumen['presentacion'] : [];
        $material = is_array($resumen['material'] ?? null) ? $resumen['material'] : null;
        $evaluacion = is_array($resumen['evaluacion'] ?? null) ? $resumen['evaluacion'] : null;

        // Presentación O video (nunca los dos): con uno visto, el otro queda
        // como opcional y la evaluación se desbloquea.
        $conHojas = (bool) ($presentacion['incluida'] ?? false);
        $conVideo = $material !== null && (bool) ($material['incluido'] ?? false) && ($material['tipo'] ?? null) === 'video';
        $hojasListas = $conHojas && (bool) ($presentacion['completada'] ?? false);
        $videoVisto = $conVideo && (bool) ($material['completado'] ?? false);
        $cumplido = $contenidoVisto || $hojasListas || $videoVisto || (! $conHojas && ! $conVideo);

        if ($conHojas) {
            $total = (int) ($presentacion['total_paginas'] ?? 0);
            $faltantes = $hojasListas || $cumplido ? [] : $this->paginasFaltantes($colaborador, $total);

            $pasos[] = match (true) {
                // Sin PDF legible no hay hojas que pedir (como antes).
                $hojasListas || (! $cumplido && $faltantes === []) => $this->paso('induccion_paginas', 'induccion', 'Documento de la inducción', 'Viste todas las páginas.', self::HECHO),
                $cumplido => $this->paso('induccion_paginas', 'induccion', 'Documento de la inducción', $videoVisto ? 'Opcional: ya viste el video de la inducción.' : 'Cubierto: ya pasaste a la evaluación.', self::HECHO),
                default => $this->paso(
                    'induccion_paginas',
                    'induccion',
                    'Documento de la inducción',
                    sprintf('Llevas %d de %d páginas.%s', (int) ($presentacion['paginas_vistas'] ?? 0), $total, $conVideo ? ' O, si prefieres, ve el video completo.' : ''),
                    self::PENDIENTE,
                    detalle: [$this->textoPaginas($faltantes)],
                    accion: $this->accion(sprintf('Ir a la página %d', $faltantes[0]), 'induccion-documento', ['pagina' => $faltantes[0]]),
                ),
            };
        }

        if ($conVideo) {
            $porcentaje = (int) ($material['porcentaje'] ?? 0);

            $pasos[] = match (true) {
                $videoVisto => $this->paso('induccion_video', 'induccion', 'Video de la inducción', 'Viste el video completo.', self::HECHO),
                $cumplido => $this->paso('induccion_video', 'induccion', 'Video de la inducción', $hojasListas ? 'Opcional: ya viste el documento de la inducción.' : 'Cubierto: ya pasaste a la evaluación.', self::HECHO),
                ! (bool) ($material['disponible'] ?? false) => $this->paso('induccion_video', 'induccion', 'Video de la inducción', 'El video se está preparando. Te avisaremos cuando esté listo.', self::ESPERA),
                default => $this->paso(
                    'induccion_video',
                    'induccion',
                    'Video de la inducción',
                    $porcentaje > 0
                        ? sprintf('Llevas %d %% del video. Retomas donde te quedaste.', $porcentaje)
                        : ($conHojas ? 'Velo completo (o termina el documento) para desbloquear tu evaluación.' : 'Velo completo para desbloquear tu evaluación.'),
                    self::PENDIENTE,
                    accion: $this->accion($porcentaje > 0 ? 'Continuar el video' : 'Ver el video', 'induccion-video'),
                ),
            };
        }

        if ($evaluacion === null && ! $contenidoVisto) {
            $pasos[] = $cumplido
                ? $this->paso('induccion_terminar', 'induccion', 'Termina tu inducción', 'Ya viste tu inducción. Confirma que terminaste para desbloquear tu evaluación.', self::PENDIENTE, accion: $this->accion('Terminé la inducción', 'induccion'))
                : $this->paso('induccion_evaluacion', 'induccion', 'Evaluación de inducción', $conHojas && $conVideo ? 'Se desbloquea al terminar el documento o el video.' : ($conVideo ? 'Se desbloquea al ver el video.' : 'Se desbloquea al terminar el documento.'), self::ESPERA);

            return $pasos;
        }

        $estadoEvaluacion = is_array($evaluacion) ? (string) ($evaluacion['estado'] ?? '') : '';

        $pasos[] = match (true) {
            (bool) ($resumen['puede_contestar_evaluacion'] ?? false) => $this->paso('induccion_evaluacion', 'induccion', 'Evaluación de inducción', 'Tu evaluación está lista. Contéstala con calma.', self::PENDIENTE, accion: $this->accion('Contestar evaluación', 'induccion-evaluacion')),
            $estadoEvaluacion === 'pendiente_calificacion_rh' => $this->paso('induccion_evaluacion', 'induccion', 'Evaluación de inducción', 'Ya la contestaste. Recursos Humanos la está calificando.', self::EN_REVISION),
            $estadoEvaluacion === 'requiere_reforzamiento' => $this->paso('induccion_evaluacion', 'induccion', 'Evaluación de inducción', 'Recursos Humanos te contactará para reforzar algunos temas.', self::ESPERA),
            default => $this->paso('induccion_evaluacion', 'induccion', 'Evaluación de inducción', 'Tu evaluación se habilita en unos momentos.', self::ESPERA),
        };

        return $pasos;
    }

    /**
     * @return list<int>
     */
    private function paginasFaltantes(Colaborador $colaborador, int $total): array
    {
        if ($total < 1) {
            return [];
        }

        $avance = $this->accesoInduccion->avanceInduccion($colaborador);
        $vistas = array_map('intval', (array) (((array) ($avance->progreso_contenido ?? []))['paginas_vistas'] ?? []));

        return array_values(array_diff(range(1, $total), $vistas));
    }

    /**
     * «Te falta la página 30» / «Te faltan las páginas 12, 13 y 30».
     *
     * @param  list<int>  $paginas
     */
    private function textoPaginas(array $paginas): string
    {
        if (count($paginas) === 1) {
            return sprintf('Te falta la página %d.', $paginas[0]);
        }

        $listadas = array_slice($paginas, 0, self::MAX_PAGINAS_LISTADAS);
        $resto = count($paginas) - count($listadas);

        if ($resto > 0) {
            return sprintf('Te faltan %d páginas: %s y %d más.', count($paginas), implode(', ', $listadas), $resto);
        }

        $ultima = array_pop($listadas);

        return sprintf('Te faltan las páginas %s y %d.', implode(', ', $listadas), $ultima);
    }

    /**
     * @return list<Paso>
     */
    private function pasosFirmas(User $usuario, Colaborador $colaborador): array
    {
        $pasos = [];

        $porFirmar = GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado_flujo', EstadoFlujoDocumento::PendienteFirmaColaborador->value)
            ->orderBy('id')
            ->get(['id', 'titulo']);

        foreach ($porFirmar as $documento) {
            $pasos[] = $this->paso(
                sprintf('firma_%d', $documento->id),
                'firmas',
                sprintf('Firma: %s', $documento->titulo ?? 'Documento'),
                'Revísalo con calma y fírmalo desde la app.',
                self::PENDIENTE,
                accion: $this->accion('Revisar y firmar', 'documento-laboral', ['id' => $documento->id]),
            );
        }

        $acuse = $this->descriptivos->vigenteDe($usuario);

        if ($acuse !== null) {
            $pasos[] = $acuse->estado() === EstadoAcuseDescriptivoPuesto::Enterado
                ? $this->paso('descriptivo_puesto', 'firmas', 'Descriptivo de puesto', 'Ya confirmaste que estás enterado.', self::HECHO)
                : $this->paso('descriptivo_puesto', 'firmas', 'Lee tu descriptivo de puesto', 'Tus actividades y responsabilidades: léelas y confirma «OK, enterado».', self::PENDIENTE, accion: $this->accion('Leer y confirmar', 'descriptivo-puesto'));
        }

        return $pasos;
    }

    /**
     * @param  array<string, int|string>  $params
     * @return AccionPaso
     */
    private function accion(string $etiqueta, string $app, array $params = []): array
    {
        return ['etiqueta' => $etiqueta, 'app' => $app, 'params' => $params];
    }

    /**
     * @param  list<string>  $detalle
     * @param  AccionPaso|null  $accion
     * @return Paso
     */
    private function paso(
        string $clave,
        string $grupo,
        string $titulo,
        string $descripcion,
        string $estado,
        ?string $motivo = null,
        array $detalle = [],
        ?array $accion = null,
        bool $bloqueaApp = false,
    ): array {
        return [
            'clave' => $clave,
            'grupo' => $grupo,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'estado' => $estado,
            'estado_etiqueta' => match ($estado) {
                self::HECHO => 'Listo',
                self::EN_REVISION => 'En revisión por RH',
                self::RECHAZADO => 'Rechazado',
                self::ESPERA => 'En espera',
                default => 'Pendiente',
            },
            'motivo' => $motivo !== null && trim($motivo) !== '' ? $motivo : null,
            'detalle' => $detalle,
            'accion' => $accion,
            'bloquea_app' => $bloqueaApp,
        ];
    }
}
