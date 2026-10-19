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
     * @return array{url: string, titulo: string, total_paginas: int}
     */
    public function datosVisor(bool $api = false): array
    {
        return [
            'url' => $api ? route('api.v1.induccion.pdf') : route('induccion.pdf'),
            'titulo' => (string) config('induccion.titulo'),
            'total_paginas' => $this->totalPaginas(),
        ];
    }

    /**
     * Solo RH que presenta o el colaborador DURANTE la sesión que lo incluye
     * (AccesoInduccionColaboradorService).
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

        if ($total < 1 || $vistas < $total) {
            throw ValidationException::withMessages(['induccion' => sprintf('Te faltan %d página(s) por revisar antes de terminar.', max(1, $total - $vistas))]);
        }

        // El video de explicación (si el material activo es video) es
        // obligatorio: la evaluación queda bloqueada hasta verlo completo.
        $material = $this->materiales->materialActivoDeAvance($avance);

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
