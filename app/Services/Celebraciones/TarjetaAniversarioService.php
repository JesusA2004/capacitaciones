<?php

namespace App\Services\Celebraciones;

use App\Enums\TipoCelebracion;
use App\Models\BirthdayGreeting;
use App\Models\CelebracionConfiguracion;
use App\Services\Cumpleanos\CumpleanosStorageService;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Tarjeta gráfica de ANIVERSARIO LABORAL (docs/CELEBRACIONES.md), generada
 * en el servidor con GD (determinista, sin capturas del DOM) a
 * config('celebraciones.card_width')×card_height (1080×1350 por defecto:
 * WhatsApp, app, descarga e impresión básica).
 *
 * Composición (idea visual de la referencia de dirección): globos verde /
 * azul / plata, logo MR. LANA, número de años destacado, "ANIVERSARIO",
 * nombre grande, sucursal, mensaje institucional y el personaje de MR. LANA
 * abajo. Todo lo variable es dinámico: {anios}, nombre, sucursal y mensaje
 * (configurable por RH). Si RH subió un fondo propio, se usa como base.
 * Nombres y sucursales largos se envuelven y reducen de tamaño; nunca se
 * cortan.
 */
class TarjetaAniversarioService
{
    use DibujoTarjeta;

    private const VERDE = [108, 192, 74];

    private const AZUL = [59, 198, 216];

    private const PLATA = [201, 206, 214];

    private const GRAFITO = [45, 52, 58];

    public function __construct(private readonly CumpleanosStorageService $storage) {}

    /**
     * Genera (o reutiliza) la tarjeta de la celebración y guarda su ruta.
     */
    public function generar(BirthdayGreeting $celebracion, bool $forzar = false): BirthdayGreeting
    {
        if (! $forzar && $celebracion->card_path !== null && $this->storage->existe($celebracion->card_path)) {
            return $celebracion;
        }

        $celebracion->loadMissing('colaborador.sucursalPrincipal');
        $colaborador = $celebracion->colaborador;
        $png = $this->renderPng(
            $colaborador->nombreCompleto(),
            (int) $celebracion->anios,
            $colaborador->sucursalPrincipal?->nombre,
            $this->mensaje((int) $celebracion->anios, $colaborador->nombreCompleto(), $colaborador->sucursalPrincipal?->nombre),
        );

        $ruta = $this->rutaTarjeta($celebracion);
        $this->storage->guardar($ruta, $png);

        if ($celebracion->card_path !== null && $celebracion->card_path !== $ruta) {
            $this->storage->eliminar($celebracion->card_path);
        }

        $celebracion->update([
            'card_path' => $ruta,
            'nombre_mostrado' => $colaborador->nombreCompleto(),
            'frase' => $this->mensaje((int) $celebracion->anios, $colaborador->nombreCompleto(), $colaborador->sucursalPrincipal?->nombre),
        ]);

        return $celebracion;
    }

