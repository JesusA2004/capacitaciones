<?php

namespace App\Services\Cumpleanos;

use App\Enums\TipoCelebracion;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayPhrase;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\Celebraciones\DibujoTarjeta;
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
    use DibujoTarjeta;

    public function __construct(
        private readonly CumpleanosStorageService $storage,
        private readonly DocumentoStorageService $fotos,
    ) {}

    public function generar(Colaborador $colaborador, CarbonInterface $fecha): BirthdayGreeting
    {
        $greeting = BirthdayGreeting::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', TipoCelebracion::Cumpleanos->value)
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
            'colaborador_id' => $colaborador->id,
            'user_id' => $colaborador->user?->id,
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
    public function regenerar(Colaborador $colaborador, CarbonInterface $fecha): BirthdayGreeting
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
    public function aplicarFrase(Colaborador $colaborador, CarbonInterface $fecha, string $frase, ?int $fraseId): BirthdayGreeting
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

    public function descargar(BirthdayGreeting $greeting, bool $enLinea = false): StreamedResponse
    {
        if ($greeting->card_path === null || ! $this->storage->existe($greeting->card_path)) {
            $this->renderizarYGuardar($greeting, $greeting->colaborador, forzar: true);
        }

        return $this->storage->respuesta($greeting->card_path, [
            'Content-Type' => 'image/png',
            // `inline` para la vista previa del panel RH (Ver tarjeta).
            'Content-Disposition' => sprintf('%s; filename="%s"', $enLinea ? 'inline' : 'attachment', $this->nombreArchivo($greeting)),
            // Sin esto, el navegador cachea la imagen por heurística (no
            // hay ningún header de caché) y, como la URL nunca cambia
            // (mismo colaborador = misma ruta), regenerar/cambiar la frase
            // seguía mostrando la tarjeta vieja hasta un F5. El querystring
            // `v=` en 'imagenUrl' (ver CumpleanosController::felicitacion())
            // ya fuerza una URL distinta en cada cambio; esto es la segunda
            // capa, por si algo la sirve sin ese parámetro.
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }

    /**
     * Bytes PNG de una vista previa sin tocar base de datos ni storage.
     * Reutiliza el mismo render que generar()/regenerar().
     */
    public function preview(Colaborador $colaborador, string $frase): string
    {
        return $this->renderPng($colaborador, $frase);
    }

    /**
     * Vista previa con datos de EJEMPLO para la pantalla de configuración
     * (fondo + frase activa más reciente): nombre largo a propósito, para
     * que RH vea cómo se acomoda. No guarda nada.
     */
    public function previewEjemplo(): string
    {
        $colaborador = (new Colaborador)->forceFill(['name' => 'María Fernanda', 'apellidos' => 'Hernández Rodríguez', 'foto_path' => null]);
        $colaborador->setRelation('sucursalPrincipal', (new Sucursal)->forceFill(['nombre' => 'Sucursal de ejemplo']));

        $frase = BirthdayPhrase::query()->activas()->orderBy('orden')->value('texto');

        return $this->renderPng($colaborador, is_string($frase) && $frase !== '' ? $frase : 'Feliz cumpleaños. Gracias por ser parte de MR. LANA.');
    }

    private function renderizarYGuardar(BirthdayGreeting $greeting, Colaborador $colaborador, bool $forzar = false): void
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
    private function renderPng(Colaborador $colaborador, string $frase): string
    {
        $ancho = max(1, (int) config('cumpleanos.card_width', 1080));
        $alto = max(1, (int) config('cumpleanos.card_height', 1350));

        $imagen = imagecreatetruecolor($ancho, $alto);
        imagesavealpha($imagen, true);
        imagealphablending($imagen, true);

        if (! $this->dibujarFondoPersonalizado($imagen, $ancho, $alto)) {
            [$r, $g, $b] = $this->hexARgb((string) config('cumpleanos.default_background', '#FFF8E7'));
            $this->dibujarFondoDegradado($imagen, $ancho, $alto, [$r, $g, $b]);
            $this->dibujarConfeti($imagen, $ancho, $alto);
            $this->dibujarGlobos($imagen, $ancho, $alto);
            $this->dibujarMarco($imagen, $ancho, $alto);
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
        $this->textoConSombra($imagen, $fuenteBold, 48, $dorado, $ancho, $y, '¡FELIZ CUMPLEAÑOS!');
        $y += 72;

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
        $ruta = $this->rutaAssetMarca('logoLetras.png');

        if ($ruta === null) {
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
        Colaborador $colaborador,
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
                'colaborador_id' => $colaborador->id,
                'foto_path' => $colaborador->foto_path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $foto = @imagecreatefromstring((string) $bytes);

        if ($foto === false) {
            Log::warning('BirthdayCardService: la foto del colaborador no es una imagen válida.', [
                'colaborador_id' => $colaborador->id,
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

    /**
     * Fondo con degradado vertical (del color base configurado hacia un
     * tono dorado más cálido en la parte inferior) en vez de un relleno
     * plano — le da profundidad a la tarjeta sin sacrificar el contraste
     * que necesita el texto marrón/dorado dibujado encima.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgbSuperior
     */
    private function dibujarFondoDegradado(GdImage $imagen, int $ancho, int $alto, array $rgbSuperior): void
    {
        [$r0, $g0, $b0] = $rgbSuperior;
        $r1 = (int) min(255, ($r0 * 0.92) + 40);
        $g1 = (int) min(255, ($g0 * 0.85) + 25);
        $b1 = (int) max(0, $b0 * 0.75);

        for ($fila = 0; $fila < $alto; $fila++) {
            $t = $fila / max(1, $alto - 1);
            $color = $this->colorRgb(
                $imagen,
                (int) round($r0 + ($r1 - $r0) * $t),
                (int) round($g0 + ($g1 - $g0) * $t),
                (int) round($b0 + ($b1 - $b0) * $t),
            );
            imagefilledrectangle($imagen, 0, $fila, $ancho, $fila, $color);
        }
    }

    /**
     * Marco decorativo doble (línea marrón exterior + línea dorada interior)
     * con acentos en las 4 esquinas, para un acabado más "tarjeta de
     * felicitación" y menos "captura de pantalla".
     */
    private function dibujarMarco(GdImage $imagen, int $ancho, int $alto): void
    {
        $dorado = $this->colorRgb($imagen, 196, 148, 46);
        $marron = $this->colorRgb($imagen, 74, 52, 30);

        $margenExterior = (int) ($ancho * 0.035);
        imagesetthickness($imagen, 3);
        imagerectangle($imagen, $margenExterior, $margenExterior, $ancho - $margenExterior, $alto - $margenExterior, $marron);

        $margenInterior = $margenExterior + 14;
        imagesetthickness($imagen, 2);
        imagerectangle($imagen, $margenInterior, $margenInterior, $ancho - $margenInterior, $alto - $margenInterior, $dorado);

        $longitudAcento = (int) ($ancho * 0.035);
        $esquinas = [
            [$margenInterior, $margenInterior, 1, 1],
            [$ancho - $margenInterior, $margenInterior, -1, 1],
            [$margenInterior, $alto - $margenInterior, 1, -1],
            [$ancho - $margenInterior, $alto - $margenInterior, -1, -1],
        ];

        imagesetthickness($imagen, 4);
        foreach ($esquinas as [$x, $y, $dx, $dy]) {
            imageline($imagen, $x, $y, $x + ($longitudAcento * $dx), $y, $dorado);
            imageline($imagen, $x, $y, $x, $y + ($longitudAcento * $dy), $dorado);
        }
        imagesetthickness($imagen, 1);
    }

    /**
     * Confeti disperso en los márgenes superior/inferior de la tarjeta
     * (evita la franja central donde va nombre/frase, para no estorbar la
     * lectura). Se suma a los globos existentes, no los reemplaza.
     */
    private function dibujarConfeti(GdImage $imagen, int $ancho, int $alto): void
    {
        $colores = [
            $this->colorRgb($imagen, 244, 178, 187),
            $this->colorRgb($imagen, 168, 213, 186),
            $this->colorRgb($imagen, 247, 214, 157),
            $this->colorRgb($imagen, 179, 196, 232),
            $this->colorRgb($imagen, 196, 148, 46),
            $this->colorRgb($imagen, 226, 152, 171),
        ];

        $limiteSuperior = (int) ($alto * 0.30);
        $limiteInferior = $alto - 150;

        for ($i = 0; $i < 90; $i++) {
            $y = mt_rand(0, $alto);

            if ($y > $limiteSuperior && $y < $limiteInferior) {
                continue;
            }

            $x = mt_rand(0, $ancho);
            $color = $colores[$i % count($colores)];
            $tamano = mt_rand(6, 14);

            if ($i % 2 === 0) {
                imagefilledellipse($imagen, $x, $y, $tamano, $tamano, $color);
            } else {
                imagefilledrectangle($imagen, $x, $y, $x + $tamano, $y + (int) ($tamano * 0.5), $color);
            }
        }
    }
}
