<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoCambioFoto;
use App\Models\CambioFotoPerfil;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Tareas\NotificadorRhService;
use GdImage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de perfil del colaborador: la miniatura que lo identifica en todos
 * los módulos (expedientes, solicitudes, organigrama, cumpleaños, app).
 *
 * Regla de negocio:
 *  - SIN foto oficial: la primera que sube el propio colaborador (web o
 *    app) queda oficial al instante, sin autorización de RH.
 *  - CON foto oficial: el colaborador no la reemplaza directo; su foto
 *    nueva queda como PROPUESTA pendiente (una a la vez) y la oficial sigue
 *    visible hasta que RH la aprueba. Al aprobar, la propuesta pasa a
 *    oficial y la anterior queda en el historial; al rechazar, no cambia
 *    nada y se avisa el motivo.
 *  - RH desde el expediente (actualizar()) sí la cambia directo.
 *
 * Toda foto se normaliza aquí — MIME real verificado al decodificarla,
 * orientación EXIF corregida, recorte cuadrado al centro, 800×800 JPEG —
 * y se guarda en el NAS privado con un nombre propio (fecha y hora): nunca
 * se sobrescribe ni se borra una foto anterior, y la ruta la arma el
 * sistema (sin path traversal). La BD solo guarda la ruta.
 */
class FotoColaboradorService
{
    private const LADO = 800;

    private const CALIDAD_JPEG = 85;

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * RH (o un proceso del sistema) fija la foto oficial directamente.
     */
    public function actualizar(Colaborador $colaborador, UploadedFile $archivo, User $actor): Colaborador
    {
        $ruta = $this->guardar($colaborador, $this->normalizar($archivo), 'Foto de perfil');

        $anterior = $colaborador->foto_path;
        $colaborador->update(['foto_path' => $ruta]);

        $this->auditoria->registrar('foto_perfil_actualizada', $colaborador, $actor, ['foto_anterior' => $anterior, 'foto_nueva' => $ruta]);

        return $colaborador;
    }

    /**
     * El propio colaborador sube su foto (web o app).
     *
     * @return array{resultado: 'oficial'|'pendiente', cambio: CambioFotoPerfil|null}
     */
    public function subirPropia(Colaborador $colaborador, UploadedFile $archivo, User $actor): array
    {
        $bytes = $this->normalizar($archivo);

        if ($colaborador->foto_path === null) {
            $ruta = $this->guardar($colaborador, $bytes, 'Foto de perfil');

            // Bloqueo: dos primeras fotos simultáneas no deben pisarse.
            $oficial = DB::transaction(function () use ($colaborador, $ruta): bool {
                $fresco = Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->firstOrFail();

                if ($fresco->foto_path !== null) {
                    return false;
                }

                $fresco->update(['foto_path' => $ruta]);
                $colaborador->setRawAttributes($fresco->getAttributes(), true);

                return true;
            });

            if ($oficial) {
                $this->auditoria->registrar('foto_perfil_actualizada', $colaborador, $actor, ['foto_anterior' => null, 'foto_nueva' => $ruta, 'origen' => 'primera_foto']);

                return ['resultado' => 'oficial', 'cambio' => null];
            }

            return ['resultado' => 'pendiente', 'cambio' => $this->registrarPropuesta($colaborador->fresh() ?? $colaborador, $ruta, $actor)];
        }

        $this->exigirSinPendiente($colaborador);
        $ruta = $this->guardar($colaborador, $bytes, 'Propuesta de foto');

        return ['resultado' => 'pendiente', 'cambio' => $this->registrarPropuesta($colaborador, $ruta, $actor)];
    }

    public function aprobar(CambioFotoPerfil $cambio, User $revisor): CambioFotoPerfil
    {
        $cambio = DB::transaction(function () use ($cambio, $revisor): CambioFotoPerfil {
            $fresco = CambioFotoPerfil::query()->whereKey($cambio->id)->lockForUpdate()->firstOrFail();
            $this->exigirPendiente($fresco);
            $colaborador = Colaborador::withTrashed()->whereKey($fresco->colaborador_id)->lockForUpdate()->firstOrFail();

            $fresco->update([
                'estado' => EstadoCambioFoto::Aprobado->value,
                'foto_anterior_path' => $colaborador->foto_path,
                'revisado_por_id' => $revisor->id,
                'revisado_en' => now(),
            ]);
            $colaborador->update(['foto_path' => $fresco->foto_path]);

            return $fresco;
        });

        $this->auditoria->registrar('foto_perfil_cambio_aprobado', $cambio->colaborador, $revisor, [
            'cambio_id' => $cambio->id, 'foto_anterior' => $cambio->foto_anterior_path, 'foto_nueva' => $cambio->foto_path,
        ]);
        $this->avisarColaborador($cambio, 'Tu nueva foto de perfil fue aprobada', 'RH aprobó tu cambio de foto: ya aparece en tu perfil.');

        return $cambio;
    }

