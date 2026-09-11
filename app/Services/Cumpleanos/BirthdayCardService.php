<?php

namespace App\Services\Cumpleanos;

use App\Models\BirthdayGreeting;
use App\Models\BirthdayPhrase;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Carbon\CarbonInterface;
use GdImage;
use Illuminate\Support\Facades\Log;
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

    /**
     * Aplica una frase elegida a mano (desde el selector de
     * Felicitacion.vue) a la felicitación existente del día, en vez de la
     * rotación automática de elegirFrase() — y re-renderiza+guarda la
     * imagen con esa frase. $fraseId es opcional: permite vincular al
     * catálogo (para las métricas de uso) cuando la frase elegida viene de
     * ahí, o quedar null si en el futuro se permite texto libre.
     */
    public function aplicarFrase(User $colaborador, CarbonInterface $fecha, string $frase, ?int $fraseId): BirthdayGreeting
    {
        $greeting = $this->generar($colaborador, $fecha);

        $greeting->update([
            'birthday_phrase_id' => $fraseId,
            'frase' => $frase,
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

    /**
     * Genera la tarjeta completa: logo arriba, fondo claro, globos, foto
     * circular si el colaborador tiene una (la tarjeta se ve bien sin ella),
     * título, nombre, sucursal opcional, frase y firma. Tamaño configurable
     * vía cumpleanos.card_width / cumpleanos.card_height (1080x1350 por
     * defecto).
     */
    private function renderPng(User $colaborador, string $frase): string
    {
        $ancho = max(1, (int) config('cumpleanos.card_width', 1080));
        $alto = max(1, (int) config('cumpleanos.card_height', 1350));

        $imagen = imagecreatetruecolor($ancho, $alto);
        imagesavealpha($imagen, true);
        imagealphablending($imagen, true);

        if (! $this->dibujarFondoPersonalizado($imagen, $ancho, $alto)) {
            [$r, $g, $b] = $this->hexARgb((string) config('cumpleanos.default_background', '#FFF8E7'));
            $fondo = $this->colorRgb($imagen, $r, $g, $b);
            imagefilledrectangle($imagen, 0, 0, $ancho, $alto, $fondo);

            $this->dibujarGlobos($imagen, $ancho, $alto);
        }

        $logoAlto = $this->dibujarLogo($imagen, $ancho);

        $fuenteBold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $fuenteRegular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $marron = $this->colorRgb($imagen, 74, 52, 30);
        $dorado = $this->colorRgb($imagen, 196, 148, 46);

        // Margen lateral generoso para que el texto envuelva (nombres y
        // frases largas) sin acercarse nunca al borde ni cortarse.
        $margenTexto = (int) ($ancho * 0.13);
        $anchoMaximoTexto = $ancho - ($margenTexto * 2);
        $yFirma = $alto - 80;

        $y = max($logoAlto + 70, (int) ($alto * 0.24));

        if ((bool) config('cumpleanos.show_employee_photo') && $colaborador->foto_path !== null) {
            $radioFoto = (int) ($ancho * 0.20);
            $fotoDibujada = $this->dibujarFotoCircular($imagen, $colaborador, (int) ($ancho / 2), $y + $radioFoto, $radioFoto, $dorado);

            if ($fotoDibujada) {
                $y += ($radioFoto * 2) + 60;
            }
        }

        $y += 20;
        $this->textoCentrado($imagen, $fuenteBold, 42, $dorado, $ancho, $y, '¡FELIZ CUMPLEAÑOS!');
        $y += 66;

        $nombre = mb_strtoupper($colaborador->nombreCompleto());
        $y = $this->textoParrafo(
            $imagen, $fuenteBold, 48, $marron, $ancho, $y, $nombre, $anchoMaximoTexto,
            interlineado: 56,
            altoMaximo: (int) ($alto * 0.20),
        );

        if ((bool) config('cumpleanos.show_branch') && $colaborador->sucursalPrincipal !== null) {
            $y += 46;
            $this->textoCentrado($imagen, $fuenteRegular, 26, $marron, $ancho, $y, $colaborador->sucursalPrincipal->nombre);
        }

        $y += 70;
        $alturaDisponibleFrase = max(90, $yFirma - 60 - $y);
        $this->textoParrafo(
            $imagen, $fuenteRegular, 30, $marron, $ancho, $y, $frase, $anchoMaximoTexto,
            interlineado: 46,
            altoMaximo: $alturaDisponibleFrase,
        );

        $this->textoCentrado($imagen, $fuenteBold, 30, $dorado, $ancho, $yFirma, 'MR. LANA');

        ob_start();
        imagepng($imagen);
        $contenido = (string) ob_get_clean();
        imagedestroy($imagen);

        return $contenido;
    }

    /**
     * Dibuja el logo centrado si existe y es una imagen PNG válida.
     * Nunca lanza excepción: si el archivo no existe o no se puede leer
     * simplemente no se dibuja nada y la tarjeta sigue generándose.
     *
     * @return int Alto (en px, desde el borde superior) ocupado por el
     *             logo, para que el resto del layout no se le encime.
     *             0 si no hay logo.
     */
    private function dibujarLogo(GdImage $imagen, int $ancho): int
    {
        $ruta = public_path('images/logoLetras.png');

        if (! is_file($ruta)) {
            return 0;
        }

        $logo = @imagecreatefrompng($ruta);

        if ($logo === false) {
            return 0;
        }

        $logoAncho = imagesx($logo);
        $logoAlto = imagesy($logo);

        $margenSuperior = 60;
        $destinoAncho = (int) ($ancho * 0.45);
        $destinoAlto = (int) ($logoAlto * ($destinoAncho / $logoAncho));

        imagesavealpha($logo, true);
        imagealphablending($imagen, true);
        imagecopyresampled(
            $imagen, $logo,
            (int) (($ancho - $destinoAncho) / 2), $margenSuperior,
            0, 0,
            $destinoAncho, $destinoAlto,
            $logoAncho, $logoAlto,
        );

        imagedestroy($logo);

        return $margenSuperior + $destinoAlto;
    }

    /**
     * Si RH subió un fondo personalizado (Rh\CumpleanosConfiguracionController,
     * guardado vía CumpleanosStorageService::rutaFondo()), lo dibuja como
     * capa base a pantalla completa ("cover": se recorta el sobrante en vez
     * de deformar la imagen) y regresa true. Si no hay fondo subido, o el
     * archivo no se puede leer/decodificar, no dibuja nada y regresa false
     * — el caller cae de vuelta al fondo plano + globos dibujados con GD.
     */
    private function dibujarFondoPersonalizado(GdImage $imagen, int $ancho, int $alto): bool
    {
        $ruta = $this->storage->rutaFondo();

        if (! $this->storage->existe($ruta)) {
            return false;
        }

        try {
            $bytes = $this->storage->disco()->get($ruta);
        } catch (\Throwable $e) {
            Log::warning('BirthdayCardService: no se pudo leer el fondo personalizado.', ['error' => $e->getMessage()]);

            return false;
        }

        $fondo = @imagecreatefromstring((string) $bytes);

        if ($fondo === false) {
            Log::warning('BirthdayCardService: el fondo personalizado no es una imagen válida.');

            return false;
        }

        $fondoAncho = imagesx($fondo);
        $fondoAlto = imagesy($fondo);

        $escala = max($ancho / $fondoAncho, $alto / $fondoAlto);
        $origenAncho = (int) round($ancho / $escala);
        $origenAlto = (int) round($alto / $escala);
        $origenX = (int) max(0, ($fondoAncho - $origenAncho) / 2);
        $origenY = (int) max(0, ($fondoAlto - $origenAlto) / 2);

        imagecopyresampled(
            $imagen, $fondo,
            0, 0,
            $origenX, $origenY,
            $ancho, $alto,
            min($origenAncho, $fondoAncho), min($origenAlto, $fondoAlto),
        );

        imagedestroy($fondo);

        return true;
    }

    /**
     * Recorta la foto del colaborador en un círculo real (transparencia
     * por-píxel fuera del radio, no una máscara rectangular aparte que
     * puede dejar un recuadro visible en los bordes) y opcionalmente le
     * dibuja un borde de color. Si algo falla al leer/decodificar la foto
     * se loggea un warning controlado y se regresa false: la tarjeta se
     * genera igual, sin foto.
     */
    private function dibujarFotoCircular(
        GdImage $imagen,
        User $colaborador,
        int $centroX,
        int $centroY,
        int $radio,
        ?int $colorBorde = null,
    ): bool {
        if ($colaborador->foto_path === null) {
            return false;
        }

        try {
            $bytes = $this->fotos->disco()->get($colaborador->foto_path);
        } catch (\Throwable $e) {
            Log::warning('BirthdayCardService: no se pudo leer la foto del colaborador para la tarjeta.', [
                'user_id' => $colaborador->id,
                'foto_path' => $colaborador->foto_path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $foto = @imagecreatefromstring((string) $bytes);

        if ($foto === false) {
            Log::warning('BirthdayCardService: la foto del colaborador no es una imagen válida.', [
                'user_id' => $colaborador->id,
                'foto_path' => $colaborador->foto_path,
            ]);

            return false;
        }

        $fotoAncho = imagesx($foto);
        $fotoAlto = imagesy($foto);

        $diametro = max(1, $radio * 2);

        $recorte = imagecreatetruecolor($diametro, $diametro);
        imagealphablending($recorte, false);
        imagesavealpha($recorte, true);
        $transparente = $this->colorTransparente($recorte);
        imagefill($recorte, 0, 0, $transparente);

        // "Cover": se reescala la foto para cubrir el círculo completo
        // (recortando el sobrante) en vez de deformarla al diámetro.
        $escala = max($diametro / $fotoAncho, $diametro / $fotoAlto);
        $origenAncho = (int) round($diametro / $escala);
        $origenAlto = (int) round($diametro / $escala);
        $origenX = (int) max(0, ($fotoAncho - $origenAncho) / 2);
        $origenY = (int) max(0, ($fotoAlto - $origenAlto) / 2);

        imagecopyresampled(
            $recorte, $foto,
            0, 0,
            $origenX, $origenY,
            $diametro, $diametro,
            min($origenAncho, $fotoAncho), min($origenAlto, $fotoAlto),
        );

        imagedestroy($foto);

        // Recorte circular real: cualquier píxel fuera del radio se vuelve
        // completamente transparente, píxel por píxel.
        $centro = $radio;
        $radioCuadrado = $radio * $radio;

        for ($px = 0; $px < $diametro; $px++) {
            $dx = $px - $centro;
            $dxCuadrado = $dx * $dx;

            for ($py = 0; $py < $diametro; $py++) {
                $dy = $py - $centro;

                if (($dxCuadrado + ($dy * $dy)) > $radioCuadrado) {
                    imagesetpixel($recorte, $px, $py, $transparente);
                }
            }
        }

        imagealphablending($imagen, true);
        imagecopy($imagen, $recorte, $centroX - $radio, $centroY - $radio, 0, 0, $diametro, $diametro);
        imagedestroy($recorte);

        if ($colorBorde !== null) {
            imagesetthickness($imagen, 4);
            imageellipse($imagen, $centroX, $centroY, $diametro - 2, $diametro - 2, $colorBorde);
            imagesetthickness($imagen, 1);
        }

        return true;
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
        $anchoTexto = $this->anchoTexto($fuente, $tamano, $texto);
        $x = (int) (($ancho - $anchoTexto) / 2);

        imagettftext($imagen, $tamano, 0, $x, $y, $color, $fuente, $texto);
    }

    /**
     * Dibuja un párrafo centrado, partiendo el texto en líneas que respetan
     * $anchoMaximo (soporta nombres y frases largas sin cortar contenido).
     * Si $altoMaximo se indica y el párrafo no cabe, la tipografía se
     * reduce de forma proporcional (nunca se trunca el texto) hasta que
     * quepa o se llegue a $tamanoMinimo.
     *
     * @return int La Y final, justo debajo de la última línea dibujada.
     */
    private function textoParrafo(
        GdImage $imagen,
        string $fuente,
        int $tamano,
        int $color,
        int $ancho,
        int $y,
        string $texto,
        int $anchoMaximo,
        int $interlineado,
        ?int $altoMaximo = null,
        int $tamanoMinimo = 18,
    ): int {
        $tamanoActual = $tamano;
        $interlineadoActual = $interlineado;
        $lineas = $this->partirLineas($fuente, $tamanoActual, $texto, $anchoMaximo);

        while ($altoMaximo !== null && $tamanoActual > $tamanoMinimo) {
            $alturaTotal = count($lineas) * $interlineadoActual;

            if ($alturaTotal <= $altoMaximo) {
                break;
            }

            $tamanoAnterior = $tamanoActual;
            $tamanoActual = max($tamanoMinimo, $tamanoActual - 2);
            $interlineadoActual = max(
                $tamanoActual + 8,
                (int) round($interlineadoActual * ($tamanoActual / $tamanoAnterior)),
            );
            $lineas = $this->partirLineas($fuente, $tamanoActual, $texto, $anchoMaximo);
        }

        foreach ($lineas as $indice => $linea) {
            $this->textoCentrado($imagen, $fuente, $tamanoActual, $color, $ancho, $y + ($indice * $interlineadoActual), $linea);
        }

        return $y + (count($lineas) * $interlineadoActual);
    }

    /**
     * @return list<string>
     */
    private function partirLineas(string $fuente, int $tamano, string $texto, int $anchoMaximo): array
    {
        $palabras = preg_split('/\s+/', trim($texto)) ?: [];
        $lineas = [];
        $lineaActual = '';

        foreach ($palabras as $palabra) {
            if ($palabra === '') {
                continue;
            }

            $intento = $lineaActual === '' ? $palabra : "{$lineaActual} {$palabra}";

            if ($lineaActual !== '' && $this->anchoTexto($fuente, $tamano, $intento) > $anchoMaximo) {
                $lineas[] = $lineaActual;
                $lineaActual = $palabra;
            } else {
                $lineaActual = $intento;
            }
        }

        if ($lineaActual !== '') {
            $lineas[] = $lineaActual;
        }

        return $lineas === [] ? [''] : $lineas;
    }

    /**
     * Centraliza imagettfbbox() (que puede regresar false) para no repetir
     * el manejo de ese caso en cada llamador.
     */
    private function anchoTexto(string $fuente, int $tamano, string $texto): int
    {
        $caja = @imagettfbbox($tamano, 0, $fuente, $texto);

        return $caja === false ? 0 : (int) abs($caja[4] - $caja[0]);
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
