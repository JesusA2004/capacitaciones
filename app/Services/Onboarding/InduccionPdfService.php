<?php

namespace App\Services\Onboarding;

use App\Exceptions\ArchivoGeneradoNoDisponibleException;
use App\Models\OnboardingAvance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
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
 * «Terminé la inducción» (terminar()) es la ÚNICA fuente del gate que marca
 * contenido_visto_en: exige que se recorrieran TODAS las hojas y, si el
 * material activo del módulo es un VIDEO, que el video se haya visto completo
 * (ReproduccionVideoInduccionService). La evaluación formal se habilita cuando
 * ese gate queda cumplido (EvaluacionInduccionService::habilitarPara()).
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
     * ORDEN OBLIGATORIO de la inducción: presentación completa → video →
     * «Terminé». Páginas de la presentación que aún faltan para poder abrir
     * el VIDEO (vacío = el video se puede ver). Nunca se bloquea
     * retroactivamente: si ya empezó o terminó el video, si ya marcó la
     * inducción como vista, si la presentación no se le asignó o si el PDF
     * no se pudo leer, no hay bloqueo.
     *
     * @return list<int>
     */
    public function paginasFaltantesParaVideo(OnboardingAvance $avance): array
    {
        if ($avance->contenido_visto_en !== null
            || ! $this->acceso->contenidoAsignado($avance)['presentacion']
            || $this->video->videoIniciado($avance)
            || $this->video->videoCompletado($avance)) {
            return [];
        }

        $total = $this->totalPaginas();

        if ($total < 1) {
            return [];
        }

        $vistas = array_map('intval', (array) (((array) ($avance->progreso_contenido ?? []))['paginas_vistas'] ?? []));

        return array_values(array_diff(range(1, $total), $vistas));
    }

    /**
     * Datos aditivos para el material del colaborador (web y app):
     * `video_bloqueado`, `paginas_faltantes` y el mensaje para la persona.
     *
     * @return array{video_bloqueado: bool, paginas_faltantes: list<int>, video_bloqueado_mensaje: string|null}
     */
    public function bloqueoVideo(OnboardingAvance $avance): array
    {
        $faltantes = $this->paginasFaltantesParaVideo($avance);

        return [
            'video_bloqueado' => $faltantes !== [],
            'paginas_faltantes' => $faltantes,
            'video_bloqueado_mensaje' => $faltantes !== [] ? $this->mensajePresentacionIncompleta($faltantes) : null,
        ];
    }

    /**
     * 423 «presentacion_incompleta» al intentar abrir el video (iniciar o
     * manifiesto) antes de terminar la presentación. Mismo gate web y API.
     *
     * @throws HttpResponseException
     */
    public function exigirPresentacionParaVideo(OnboardingAvance $avance): void
    {
        $faltantes = $this->paginasFaltantesParaVideo($avance);

        if ($faltantes === []) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $this->mensajePresentacionIncompleta($faltantes),
            'code' => 'presentacion_incompleta',
            'codigo' => 'presentacion_incompleta',
            'paginas_faltantes' => $faltantes,
        ], 423));
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
     * «Terminé la inducción»: solo si el colaborador recorrió TODAS las
     * páginas Y —cuando el material activo del módulo es un VIDEO— vio ese
     * video completo. Marca el contenido visto (única fuente del gate que
     * habilita la evaluación formal).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function terminar(OnboardingAvance $avance, User $usuario): OnboardingAvance
    {
        $this->exigirTitular($avance, $usuario);
        $total = $this->totalPaginas();
        $vistas = count((array) (((array) ($avance->progreso_contenido ?? []))['paginas_vistas'] ?? []));
        // RH puede asignar solo la presentación o solo el material: lo que no
        // se le asignó no se le exige (AsignacionInduccionService).
        $asignado = $this->acceso->contenidoAsignado($avance);

        if ($asignado['presentacion'] && ($total < 1 || $vistas < $total)) {
            $faltantes = $total < 1 ? [] : array_values(array_diff(range(1, $total), array_map('intval', (array) (((array) ($avance->progreso_contenido ?? []))['paginas_vistas'] ?? []))));

            throw ValidationException::withMessages(['induccion' => $faltantes === []
                ? sprintf('Te faltan %d página(s) por revisar antes de terminar.', max(1, $total - $vistas))
                : str_replace('Primero termina la presentación', 'Antes de terminar revisa la presentación', $this->mensajePresentacionIncompleta($faltantes))]);
        }

        // El video de explicación (si el material activo es video y se le
        // asignó) es obligatorio: la evaluación queda bloqueada hasta verlo
        // completo.
        $material = $asignado['material'] ? $this->materiales->materialActivoDeAvance($avance) : null;

        if ($material !== null && $material->tipo->bloqueaEvaluacion() && ! $this->video->videoCompletado($avance)) {
            throw ValidationException::withMessages(['induccion' => 'Primero ve completo el video de la inducción antes de terminar.']);
        }

        if ($avance->contenido_visto_en === null) {
            $avance->update(['contenido_visto_en' => now()]);
        }

        return $avance->refresh();
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