    public function rechazar(CambioFotoPerfil $cambio, User $revisor, ?string $motivo): CambioFotoPerfil
    {
        $motivo = trim((string) $motivo) !== '' ? trim((string) $motivo) : null;

        $cambio = DB::transaction(function () use ($cambio, $revisor, $motivo): CambioFotoPerfil {
            $fresco = CambioFotoPerfil::query()->whereKey($cambio->id)->lockForUpdate()->firstOrFail();
            $this->exigirPendiente($fresco);

            $fresco->update([
                'estado' => EstadoCambioFoto::Rechazado->value,
                'motivo_rechazo' => $motivo,
                'revisado_por_id' => $revisor->id,
                'revisado_en' => now(),
            ]);

            return $fresco;
        });

        $this->auditoria->registrar('foto_perfil_cambio_rechazado', $cambio->colaborador, $revisor, ['cambio_id' => $cambio->id, 'motivo' => $motivo]);
        $this->avisarColaborador($cambio, 'Tu cambio de foto no fue aprobado', $motivo !== null
            ? sprintf('RH no aprobó tu nueva foto: %s. Tu foto actual se conserva.', $motivo)
            : 'RH no aprobó tu nueva foto. Tu foto actual se conserva.');

        return $cambio;
    }

    /**
     * Estado de la foto para la propia persona (perfil web/app):
     * sin_foto | oficial | cambio_pendiente, más la última decisión de RH.
     *
     * @return array{estado: string, etiqueta: string, foto_url: string|null, puede_subir_directo: bool, pendiente: array<string, mixed>|null, ultimo_cambio: array<string, mixed>|null}
     */
    public function estadoPara(Colaborador $colaborador, bool $api = true): array
    {
        $pendiente = $this->pendienteDe($colaborador);
        $ultimo = CambioFotoPerfil::query()->where('colaborador_id', $colaborador->id)
            ->where('estado', '!=', EstadoCambioFoto::Pendiente->value)->latest('revisado_en')->latest('id')->first();

        $estado = match (true) {
            $pendiente !== null => 'cambio_pendiente',
            $colaborador->foto_path === null => 'sin_foto',
            default => 'oficial',
        };

        return [
            'estado' => $estado,
            'etiqueta' => match ($estado) {
                'cambio_pendiente' => EstadoCambioFoto::Pendiente->etiqueta(),
                'sin_foto' => 'Sin foto',
                default => 'Foto oficial',
            },
            'foto_url' => $api ? $this->urlPropiaApi($colaborador) : $this->url($colaborador),
            'puede_subir_directo' => $colaborador->foto_path === null,
            'pendiente' => $pendiente !== null ? [
                'id' => $pendiente->id,
                'solicitada_en' => $pendiente->created_at->toIso8601String(),
                'foto_url' => $api
                    ? route('api.v1.colaborador.foto.propuesta', ['v' => $this->version($pendiente->foto_path)])
                    : route('rh.cambios-foto.propuesta', $pendiente),
            ] : null,
            'ultimo_cambio' => $ultimo !== null ? [
                'id' => $ultimo->id,
                'estado' => $ultimo->estado->value,
                'etiqueta' => $ultimo->estado->etiqueta(),
                'motivo_rechazo' => $ultimo->motivo_rechazo,
                'revisado_en' => $ultimo->revisado_en?->toIso8601String(),
            ] : null,
        ];
    }

