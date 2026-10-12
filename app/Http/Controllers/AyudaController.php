<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Centro de Guía / Ayuda de MR. LANA PEOPLE. El contenido vive en
 * resources/js/lib/guia (la misma fuente genera docs/guias/*.md): aquí solo
 * se arma cada pantalla y se informa qué recursos multimedia reales existen
 * (capturas del sistema y el video), para no mostrar nunca una imagen o un
 * video que no está publicado.
 */
class AyudaController extends Controller
{
    /** Carpeta pública de las capturas reales (docs/guias/video/CAPTURAS_NECESARIAS.md). */
    private const CAPTURAS = 'guia/capturas';

    /**
     * Archivos del video que se sirven desde docs/guias/video (una sola
     * copia en el repositorio; solo con sesión iniciada). Clave = nombre
     * público, valor = archivo y tipo.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ARCHIVOS_VIDEO = [
        'guia-completa.mp4' => ['MR_LANA_PEOPLE_GUIA_COMPLETA.mp4', 'video/mp4'],
        'subtitulos.vtt' => ['subtitulos.vtt', 'text/vtt'],
        'miniatura.png' => ['miniatura.png', 'image/png'],
    ];

    public function index(): Response
    {
        return Inertia::render('Ayuda/Index', [
            'video' => $this->datosVideo(),
        ]);
    }

    public function proceso(string $proceso): Response
    {
        return Inertia::render('Ayuda/Proceso', ['proceso' => $proceso]);
    }

    public function articulo(string $articulo): Response
    {
        return Inertia::render('Ayuda/Articulo', [
            'articulo' => $articulo,
            'capturas' => $this->capturasDe($articulo),
        ]);
    }

    public function rol(string $rol): Response
    {
        return Inertia::render('Ayuda/Rol', ['rol' => $rol]);
    }

    public function video(): Response
    {
        return Inertia::render('Ayuda/Video', ['video' => $this->datosVideo(), 'cortos' => $this->cortos()]);
    }

    public function glosario(): Response
    {
        return Inertia::render('Ayuda/Glosario');
    }

    public function preguntas(): Response
    {
        return Inertia::render('Ayuda/Preguntas');
    }

    /**
     * Capturas reales publicadas para un artículo: `{articulo}.webp|png`,
     * `{articulo}-2.webp`… en public/guia/capturas. Sin archivo, nada.
     *
     * @return list<string>
     */
    private function capturasDe(string $articulo): array
    {
        $carpeta = public_path(self::CAPTURAS);

        if (! File::isDirectory($carpeta)) {
            return [];
        }

        return array_values(collect(File::files($carpeta))
            ->map(fn (\SplFileInfo $archivo): string => $archivo->getFilename())
            ->filter(fn (string $nombre): bool => preg_match('/^'.preg_quote($articulo, '/').'(-\d+)?\.(webp|png|jpe?g)$/', $nombre) === 1)
            ->sort(SORT_NATURAL)
            ->map(fn (string $nombre): string => asset(self::CAPTURAS.'/'.$nombre))
            ->all());
    }

    /**
     * Video, subtítulos o miniatura del tutorial. BinaryFileResponse atiende
     * peticiones por rango (adelantar/atrasar en el reproductor).
     */
    public function archivoVideo(string $archivo): BinaryFileResponse
    {
        // Videos cortos: corto-{id}.mp4 → docs/guias/video/cortos/{id}.mp4.
        if (preg_match('/^corto-([a-z-]+)\.mp4$/', $archivo, $m) === 1) {
            [$nombre, $tipo] = [sprintf('cortos/%s.mp4', $m[1]), 'video/mp4'];
        } else {
            abort_unless(isset(self::ARCHIVOS_VIDEO[$archivo]), 404);
            [$nombre, $tipo] = self::ARCHIVOS_VIDEO[$archivo];
        }

        $ruta = $this->rutaVideo($nombre);

        abort_unless(File::exists($ruta), 404);

        $respuesta = response()->file($ruta, ['Content-Type' => $tipo]);
        // Solo con sesión: nunca en cachés compartidas.
        $respuesta->setPrivate();
        $respuesta->setMaxAge(86400);

        return $respuesta;
    }

    private function rutaVideo(string $nombre): string
    {
        return base_path('docs/guias/video/'.$nombre);
    }

    /**
     * Videos cortos ya producidos: id => URL.
     *
     * @return array<string, string>
     */
    private function cortos(): array
    {
        $carpeta = $this->rutaVideo('cortos');

        if (! File::isDirectory($carpeta)) {
            return [];
        }

        return collect(File::files($carpeta))
            ->filter(fn (\SplFileInfo $f): bool => preg_match('/^[a-z-]+\.mp4$/', $f->getFilename()) === 1)
            ->mapWithKeys(fn (\SplFileInfo $f): array => [
                $f->getBasename('.mp4') => route('ayuda.video.archivo', sprintf('corto-%s.mp4', $f->getBasename('.mp4'))),
            ])
            ->all();
    }

    /**
     * @return array{url: string, subtitulos: string|null, miniatura: string|null}|null
     */
    private function datosVideo(): ?array
    {
        if (! File::exists($this->rutaVideo('MR_LANA_PEOPLE_GUIA_COMPLETA.mp4'))) {
            return null;
        }

        return [
            'url' => route('ayuda.video.archivo', 'guia-completa.mp4'),
            'subtitulos' => File::exists($this->rutaVideo('subtitulos.vtt')) ? route('ayuda.video.archivo', 'subtitulos.vtt') : null,
            'miniatura' => File::exists($this->rutaVideo('miniatura.png')) ? route('ayuda.video.archivo', 'miniatura.png') : null,
        ];
    }
}
