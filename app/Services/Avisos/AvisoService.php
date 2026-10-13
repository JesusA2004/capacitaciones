<?php

namespace App\Services\Avisos;

use App\Enums\AlcanceAviso;
use App\Enums\EstadoUsuario;
use App\Exceptions\ArchivoGeneradoNoDisponibleException;
use App\Models\Aviso;
use App\Models\AvisoLectura;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\MobilePush\PushNotifier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avisos de RH a la empresa: mensaje + adjunto opcional (imagen JPG/PNG/
 * WEBP o PDF), a toda la plantilla activa o a un colaborador específico.
 * El adjunto nunca viaja en el push: el push abre el aviso y el archivo se
 * consulta por endpoint autorizado. Push real a cada destinatario
 * (App\Services\MobilePush\PushNotifier, que ya encola por dispositivo:
 * nunca se llama a Expo directo desde aquí). El disco es privado, igual
 * que fondos/documentos (nunca un disco público).
 */
class AvisoService
{
    private const DISK = 'nas';

    public function __construct(private readonly PushNotifier $push) {}

    /**
     * @param  array<string, mixed>  $datos  titulo, mensaje, alcance, colaborador_objetivo_id? (validado por CrearAvisoRequest).
     */
    public function crear(array $datos, ?UploadedFile $archivo, User $actor): Aviso
    {
        $alcance = AlcanceAviso::from((string) $datos['alcance']);
        $colaboradorObjetivoIdCrudo = $datos['colaborador_objetivo_id'] ?? null;

        if ($alcance === AlcanceAviso::Colaborador && $colaboradorObjetivoIdCrudo === null) {
            throw ValidationException::withMessages(['colaborador_objetivo_id' => 'Elige a qué colaborador le llega este aviso.']);
        }

        $colaboradorObjetivoId = $alcance === AlcanceAviso::Colaborador ? (int) $colaboradorObjetivoIdCrudo : null;

        $aviso = DB::transaction(function () use ($datos, $archivo, $actor, $alcance, $colaboradorObjetivoId): Aviso {
            $aviso = Aviso::query()->create([
                'titulo' => $datos['titulo'],
                'mensaje' => $datos['mensaje'],
                'alcance' => $alcance,
                'colaborador_objetivo_id' => $colaboradorObjetivoId,
                'creado_por' => $actor->id,
                'enviado_en' => now(),
            ]);

            if ($archivo !== null) {
                $mime = (string) $archivo->getMimeType();
                // La extensión sale del contenido real (no del nombre que
                // mandó el navegador).
                $extension = match ($mime) {
                    'application/pdf' => 'pdf',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $ruta = sprintf('avisos/%d/adjunto.%s', $aviso->id, $extension);

                if (! $this->disco()->put($ruta, (string) file_get_contents($archivo->getRealPath()))) {
                    throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo del aviso. Intenta de nuevo; si continúa, avisa a Sistemas.']);
                }

                $nombre = trim((string) preg_replace('~[\x00-\x1F/\\\\]+~u', ' ', $archivo->getClientOriginalName()));

                $aviso->forceFill([
                    'archivo_disk' => self::DISK,
                    'archivo_path' => $ruta,
                    'archivo_mime' => $mime,
                    'archivo_nombre' => mb_substr($nombre !== '' ? $nombre : 'adjunto.'.$extension, 0, 180),
                    'archivo_size' => (int) $archivo->getSize(),
                ])->save();
            }

            return $aviso;
        });

        $this->notificar($aviso);

        return $aviso->refresh();
    }

    /**
     * Push real a cada destinatario — nunca se materializa una fila de
     * notificación por persona para "toda la empresa" (podrían ser miles);
     * el feed se resuelve por consulta (paraColaborador()), y la lectura
     * se registra solo cuando alguien realmente lo abre.
     */
    private function notificar(Aviso $aviso): void
    {
        // Sin emoji a mano: PushNotifier::conEmoji() ya antepone el de
        // 'aviso_rh' (NotificacionesService::ESTILOS), mismo catálogo que la
        // campana web y la API.
        $titulo = $aviso->titulo;

        if ($aviso->alcance === AlcanceAviso::Todos) {
            // "Todos" incluye a quien lo envía (también le llega el push),
            // aunque su cuenta no tenga colaborador activo (p. ej. un admin).
            $usuarios = User::query()
                ->whereNull('acceso_bloqueado_en')
                ->where(fn ($q) => $q
                    ->whereHas('colaborador', fn ($c) => $c->where('estatus', EstadoUsuario::Activo->value))
                    ->orWhere('id', $aviso->creado_por))
                ->get();
        } else {
            // A un colaborador: solo a esa persona.
            $usuarios = User::query()->where('colaborador_id', $aviso->colaborador_objetivo_id)->whereNull('acceso_bloqueado_en')->get();
        }

        foreach ($usuarios as $usuario) {
            $this->push->aUsuarioConDatos($usuario, $titulo, mb_substr($aviso->mensaje, 0, 180), [
                'type' => 'aviso_rh',
                'resource_id' => $aviso->id,
            ]);
        }
    }

    /**
     * Avisos vigentes para ESTE colaborador: los de "toda la empresa" más
     * los dirigidos a él, con si ya los leyó. Array explícito (nunca el
     * modelo crudo): una relación cargada se serializa con la llave del
     * MÉTODO (camelCase: `creadoPor`), no con el nombre de columna — mismo
     * criterio que el resto de PEOPLE (ver DocumentosMaestrosAdminService).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paraColaborador(Colaborador $colaborador, int $porPagina = 20): LengthAwarePaginator
    {
        $usuarioId = $colaborador->user?->id;

        $paginador = Aviso::query()
            ->where(fn ($q) => $q->where('alcance', AlcanceAviso::Todos)->orWhere('colaborador_objetivo_id', $colaborador->id))
            ->with('creadoPor:id,name,apellidos')
            ->withExists(['lecturas as leido' => fn ($q) => $q->where('user_id', $usuarioId)])
            // Cuándo lo leyó (histórico del expediente).
            ->addSelect(['leido_en' => AvisoLectura::query()->select('leido_en')->whereColumn('aviso_id', 'avisos.id')->where('user_id', $usuarioId)->limit(1)])
            ->orderByDesc('enviado_en')
            ->paginate($porPagina);

        return $this->reemplazarItems($paginador, fn (Aviso $aviso): array => $this->aArray($aviso, incluirLeido: true));
    }

    /**
     * Un aviso concreto para este colaborador (el push abre el aviso, no la
     * bandeja). Quien llama ya verificó que le corresponde.
     *
     * @return array<string, mixed>
     */
    public function detalleParaColaborador(Aviso $aviso, Colaborador $colaborador): array
    {
        $usuarioId = $colaborador->user?->id;
        $aviso->loadMissing('creadoPor:id,name,apellidos');
        $lectura = AvisoLectura::query()->where('aviso_id', $aviso->id)->where('user_id', $usuarioId)->first(['leido_en']);
        $aviso->setAttribute('leido', $lectura !== null);
        $aviso->setAttribute('leido_en', $lectura?->leido_en);

        return $this->aArray($aviso, incluirLeido: true);
    }

    public function marcarLeido(Aviso $aviso, User $usuario): void
    {
        AvisoLectura::query()->firstOrCreate(
            ['aviso_id' => $aviso->id, 'user_id' => $usuario->id],
            ['leido_en' => now()],
        );
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listar(int $porPagina = 20): LengthAwarePaginator
    {
        $paginador = Aviso::query()
            ->with(['creadoPor:id,name,apellidos', 'colaboradorObjetivo:id,name,apellidos'])
            ->withCount('lecturas')
            ->orderByDesc('enviado_en')
            ->paginate($porPagina);

        return $this->reemplazarItems($paginador, fn (Aviso $aviso): array => [
            ...$this->aArray($aviso),
            'colaborador_objetivo' => $aviso->colaboradorObjetivo !== null ? ['id' => $aviso->colaboradorObjetivo->id, 'name' => $aviso->colaboradorObjetivo->name, 'apellidos' => $aviso->colaboradorObjetivo->apellidos] : null,
            'lecturas_count' => $aviso->lecturas_count,
        ]);
    }

    /**
     * Mismo paginador, con cada item ya convertido a array explícito (nunca
     * el modelo crudo: ver aArray()). Se construye un paginador NUEVO
     * porque setCollection() conserva el tipo genérico original.
     *
     * @param  LengthAwarePaginator<int, Aviso>  $paginador
     * @param  callable(Aviso): array<string, mixed>  $mapa
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function reemplazarItems(LengthAwarePaginator $paginador, callable $mapa): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $paginador->getCollection()->map($mapa)->values(),
            $paginador->total(),
            $paginador->perPage(),
            $paginador->currentPage(),
            ['path' => $paginador->path()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function aArray(Aviso $aviso, bool $incluirLeido = false): array
    {
        return [
            'id' => $aviso->id,
            'titulo' => $aviso->titulo,
            'mensaje' => $aviso->mensaje,
            'alcance' => $aviso->alcance->value,
            'colaborador_objetivo_id' => $aviso->colaborador_objetivo_id,
            // Adjunto (imagen o PDF): tipo para que cada cliente lo muestre
            // como imagen o como tarjeta PDF; se abre por /{id}/archivo.
            'archivo' => $aviso->tieneArchivo() ? [
                'tipo' => $aviso->archivoEsPdf() ? 'pdf' : 'imagen',
                'nombre' => $aviso->archivo_nombre,
                'tamano' => $aviso->archivo_size,
                'mime' => $aviso->archivo_mime,
            ] : null,
            // Compatibilidad con la app anterior (solo pinta imágenes vía
            // /{id}/imagen): no nulo únicamente si el adjunto es imagen.
            'imagen_path' => $aviso->archivoEsImagen() ? $aviso->archivo_path : null,
            'creado_por' => $aviso->creadoPor !== null ? ['id' => $aviso->creadoPor->id, 'name' => $aviso->creadoPor->name, 'apellidos' => $aviso->creadoPor->apellidos] : null,
            'enviado_en' => $aviso->enviado_en?->toIso8601String(),
            'created_at' => $aviso->created_at?->toIso8601String(),
            ...($incluirLeido ? [
                'leido' => (bool) $aviso->getAttribute('leido'),
                'leido_en' => $aviso->getAttribute('leido_en') !== null ? Carbon::parse((string) $aviso->getAttribute('leido_en'))->toIso8601String() : null,
            ] : []),
        ];
    }

    /**
     * El adjunto del aviso, inline (imagen o PDF). Sin adjunto → 404 (no
     * hay nada que mostrar); con registro pero sin archivo físico → aviso
     * recuperable, nunca un 404 genérico.
     *
     * @throws ArchivoGeneradoNoDisponibleException
     */
    public function archivo(Aviso $aviso): StreamedResponse
    {
        abort_unless($aviso->tieneArchivo(), 404);
        $ruta = (string) $aviso->archivo_path;
        $disco = Storage::disk((string) $aviso->archivo_disk);

        if (! $disco->exists($ruta)) {
            throw new ArchivoGeneradoNoDisponibleException(
                titulo: $aviso->titulo,
                mensaje: 'El archivo de este aviso ya no está disponible. Pide a Recursos Humanos que lo vuelva a enviar.',
            );
        }

        $nombre = str_replace('"', '', (string) ($aviso->archivo_nombre ?? basename($ruta)));

        return $disco->response($ruta, $nombre, [
            'Content-Type' => $aviso->archivo_mime ?? 'application/octet-stream',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nombre),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Compatibilidad con la app anterior: solo imágenes.
     */
    public function imagen(Aviso $aviso): StreamedResponse
    {
        abort_unless($aviso->archivoEsImagen(), 404);

        return $this->archivo($aviso);
    }

    private function disco(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
