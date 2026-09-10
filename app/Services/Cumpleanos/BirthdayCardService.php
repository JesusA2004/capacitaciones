<?php

namespace App\Services\Cumpleanos;

use App\Models\BirthdayGreeting;
use App\Models\BirthdayPhrase;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Carbon\CarbonInterface;
use GdImage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dueño de la creación de BirthdayGreeting y de renderizar la tarjeta
 * (imagen PNG) con GD. Idempotente por diseño: generar() nunca cambia la
 * frase ni la imagen de una felicitación que ya existe para ese
 * colaborador/fecha (ver unique en la tabla) — para forzar un cambio hay que
 * llamar regenerar() explícitamente. Ver docs/CUMPLEANOS.md.
 */
class BirthdayCardService
{
    public function __construct(
        private readonly CumpleanosStorageService $storage,
        private readonly DocumentoStorageService $fotos,
    ) {}

    public function generar(User $colaborador, CarbonInterface $fecha): BirthdayGreeting
    {
        $greeting = BirthdayGreeting::query()
            ->where('user_id', $colaborador->id)
            ->whereDate('fecha', $fecha->toDateString())
            ->first();

        if ($greeting !== null) {
            if ($greeting->card_path === null && (bool) config('cumpleanos.auto_generate_cards')) {
                $this->renderizarYGuardar($greeting, $colaborador);
            }

            return $greeting;
        }

        $frase = $this->elegirFrase();

        $greeting = BirthdayGreeting::create([
            'user_id' => $colaborador->id,
            'birthday_phrase_id' => $frase !== null ? $frase->id : null,
            'fecha' => $fecha->toDateString(),
            'nombre_mostrado' => $colaborador->nombreCompleto(),
            'frase' => $frase !== null ? $frase->texto : 'Feliz cumpleaños. Gracias por ser parte de MR. LANA.',
            'auto_generada' => true,
        ]);

        if ((bool) config('cumpleanos.auto_generate_cards')) {
            $this->renderizarYGuardar($greeting, $colaborador);
        }

        return $greeting;
    }

    /**
     * Fuerza una nueva frase y re-renderiza la imagen, incluso si ya existía
     * una felicitación para ese día (usado por "regenerar" en el panel RH).
     */
    public function regenerar(User $colaborador, CarbonInterface $fecha): BirthdayGreeting
    {
        $greeting = $this->generar($colaborador, $fecha);

        $frase = $this->elegirFrase();

        $greeting->update([
            'birthday_phrase_id' => $frase !== null ? $frase->id : null,
            'frase' => $frase !== null ? $frase->texto : $greeting->frase,
            'nombre_mostrado' => $colaborador->nombreCompleto(),
        ]);

        $this->renderizarYGuardar($greeting->fresh(), $colaborador, forzar: true);

        return $greeting->fresh();
    }

