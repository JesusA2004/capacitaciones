<?php

namespace App\Services\Onboarding;

use App\Exceptions\ArchivoGeneradoNoDisponibleException;
use App\Models\OnboardingAvance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * La inducción se ve en el PDF OFICIAL (config('induccion.pdf_oficial')):
 * el visor pdf.js dibuja sus páginas tal cual (diseño, logos, organigrama).
 * Este servicio lo sirve protegido y lleva el avance del COLABORADOR por
 * página realmente mostrada. El colaborador lo ve mientras su inducción está
 * asignada y por ver (AccesoInduccionColaboradorService).
 *
 * La inducción se cumple con UNO de los materiales asignados: TODAS las hojas
 * de la presentación O el video completo (ReproduccionVideoInduccionService),
 * nunca los dos — ver materialVisto(). «Terminé la inducción» (terminar()) y
 * marcarVistoSiCumple() marcan contenido_visto_en; la evaluación formal se
 * habilita cuando ese gate queda cumplido
 * (EvaluacionInduccionService::habilitarPara()).
 */
class InduccionPdfService
{
    public function __construct(
        private readonly AccesoInduccionColaboradorService $acceso,
        private readonly MaterialInduccionService $materiales,
        private readonly ReproduccionVideoInduccionService $video,
    ) {}