    public function pendienteDe(Colaborador $colaborador): ?CambioFotoPerfil
    {
        return CambioFotoPerfil::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoCambioFoto::Pendiente->value)->latest('id')->first();
    }

    /**
     * Propuestas pendientes que este revisor puede resolver (alcance
     * organizacional: RH global ve todas; un gerente, las de su sucursal).
     *
     * @return EloquentCollection<int, CambioFotoPerfil>
     */
    public function pendientesPara(User $revisor): EloquentCollection
    {
        $ids = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $revisor)->select('colaboradores.id');

        return CambioFotoPerfil::query()
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre'])
            ->where('estado', EstadoCambioFoto::Pendiente->value)
            ->whereIn('colaborador_id', $ids)
            ->oldest()
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function filaRevision(CambioFotoPerfil $cambio, bool $api = false): array
    {
        $colaborador = $cambio->colaborador;

        return [
            'id' => $cambio->id,
            'estado' => $cambio->estado->value,
            'etiqueta' => $cambio->estado->etiqueta(),
            'solicitada_en' => $cambio->created_at->toIso8601String(),
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ],
            'foto_actual_url' => $api ? $this->urlRhApi($colaborador) : $this->url($colaborador),
            'foto_propuesta_url' => $api
                ? route('api.v1.rh.cambios-foto.propuesta', ['cambio' => $cambio->id, 'v' => $this->version($cambio->foto_path)])
                : route('rh.cambios-foto.propuesta', ['cambio' => $cambio->id, 'v' => $this->version($cambio->foto_path)]),
        ];
    }

    public function respuestaPropuesta(CambioFotoPerfil $cambio): StreamedResponse
    {
        abort_unless($this->storage->existe($cambio->foto_path), 404);

        return $this->storage->respuesta($cambio->foto_path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto-propuesta.jpg"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * URL protegida (sesión web) de la miniatura, o null si no tiene foto.
     * `v` cambia con cada foto nueva para que nadie vea la anterior desde
     * su caché. Nunca expone `foto_path` (ruta del NAS).
     */
    public function url(Colaborador $colaborador): ?string
    {
        if ($colaborador->foto_path === null) {
            return null;
        }

        return route('rh.expedientes.foto', [
            'colaborador' => $colaborador->id,
            'v' => $this->version($colaborador->foto_path),
        ]);
    }

    /** Foto propia para la app (Bearer token), versionada. */
    public function urlPropiaApi(Colaborador $colaborador): ?string
    {
        return $colaborador->foto_path !== null ? route('api.v1.colaborador.foto', ['v' => $this->version($colaborador->foto_path)]) : null;
    }

    /** Foto de otra persona para RH en la app (Bearer token), versionada. */
    public function urlRhApi(Colaborador $colaborador): ?string
    {
        return $colaborador->foto_path !== null ? route('api.v1.rh.cumpleanos.foto', ['colaborador' => $colaborador->id, 'v' => $this->version($colaborador->foto_path)]) : null;
    }

    public static function version(string $ruta): string
    {
        return substr(md5($ruta), 0, 10);
    }

    private function exigirSinPendiente(Colaborador $colaborador): void
    {
        if ($this->pendienteDe($colaborador) !== null) {
            throw ValidationException::withMessages([
                'foto' => 'Ya tienes un cambio de foto esperando la revisión de RH. Espera a que lo resuelvan.',
            ]);
        }
    }

    private function exigirPendiente(CambioFotoPerfil $cambio): void
    {
        if ($cambio->estado !== EstadoCambioFoto::Pendiente) {
            throw ValidationException::withMessages(['cambio' => 'Este cambio de foto ya fue resuelto.']);
        }
    }

    private function registrarPropuesta(Colaborador $colaborador, string $ruta, User $actor): CambioFotoPerfil
    {
        $cambio = DB::transaction(function () use ($colaborador, $ruta, $actor): CambioFotoPerfil {
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();
            $this->exigirSinPendiente($colaborador);

            return CambioFotoPerfil::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitado_por_id' => $actor->id,
                'foto_path' => $ruta,
                'foto_anterior_path' => $colaborador->foto_path,
                'estado' => EstadoCambioFoto::Pendiente->value,
            ]);
        });

        $this->auditoria->registrar('foto_perfil_cambio_solicitado', $colaborador, $actor, ['cambio_id' => $cambio->id, 'foto_propuesta' => $ruta]);
        $this->notificador->notificar(
            $this->notificador->responsablesDe($colaborador, 'expedientes.revisar'),
            'rh_foto_perfil',
            'Cambio de foto por revisar',
            sprintf('%s pidió cambiar su foto de perfil.', $colaborador->nombreCompleto()),
            $cambio,
            'revisar_foto',
        );

        return $cambio;
    }

    private function avisarColaborador(CambioFotoPerfil $cambio, string $titulo, string $mensaje): void
    {
        $usuario = $cambio->colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'foto_perfil', $titulo, $mensaje, $cambio, 'ver_perfil');
        }
    }

    private function guardar(Colaborador $colaborador, string $bytes, string $prefijo): string
    {
        $carpeta = dirname($this->storage->rutaFoto($colaborador, 'jpg'));
        $ruta = sprintf('%s/%s %s.jpg', $carpeta, $prefijo, now()->format('Y-m-d His'));

        if ($this->storage->existe($ruta)) {
            throw ValidationException::withMessages([
                'foto' => 'Se acaba de guardar una foto; espera un momento e intenta de nuevo.',
            ]);
        }

        $this->storage->disco()->put($ruta, $bytes);

        if (! $this->storage->existe($ruta)) {
            throw new RuntimeException("No se pudo guardar la foto de perfil en el NAS: {$ruta}");
        }

        return $ruta;
    }

    /**
     * @return string Bytes JPEG cuadrados de LADO×LADO.
     */
    private function normalizar(UploadedFile $archivo): string
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        // MIME real (no la extensión ni lo que diga el cliente).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
        $origen = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? @imagecreatefromstring($contenido) : false;

        if (! $origen instanceof GdImage) {
            throw ValidationException::withMessages([
                'foto' => 'No se pudo leer la imagen. Usa una foto JPG, PNG o WEBP.',
            ]);
        }

        $origen = $this->corregirOrientacion($origen, $archivo, $mime);

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
    private function corregirOrientacion(GdImage $imagen, UploadedFile $archivo, string|false $mime): GdImage
    {
        if (! function_exists('exif_read_data') || $mime !== 'image/jpeg') {
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