    public function descargar(BirthdayGreeting $greeting): StreamedResponse
    {
        if ($greeting->card_path === null || ! $this->storage->existe($greeting->card_path)) {
            $this->renderizarYGuardar($greeting, $greeting->colaborador, forzar: true);
        }

        return $this->storage->respuesta($greeting->card_path, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->nombreArchivo($greeting).'"',
        ]);
    }

    /**
     * Bytes PNG de una vista previa sin tocar base de datos ni storage.
     * Reutiliza el mismo render que generar()/regenerar().
     */
    public function preview(User $colaborador, string $frase): string
    {
        return $this->renderPng($colaborador, $frase);
    }

    private function renderizarYGuardar(BirthdayGreeting $greeting, User $colaborador, bool $forzar = false): void
    {
        if ($greeting->card_path !== null && ! $forzar && $this->storage->existe($greeting->card_path)) {
            return;
        }

        if ($greeting->card_path !== null) {
            $this->storage->eliminar($greeting->card_path);
        }

        $png = $this->renderPng($colaborador, $greeting->frase);
        $ruta = $this->storage->rutaTarjeta($colaborador->id, $greeting->fecha->toDateString());
        $this->storage->guardar($ruta, $png);

        $greeting->update(['card_path' => $ruta]);
    }

    private function elegirFrase(): ?BirthdayPhrase
    {
        /** @var BirthdayPhrase|null $frase */
        $frase = BirthdayPhrase::query()
            ->activas()
            ->orderByRaw('ultimo_uso_at IS NOT NULL, ultimo_uso_at ASC')
            ->orderBy('usado_count')
            ->orderBy('orden')
            ->first();

        if ($frase === null) {
            return null;
        }

        $frase->update([
            'usado_count' => $frase->usado_count + 1,
            'ultimo_uso_at' => now(),
        ]);

        return $frase;
    }

    private function nombreArchivo(BirthdayGreeting $greeting): string
    {
        $slug = Str::slug($greeting->nombre_mostrado) ?: 'colaborador';

        return "feliz-cumpleanos-{$slug}-{$greeting->fecha->format('Y')}.png";
    }

    private function renderPng(User $colaborador, string $frase): string
    {
        $ancho = max(1, (int) config('cumpleanos.card_width', 1080));
        $alto = max(1, (int) config('cumpleanos.card_height', 1350));

        $imagen = imagecreatetruecolor($ancho, $alto);
        imagesavealpha($imagen, true);
        imagealphablending($imagen, true);

        [$r, $g, $b] = $this->hexARgb((string) config('cumpleanos.default_background', '#FFF8E7'));
        $fondo = $this->colorRgb($imagen, $r, $g, $b);
        imagefilledrectangle($imagen, 0, 0, $ancho, $alto, $fondo);

        $this->dibujarGlobos($imagen, $ancho, $alto);
        $this->dibujarLogo($imagen, $ancho);

        $fuenteBold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $fuenteRegular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $marron = $this->colorRgb($imagen, 74, 52, 30);
        $dorado = $this->colorRgb($imagen, 196, 148, 46);

        $centroY = (int) ($alto * 0.40);

        if ((bool) config('cumpleanos.show_employee_photo') && $colaborador->foto_path !== null) {
            $this->dibujarFotoCircular($imagen, $colaborador, (int) ($ancho / 2), $centroY, (int) ($ancho * 0.28));
            $yNombre = $centroY + (int) ($ancho * 0.28) + 90;
        } else {
            $yNombre = $centroY;
        }

        $this->textoCentrado($imagen, $fuenteBold, 54, $dorado, $ancho, $yNombre, '¡FELIZ CUMPLEAÑOS!');

        $nombre = mb_strtoupper($colaborador->nombreCompleto());
        $this->textoCentrado($imagen, $fuenteBold, 46, $marron, $ancho, $yNombre + 80, $nombre);

        if ((bool) config('cumpleanos.show_branch') && $colaborador->sucursalPrincipal) {
            $this->textoCentrado($imagen, $fuenteRegular, 28, $marron, $ancho, $yNombre + 140, $colaborador->sucursalPrincipal->nombre);
        }

        $this->textoParrafo($imagen, $fuenteRegular, 30, $marron, $ancho, $yNombre + 220, $frase, $ancho - 220);

        $this->textoCentrado($imagen, $fuenteBold, 32, $dorado, $ancho, $alto - 90, 'MR. LANA');

        ob_start();
        imagepng($imagen);
        $contenido = (string) ob_get_clean();
        imagedestroy($imagen);

        return $contenido;
    }

    private function dibujarLogo(GdImage $imagen, int $ancho): void
    {
        $ruta = public_path('images/logoLetras.png');

        if (! is_file($ruta)) {
            return;
        }

        $logo = @imagecreatefrompng($ruta);

        if ($logo === false) {
            return;
        }

        $logoAncho = imagesx($logo);
        $logoAlto = imagesy($logo);

        $destinoAncho = (int) ($ancho * 0.45);
        $destinoAlto = (int) ($logoAlto * ($destinoAncho / $logoAncho));

        imagesavealpha($logo, true);
        imagealphablending($imagen, true);
        imagecopyresampled(
            $imagen, $logo,
            (int) (($ancho - $destinoAncho) / 2), 60,
            0, 0,
            $destinoAncho, $destinoAlto,
            $logoAncho, $logoAlto,
        );

        imagedestroy($logo);
    }

    private function dibujarFotoCircular(GdImage $imagen, User $colaborador, int $centroX, int $centroY, int $radio): void
    {
        try {
            $bytes = $this->fotos->disco()->get($colaborador->foto_path);
        } catch (\Throwable) {
            return;
        }

        $foto = @imagecreatefromstring($bytes);

        if ($foto === false) {
            return;
        }

        $diametro = max(1, $radio * 2);
        $mascara = imagecreatetruecolor($diametro, $diametro);
        imagealphablending($mascara, false);
        $transparente = $this->colorTransparente($mascara);
        imagefill($mascara, 0, 0, $transparente);
        imagesavealpha($mascara, true);

        $blanco = $this->colorRgb($mascara, 255, 255, 255);
        imagefilledellipse($mascara, $radio, $radio, $diametro, $diametro, $blanco);

        $recorte = imagecreatetruecolor($diametro, $diametro);
        imagealphablending($recorte, false);
        imagesavealpha($recorte, true);
        imagefill($recorte, 0, 0, $transparente);

        imagecopyresampled($recorte, $foto, 0, 0, 0, 0, $diametro, $diametro, imagesx($foto), imagesy($foto));
        imagecopymerge($recorte, $mascara, 0, 0, 0, 0, $diametro, $diametro, 100);

        imagecopy($imagen, $recorte, $centroX - $radio, $centroY - $radio, 0, 0, $diametro, $diametro);

        imagedestroy($foto);
        imagedestroy($mascara);
        imagedestroy($recorte);
    }

    private function dibujarGlobos(GdImage $imagen, int $ancho, int $alto): void
    {
        $colores = [
            $this->colorRgb($imagen, 244, 178, 187),
            $this->colorRgb($imagen, 168, 213, 186),
            $this->colorRgb($imagen, 247, 214, 157),
            $this->colorRgb($imagen, 179, 196, 232),
        ];

        $posiciones = [
            [0.10, 0.12, 0.07], [0.90, 0.10, 0.06], [0.05, 0.85, 0.06],
            [0.93, 0.88, 0.07], [0.18, 0.05, 0.045], [0.82, 0.06, 0.05],
        ];

        foreach ($posiciones as $i => [$px, $py, $pr]) {
            $cx = (int) ($ancho * $px);
            $cy = (int) ($alto * $py);
            $r = (int) ($ancho * $pr);
            $color = $colores[$i % count($colores)];

            imagefilledellipse($imagen, $cx, $cy, $r * 2, (int) ($r * 2.3), $color);
            imageline($imagen, $cx, $cy + $r, $cx, $cy + $r + 60, $color);
        }
    }

    private function textoCentrado(GdImage $imagen, string $fuente, int $tamano, int $color, int $ancho, int $y, string $texto): void
    {
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);
        $anchoTexto = $caja === false ? 0 : abs($caja[4] - $caja[0]);
        $x = (int) (($ancho - $anchoTexto) / 2);

        imagettftext($imagen, $tamano, 0, $x, $y, $color, $fuente, $texto);
    }

    private function textoParrafo(GdImage $imagen, string $fuente, int $tamano, int $color, int $ancho, int $y, string $texto, int $anchoMaximo): void
    {
        $palabras = explode(' ', $texto);
        $lineas = [];
        $lineaActual = '';

        foreach ($palabras as $palabra) {
            $intento = trim("{$lineaActual} {$palabra}");
            $caja = imagettfbbox($tamano, 0, $fuente, $intento);
            $anchoIntento = $caja === false ? 0 : abs($caja[4] - $caja[0]);

            if ($anchoIntento > $anchoMaximo && $lineaActual !== '') {
                $lineas[] = $lineaActual;
                $lineaActual = $palabra;
            } else {
                $lineaActual = $intento;
            }
        }

        if ($lineaActual !== '') {
            $lineas[] = $lineaActual;
        }

        foreach ($lineas as $indice => $linea) {
            $this->textoCentrado($imagen, $fuente, $tamano, $color, $ancho, $y + ($indice * ($tamano + 18)), $linea);
        }
    }

    /**
     * imagecolorallocate() devuelve int|false segun sus stubs (falla solo si
     * la paleta de una imagen no-truecolor se agota, algo que no aplica a
     * las imagenes truecolor que usa este service); se centraliza aqui el
     * manejo de ese caso y el clamp 0-255 de cada canal.
     */
    private function colorRgb(GdImage $imagen, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($imagen, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));

        return $color === false ? 0 : $color;
    }

    private function colorTransparente(GdImage $imagen): int
    {
        $color = imagecolorallocatealpha($imagen, 0, 0, 0, 127);

        return $color === false ? 0 : $color;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return [255, 248, 231];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
