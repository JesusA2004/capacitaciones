<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Foto de perfil del colaborador: la sube él mismo (al registrar sus
 * documentos, desde la web o la app) o RH desde su expediente. Es la
 * miniatura que lo identifica en todos los módulos (expedientes,
 * solicitudes, organigrama, cumpleaños).
 *
 * Toda foto se normaliza aquí — orientación EXIF corregida, recorte
 * cuadrado al centro y 800×800 JPEG — para que se vea igual de bien como
 * miniatura sin importar si vino de un celular vertical o de una webcam.
 *
 * Nunca sobrescribe ni borra la foto anterior (regla del proyecto: el
 * expediente conserva su historial): cada foto nueva se guarda con su
 * propia marca de tiempo y `foto_path` pasa a apuntar a ella.
 */
class FotoColaboradorService
{
    private const LADO = 800;

    private const CALIDAD_JPEG = 85;

    public function __construct(private readonly DocumentoStorageService $storage) {}

    public function actualizar(Colaborador $colaborador, UploadedFile $archivo, User $actor): Colaborador
    {
        $imagen = $this->normalizar($archivo);

        $carpeta = dirname($this->storage->rutaFoto($colaborador, 'jpg'));
        $ruta = sprintf('%s/Foto de perfil %s.jpg', $carpeta, now()->format('Y-m-d His'));

        if ($this->storage->existe($ruta)) {
            throw ValidationException::withMessages([
                'foto' => 'Se acaba de guardar una foto; espera un momento e intenta de nuevo.',
            ]);
        }

        $this->storage->disco()->put($ruta, $imagen);

        if (! $this->storage->existe($ruta)) {
            throw new RuntimeException("No se pudo guardar la foto de perfil en el NAS: {$ruta}");
        }

        $anterior = $colaborador->foto_path;
        $colaborador->update(['foto_path' => $ruta]);

        activity('expedientes')
            ->performedOn($colaborador)
            ->causedBy($actor)
            ->withProperties(['foto_anterior' => $anterior, 'foto_nueva' => $ruta])
            ->log('foto_perfil_actualizada');

        return $colaborador;
    }

    /**
     * URL protegida de la miniatura, o null si no tiene foto. El parámetro
     * `v` cambia con cada foto nueva para que el navegador no muestre la
     * anterior desde su caché. Nunca expone `foto_path` (ruta del NAS).
     */
    public function url(Colaborador $colaborador): ?string
    {
        if ($colaborador->foto_path === null) {
            return null;
        }

        return route('rh.expedientes.foto', [
            'colaborador' => $colaborador->id,
            'v' => substr(md5($colaborador->foto_path), 0, 10),
        ]);
    }

    /**
     * @return string Bytes JPEG cuadrados de LADO×LADO.
     */
    private function normalizar(UploadedFile $archivo): string
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $origen = @imagecreatefromstring($contenido);

        if (! $origen instanceof GdImage) {
            throw ValidationException::withMessages([
                'foto' => 'No se pudo leer la imagen. Usa una foto JPG, PNG o WEBP.',
            ]);
        }

        $origen = $this->corregirOrientacion($origen, $archivo);

        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $lado = min($ancho, $alto);

        $destino = imagecreatetruecolor(self::LADO, self::LADO);
        // Fondo blanco: un PNG con transparencia no debe quedar negro.
        imagefill($destino, 0, 0, (int) imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled(
            $destino,
            $origen,
            0,
            0,
            intdiv($ancho - $lado, 2),
            intdiv($alto - $lado, 2),
            self::LADO,
            self::LADO,
            $lado,
            $lado,
        );

        ob_start();
        imagejpeg($destino, null, self::CALIDAD_JPEG);
        $bytes = (string) ob_get_clean();

        imagedestroy($origen);
        imagedestroy($destino);

        return $bytes;
    }

    /**
     * Las fotos de celular suelen venir "acostadas" con la rotación real en
     * los metadatos EXIF: se aplica para que la miniatura quede derecha.
     */
    private function corregirOrientacion(GdImage $imagen, UploadedFile $archivo): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($archivo->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $imagen;
        }

        $exif = @exif_read_data($archivo->getRealPath());
        $orientacion = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $grados = match ($orientacion) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $grados, 0);

        return $rotada instanceof GdImage ? $rotada : $imagen;
    }
}