    public function descargar(BirthdayGreeting $celebracion, bool $enLinea = false): StreamedResponse
    {
        $this->generar($celebracion);

        if ($celebracion->card_path === null) {
            throw new RuntimeException('No se pudo generar la tarjeta.');
        }

        $nombre = sprintf('aniversario-%s-%d-anios.png', Str::slug($celebracion->nombre_mostrado) ?: 'colaborador', (int) $celebracion->anios);

        return $this->storage->respuesta($celebracion->card_path, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('%s; filename="%s"', $enLinea ? 'inline' : 'attachment', $nombre),
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }

    /**
     * Mensaje institucional con {anios}/{nombre}/{sucursal} resueltos.
     */
    public function mensaje(int $anios, string $nombre, ?string $sucursal): string
    {
        $plantilla = CelebracionConfiguracion::de(TipoCelebracion::AniversarioLaboral)->mensaje
            ?: (string) config('celebraciones.aniversario.mensaje');

        // "durante estos 1 año" no es español correcto.
        if ($anios === 1) {
            $plantilla = str_replace('estos {anios}', 'este primer año', $plantilla);
        }

        return strtr($plantilla, [
            '{anios}' => FechasCelebracion::textoAnios($anios),
            '{nombre}' => $nombre,
            '{sucursal}' => (string) $sucursal,
        ]);
    }

    public function rutaFondo(): string
    {
        return 'celebraciones/aniversario/fondo-tarjeta.png';
    }

    private function rutaTarjeta(BirthdayGreeting $celebracion): string
    {
        return sprintf('celebraciones/aniversarios/%d/%s-%s.png', (int) $celebracion->colaborador_id, $celebracion->fecha->toDateString(), Str::random(6));
    }

    /**
     * PNG completo. Público para la vista previa de Configuración.
     */
    public function renderPng(string $nombre, int $anios, ?string $sucursal, string $mensaje): string
    {
        $ancho = max(600, (int) config('celebraciones.card_width', 1080));
        $alto = max(750, (int) config('celebraciones.card_height', 1350));
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagesavealpha($imagen, true);
        imagealphablending($imagen, true);

        if (! $this->fondoPersonalizado($imagen, $ancho, $alto)) {
            $this->fondo($imagen, $ancho, $alto);
            $this->globos($imagen, $ancho, $alto);
        }

        $negrita = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $regular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $verde = $this->colorRgb($imagen, ...self::VERDE);
        $azul = $this->colorRgb($imagen, ...self::AZUL);
        $grafito = $this->colorRgb($imagen, ...self::GRAFITO);
        $margen = (int) ($ancho * 0.12);
        $anchoTexto = $ancho - 2 * $margen;
        $escala = $ancho / 1080;

        // Logo.
        $y = $this->logo($imagen, $ancho, (int) (48 * $escala)) + (int) (40 * $escala);

        // Número de años destacado, con sombra plata.
        $tamanoNumero = (int) (($anios >= 10 ? 200 : 230) * $escala);
        $y += $tamanoNumero;
        $sombra = $this->colorRgb($imagen, ...self::PLATA);
        $this->textoCentrado($imagen, $negrita, $tamanoNumero, $sombra, $ancho + (int) (10 * $escala), $y + (int) (8 * $escala), (string) $anios);
        $this->textoCentrado($imagen, $negrita, $tamanoNumero, $verde, $ancho, $y, (string) $anios);

        $y += (int) (70 * $escala);
        $this->textoCentrado($imagen, $negrita, (int) (30 * $escala), $azul, $ancho, $y, mb_strtoupper($anios === 1 ? 'año' : 'años'));
        $y += (int) (78 * $escala);
        $this->textoCentrado($imagen, $negrita, (int) (58 * $escala), $grafito, $ancho, $y, 'ANIVERSARIO');

        // Nombre: hasta ~2-3 líneas, reduce tamaño antes que cortar.
        $y += (int) (92 * $escala);
        $y = $this->textoParrafo($imagen, $negrita, (int) (54 * $escala), $verde, $ancho, $y, mb_strtoupper($nombre), $anchoTexto,
            interlineado: (int) (64 * $escala), altoMaximo: (int) (190 * $escala), tamanoMinimo: (int) (28 * $escala));

        if ($sucursal !== null && trim($sucursal) !== '') {
            $y += (int) (6 * $escala);
            $y = $this->textoParrafo($imagen, $regular, (int) (30 * $escala), $azul, $ancho, $y, mb_strtoupper($sucursal), $anchoTexto,
                interlineado: (int) (40 * $escala), altoMaximo: (int) (80 * $escala), tamanoMinimo: (int) (20 * $escala));
        }

        // Mensaje primero, en el espacio sobre la franja inferior (18 % del
        // alto). textoParrafo() recibe la línea base de la primera línea,
        // por eso se descuenta una línea de holgura.
        $y += (int) (50 * $escala);
        $limiteMensaje = (int) ($alto * 0.82);
        $disponible = max((int) (120 * $escala), $limiteMensaje - (int) (42 * $escala) - $y);
        $finMensaje = $this->textoParrafo($imagen, $regular, (int) (28 * $escala), $grafito, $ancho, $y, $mensaje, $anchoTexto,
            interlineado: (int) (42 * $escala), altoMaximo: $disponible, tamanoMinimo: (int) (18 * $escala));

        // El personaje ocupa el hueco que quede debajo del mensaje (hasta
        // 25 % del alto); si no hay espacio suficiente, no se dibuja.
        $altoPersonaje = min((int) ($alto * 0.25), $alto - $finMensaje - (int) (30 * $escala));

        if ($altoPersonaje >= (int) ($alto * 0.12)) {
            $this->personaje($imagen, $ancho - $margen + (int) (40 * $escala), $alto - $altoPersonaje - (int) (20 * $escala), $altoPersonaje);
        }

        imagettftext($imagen, (int) (40 * $escala), 0, $margen, $alto - (int) (150 * $escala), $verde, $negrita, '¡Muchas');
        imagettftext($imagen, (int) (40 * $escala), 0, $margen, $alto - (int) (95 * $escala), $verde, $negrita, 'felicidades!');

        ob_start();
        imagepng($imagen);
        $contenido = (string) ob_get_clean();
        imagedestroy($imagen);

        return $contenido;
    }

    private function fondo(GdImage $imagen, int $ancho, int $alto): void
    {
        for ($fila = 0; $fila < $alto; $fila++) {
            $t = $fila / max(1, $alto - 1);
            $c = (int) round(255 - 14 * $t);
            imagefilledrectangle($imagen, 0, $fila, $ancho, $fila, $this->colorRgb($imagen, $c, $c + 0, min(255, $c + 3)));
        }
    }

    /**
     * Racimos de globos verde / azul / plata en las esquinas superiores,
     * con brillo y cordón.
     */
    private function globos(GdImage $imagen, int $ancho, int $alto): void
    {
        $colores = [self::VERDE, self::AZUL, self::PLATA];
        $racimos = [
            [[0.09, 0.10, 0.075], [0.19, 0.06, 0.06], [0.05, 0.22, 0.06], [0.17, 0.17, 0.055]],
            [[0.91, 0.10, 0.075], [0.81, 0.06, 0.06], [0.95, 0.22, 0.06], [0.83, 0.17, 0.055]],
        ];
        $cordon = $this->colorRgb($imagen, 170, 176, 184);

        foreach ($racimos as $racimo) {
            foreach ($racimo as $i => [$px, $py, $pr]) {
                [$r, $g, $b] = $colores[$i % 3];
                $cx = (int) ($ancho * $px);
                $cy = (int) ($alto * $py);
                $radio = (int) ($ancho * $pr);

                imageline($imagen, $cx, $cy + (int) ($radio * 1.15), $cx + (int) ($radio * 0.3), $cy + (int) ($radio * 2.4), $cordon);
                imagefilledellipse($imagen, $cx, $cy, $radio * 2, (int) ($radio * 2.3), $this->colorRgb($imagen, $r, $g, $b));
                imagefilledellipse($imagen, $cx - (int) ($radio * 0.35), $cy - (int) ($radio * 0.45), (int) ($radio * 0.5), (int) ($radio * 0.7), $this->colorRgba($imagen, 255, 255, 255, 70));
            }
        }
    }

    private function fondoPersonalizado(GdImage $imagen, int $ancho, int $alto): bool
    {
        $ruta = $this->rutaFondo();

        try {
            if (! $this->storage->existe($ruta)) {
                return false;
            }

            $fondo = @imagecreatefromstring((string) $this->storage->disco()->get($ruta));
        } catch (Throwable $e) {
            Log::warning('TarjetaAniversarioService: no se pudo leer el fondo personalizado.', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $fondo instanceof GdImage) {
            return false;
        }

        $escala = max($ancho / imagesx($fondo), $alto / imagesy($fondo));
        $origenAncho = (int) round($ancho / $escala);
        $origenAlto = (int) round($alto / $escala);
        imagecopyresampled($imagen, $fondo, 0, 0, (int) max(0, (imagesx($fondo) - $origenAncho) / 2), (int) max(0, (imagesy($fondo) - $origenAlto) / 2), $ancho, $alto, $origenAncho, $origenAlto);
        imagedestroy($fondo);

        return true;
    }

    /**
     * @return int Y inferior del logo.
     */
    private function logo(GdImage $imagen, int $ancho, int $margenSuperior): int
    {
        $ruta = $this->rutaAssetMarca('logoLetras.png');
        $logo = $ruta !== null ? @imagecreatefrompng($ruta) : false;

        if (! $logo instanceof GdImage) {
            return $margenSuperior;
        }

        $destinoAncho = (int) ($ancho * 0.38);
        $destinoAlto = (int) (imagesy($logo) * ($destinoAncho / imagesx($logo)));
        imagecopyresampled($imagen, $logo, (int) (($ancho - $destinoAncho) / 2), $margenSuperior, 0, 0, $destinoAncho, $destinoAlto, imagesx($logo), imagesy($logo));
        imagedestroy($logo);

        return $margenSuperior + $destinoAlto;
    }

    /**
     * Personaje de MR. LANA (asset real del repo); si no existe, no se dibuja.
     */
    private function personaje(GdImage $imagen, int $xDerecha, int $y, int $alto): void
    {
        $ruta = $this->rutaAssetMarca('mascot-left.png') ?? $this->rutaAssetMarca('mascot-right.png');
        $png = $ruta !== null ? @imagecreatefrompng($ruta) : false;

        if (! $png instanceof GdImage) {
            return;
        }

        $anchoDestino = (int) (imagesx($png) * ($alto / imagesy($png)));
        imagecopyresampled($imagen, $png, $xDerecha - $anchoDestino, $y, 0, 0, $anchoDestino, $alto, imagesx($png), imagesy($png));
        imagedestroy($png);
    }
}
