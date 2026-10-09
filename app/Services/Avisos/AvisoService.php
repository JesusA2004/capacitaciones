<?php

namespace App\Services\Avisos;

use App\Enums\AlcanceAviso;
use App\Enums\EstadoUsuario;
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
 * Avisos de RH a la empresa: mensaje + imagen opcional, a toda la plantilla
 * activa o a un colaborador específico. Push real a cada destinatario
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
    public function crear(array $datos, ?UploadedFile $imagen, User $actor): Aviso
    {
        $alcance = AlcanceAviso::from((string) $datos['alcance']);
        $colaboradorObjetivoIdCrudo = $datos['colaborador_objetivo_id'] ?? null;

        if ($alcance === AlcanceAviso::Colaborador && $colaboradorObjetivoIdCrudo === null) {
            throw ValidationException::withMessages(['colaborador_objetivo_id' => 'Elige a qué colaborador le llega este aviso.']);
        }

        $colaboradorObjetivoId = $alcance === AlcanceAviso::Colaborador ? (int) $colaboradorObjetivoIdCrudo : null;

        $aviso = DB::transaction(function () use ($datos, $imagen, $actor, $alcance, $colaboradorObjetivoId): Aviso {
            $aviso = Aviso::query()->create([
                'titulo' => $datos['titulo'],
                'mensaje' => $datos['mensaje'],
                'alcance' => $alcance,
                'colaborador_objetivo_id' => $colaboradorObjetivoId,
                'creado_por' => $actor->id,
                'enviado_en' => now(),
            ]);

            if ($imagen !== null) {
                $extension = strtolower($imagen->getClientOriginalExtension() ?: $imagen->extension() ?: 'jpg');
                $ruta = sprintf('avisos/%d/imagen.%s', $aviso->id, $extension);
                $this->disco()->put($ruta, (string) file_get_contents($imagen->getRealPath()));

                $aviso->forceFill([
                    'imagen_disk' => self::DISK,
                    'imagen_path' => $ruta,
                    'imagen_mime' => (string) $imagen->getMimeType(),
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
            $usuarios = User::query()->whereHas(
                'colaborador',
                fn ($q) => $q->where('estatus', EstadoUsuario::Activo->value),
            )->get();
        } else {
            $usuarios = User::query()->where('colaborador_id', $aviso->colaborador_objetivo_id)->get();
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
            'imagen_path' => $aviso->imagen_path,
            'creado_por' => $aviso->creadoPor !== null ? ['id' => $aviso->creadoPor->id, 'name' => $aviso->creadoPor->name, 'apellidos' => $aviso->creadoPor->apellidos] : null,
            'enviado_en' => $aviso->enviado_en?->toIso8601String(),
            'created_at' => $aviso->created_at?->toIso8601String(),
            ...($incluirLeido ? [
                'leido' => (bool) $aviso->getAttribute('leido'),
                'leido_en' => $aviso->getAttribute('leido_en') !== null ? Carbon::parse((string) $aviso->getAttribute('leido_en'))->toIso8601String() : null,
            ] : []),
        ];
    }

    public function imagen(Aviso $aviso): StreamedResponse
    {
        abort_if($aviso->imagen_path === null || $aviso->imagen_disk === null, 404);
        $disco = Storage::disk($aviso->imagen_disk);
        abort_unless($disco->exists($aviso->imagen_path), 404);

        return $disco->response($aviso->imagen_path, null, [
            'Content-Type' => $aviso->imagen_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function disco(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