    public function ruta(): string
    {
        $ruta = (string) config('induccion.pdf_oficial');

        return preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $ruta) === 1 ? $ruta : base_path($ruta);
    }

    public function disponible(): bool
    {
        return is_file($this->ruta());
    }

    /**
     * Páginas del PDF oficial (cacheado por archivo: cambia solo si se
     * reemplaza el documento).
     */
    public function totalPaginas(): int
    {
        if (! $this->disponible()) {
            return 0;
        }

        $ruta = $this->ruta();
        $clave = sprintf('induccion_pdf_paginas:%s', sha1($ruta.'|'.filesize($ruta).'|'.filemtime($ruta)));

        return (int) Cache::rememberForever($clave, function () use ($ruta): int {
            try {
                return (new Fpdi)->setSourceFile($ruta);
            } catch (Throwable) {
                // Sin poder leer el índice: el visor igual muestra el PDF;
                // el avance se apoya en lo que reporte pdf.js.
                return 0;
            }
        });
    }

    /**
     * @return array{url: string, titulo: string, total_paginas: int, puede_descargar?: bool}
     */
    public function datosVisor(bool $api = false, ?User $viewer = null): array
    {
        $datos = [
            'url' => $api ? route('api.v1.induccion.pdf') : route('induccion.pdf'),
            'titulo' => (string) config('induccion.titulo'),
            'total_paginas' => $this->totalPaginas(),
        ];

        // Respaldo si el visor no dibuja. Sin quien mira no se manda: ausente = no.
        if ($viewer !== null) {
            $datos['puede_descargar'] = $this->puedeVerPdf($viewer);
        }

        return $datos;
    }

    private function puedeVerPdf(User $usuario): bool
    {
        try {
            $this->acceso->exigirPdfVisible($usuario);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * Solo RH (onboarding.gestionar) o el colaborador mientras su inducción
     * está asignada y por ver (AccesoInduccionColaboradorService).
     *
     * @throws AuthorizationException
     * @throws ArchivoGeneradoNoDisponibleException
     */
    public function respuesta(User $usuario): BinaryFileResponse
    {
        $this->acceso->exigirPdfVisible($usuario);

        if (! $this->disponible()) {
            throw new ArchivoGeneradoNoDisponibleException(
                titulo: (string) config('induccion.titulo'),
                mensaje: 'El documento oficial de la inducción no está cargado en el servidor. Avisa a Sistemas.',
            );
        }

        return response()->file($this->ruta(), [
            'Content-Type' => 'application/pdf',
            // Nombre amigable (el archivo fuente del servidor nunca se expone):
            // ASCII de respaldo + UTF-8 para «Inducción Mr. Lana.pdf».
            'Content-Disposition' => "inline; filename=\"Induccion Mr. Lana.pdf\"; filename*=UTF-8''".rawurlencode('Inducción Mr. Lana.pdf'),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Registra una página MOSTRADA al colaborador titular (el visor la
     * reporta al llegar a ella). Nunca marca páginas que no se abrieron ni
     * completa la inducción por sí sola: eso lo hace terminar().
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function registrarPagina(OnboardingAvance $avance, User $usuario, int $pagina): OnboardingAvance
    {
        $this->exigirTitular($avance, $usuario);
        $total = $this->totalPaginas();

        if ($total < 1 || $pagina < 1 || $pagina > $total) {
            throw ValidationException::withMessages(['pagina' => 'Esa página no existe en la inducción.']);
        }

        $progreso = (array) ($avance->progreso_contenido ?? []);
        $vistas = collect((array) ($progreso['paginas_vistas'] ?? []))->map(fn ($p) => (int) $p)->push($pagina)->unique()->sort()->values();

        $avance->update(['progreso_contenido' => [
            ...$progreso,
            'modo' => 'pdf',
            'paginas_vistas' => $vistas->all(),
            'ultima_pagina' => $pagina,
            'total_paginas' => $total,
            'porcentaje' => (int) floor($vistas->count() / $total * 100),
        ]]);

        return $avance->refresh();
    }

    /**
     * Datos aditivos para el material del colaborador (web y app). El video
     * ya NO espera a la presentación (basta con una de las dos): se conservan
     * las llaves, siempre «sin bloqueo», porque la app instalada las lee.
     *
     * @return array{video_bloqueado: bool, paginas_faltantes: list<int>, video_bloqueado_mensaje: string|null}
     */
    public function bloqueoVideo(OnboardingAvance $avance): array
    {
        return ['video_bloqueado' => false, 'paginas_faltantes' => [], 'video_bloqueado_mensaje' => null];
    }

    /** Recorrió TODAS las hojas del PDF oficial (sin PDF legible no cuenta). */
    public function presentacionCompleta(OnboardingAvance $avance): bool
    {
        $total = $this->totalPaginas();

        return $total > 0 && count(array_intersect($this->paginasVistas($avance), range(1, $total))) >= $total;
    }

    /**
     * Regla de la inducción: basta con UNO de los materiales asignados —la
     * presentación completa O el video completo—, nunca los dos. El audio es
     * narración: ni cuenta ni bloquea.
     *
     * @return array{presentacion: bool, video: bool, presentacion_vista: bool, video_visto: bool}
     */
    public function materialesRequeridos(OnboardingAvance $avance): array
    {
        $asignado = $this->acceso->contenidoAsignado($avance);
        $material = $asignado['material'] ? $this->materiales->materialActivoDeAvance($avance) : null;
        $video = $material !== null && $material->tipo->bloqueaEvaluacion();

        return [
            'presentacion' => $asignado['presentacion'],
            'video' => $video,
            'presentacion_vista' => $asignado['presentacion'] && $this->presentacionCompleta($avance),
            'video_visto' => $video && $this->video->videoCompletado($avance),
        ];
    }

    /** Ya vio la presentación completa O el video completo (de lo asignado). */
    public function materialVisto(OnboardingAvance $avance): bool
    {
        $requeridos = $this->materialesRequeridos($avance);

        return $requeridos['presentacion_vista'] || $requeridos['video_visto'];
    }

    /**
     * Da por terminada la inducción (contenido_visto_en) en cuanto ya vio la
     * presentación O el video, aunque no haya presionado «Terminé»: así nadie
     * se queda detenido porque después se cargó un video (tampoco en la app
     * instalada, que aún pide el video para ofrecer «Terminé»). Idempotente;
     * true si la marcó ahora.
     */
    public function marcarVistoSiCumple(OnboardingAvance $avance): bool
    {
        if ($avance->contenido_visto_en !== null
            || $this->acceso->asignacionRetirada($avance)
            || ! $this->materialVisto($avance)) {
            return false;
        }

        $avance->update(['contenido_visto_en' => now()]);

        return true;
    }

    /**
     * @return list<int>
     */
    private function paginasVistas(OnboardingAvance $avance): array
    {
        return array_values(array_unique(array_map('intval', (array) (((array) ($avance->progreso_contenido ?? []))['paginas_vistas'] ?? []))));
    }

    /**
     * «Primero termina la presentación: te falta la página 30.»
     *
     * @param  non-empty-list<int>  $faltantes
     */
    public function mensajePresentacionIncompleta(array $faltantes): string
    {
        $cantidad = count($faltantes);

        return match (true) {
            $cantidad === 1 => sprintf('Primero termina la presentación: te falta la página %d.', $faltantes[0]),
            $cantidad <= 5 => sprintf('Primero termina la presentación: te faltan las páginas %s y %d.', implode(', ', array_slice($faltantes, 0, -1)), $faltantes[$cantidad - 1]),
            default => sprintf('Primero termina la presentación: te faltan %d páginas (empieza en la página %d).', $cantidad, $faltantes[0]),
        };
    }

    /**
     * «Terminé la inducción»: basta con haber visto UNO de los materiales
     * asignados —todas las hojas de la presentación O el video completo—,
     * nunca los dos. Lo que no se le asignó no se le exige
     * (AsignacionInduccionService); si no se le asignó nada que contar (p. ej.
     * solo la narración de audio), puede terminar. Marca el contenido visto
     * (gate que habilita la evaluación formal).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function terminar(OnboardingAvance $avance, User $usuario): OnboardingAvance
    {
        $this->exigirTitular($avance, $usuario);
        $requeridos = $this->materialesRequeridos($avance);
        $algoQueVer = $requeridos['presentacion'] || $requeridos['video'];

        if ($algoQueVer && ! $requeridos['presentacion_vista'] && ! $requeridos['video_visto']) {
            throw ValidationException::withMessages(['induccion' => $this->mensajeFaltaMaterial($avance, $requeridos)]);
        }

        if ($avance->contenido_visto_en === null) {
            $avance->update(['contenido_visto_en' => now()]);
        }

        return $avance->refresh();
    }

    /**
     * «Antes de terminar ve el video completo o termina la presentación: te
     * falta la página 30.» — dice qué le falta según lo que se le asignó.
     *
     * @param  array{presentacion: bool, video: bool, presentacion_vista: bool, video_visto: bool}  $requeridos
     */
    private function mensajeFaltaMaterial(OnboardingAvance $avance, array $requeridos): string
    {
        if (! $requeridos['presentacion']) {
            return 'Antes de terminar ve completo el video de la inducción.';
        }

        $total = $this->totalPaginas();
        $faltantes = $total < 1 ? [] : array_values(array_diff(range(1, $total), $this->paginasVistas($avance)));
        $prefijo = $requeridos['video'] ? 'Antes de terminar ve el video completo o termina la presentación' : 'Antes de terminar revisa la presentación';

        return $faltantes === []
            ? $prefijo.'.'
            : str_replace('Primero termina la presentación', $prefijo, $this->mensajePresentacionIncompleta($faltantes));
    }

    /**
     * @throws AuthorizationException
     */
    private function exigirTitular(OnboardingAvance $avance, User $usuario): void
    {
        $avance->loadMissing('proceso', 'modulo');

        if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $avance->proceso->colaborador_id) {
            throw new AuthorizationException('Solo el colaborador titular registra su propio avance de inducción.');
        }

        if ($avance->modulo->evaluacion_formal_clave === null) {
            throw ValidationException::withMessages(['induccion' => 'Este módulo no es la inducción institucional.']);
        }

        // Solo mientras su inducción esté asignada y por ver.
        $this->acceso->exigirContenidoVisible($usuario);
    }
}
