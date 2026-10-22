<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteZipService;
use App\Services\Incorporacion\IncorporacionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expedientes/incorporacion para RH desde la app movil (Sanctum, sin
 * sesion web). A diferencia de App\Http\Controllers\Api\V1\IncorporacionController
 * (solo el propio colaborador, vista angosta), aqui RH ve expedientes
 * completos, pero solo dentro de su alcance organizacional
 * (AlcanceOrganizacionalService) y con los permisos rh.expedientes.* — ver
 * docs/API_MOVIL.md.
 */
class ExpedienteController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncorporacionService $incorporacion,
        private readonly DocumentoStorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        // Mismo criterio que la web (+ el permiso móvil histórico).
        abort_unless($usuario->can('rh.expedientes.ver') || $this->alcance->puedeExplorarExpedientes($usuario), 403);

        $colaboradores = $this->queryFiltrada($request)->orderBy('name')->get();

        $filas = $colaboradores->map(fn (Colaborador $c) => [
            'colaborador' => $c,
            'estado' => $this->incorporacion->estado($c),
        ]);

        if ($estado = $request->string('estado')->toString()) {
            $filas = $filas->filter(fn (array $fila) => $fila['estado'] === $estado)->values();
        }

        $total = $filas->count();
        $pagina = max(1, $request->integer('page', 1));

        $data = $filas->forPage($pagina, self::POR_PAGINA)->map(function (array $fila) {
            $c = $fila['colaborador'];

            return [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'correo' => $c->correo_personal,
                'numero_empleado' => $c->numero_empleado,
                'empresa' => $c->sucursalPrincipal?->empresa?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'departamento' => $c->departamento?->nombre,
                'estatus_usuario' => $c->estatus->value,
                'estado_incorporacion' => $fila['estado'],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $pagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / self::POR_PAGINA)),
            ],
        ]);
    }

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($this->puedeVerDetalle($request->user(), $colaborador), 403);

        $usuario = $request->user();
        $tipos = $this->incorporacion->tiposDocumento($colaborador);
        $documentos = $this->conPermisosDeLaApp($usuario, $this->incorporacion->detalleParaRh($colaborador));

        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
        ]);

        return response()->json([
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'correo' => $colaborador->correo_personal,
                'telefono' => $colaborador->telefono,
                'correo_corporativo' => $colaborador->correo_corporativo,
                'telefono_corporativo' => $colaborador->telefono_corporativo,
                'estatus' => $colaborador->estatus->value,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
            ],
            'estado_incorporacion' => $this->incorporacion->estado($colaborador),
            'incorporacion_decidida_por' => $colaborador->incorporacionDecididaPor?->nombreCompleto(),
            'incorporacion_decidida_en' => $colaborador->incorporacion_decidida_en?->toIso8601String(),
            'incorporacion_motivo_rechazo' => $colaborador->incorporacion_motivo_rechazo,
            'documentos' => $documentos,
            'documentos_totales' => $tipos->count(),
            // «Descargar expediente completo (ZIP)» en la app: misma regla que
            // el botón de la web (EmployeeDocumentPolicy::descargarExpediente).
            'puede_descargar_expediente' => $usuario->can('descargarExpediente', [EmployeeDocument::class, $colaborador]),
        ]);
    }

    /**
     * «Descargar» un documento del expediente desde la app (attachment): misma
     * regla que la descarga web (EmployeeDocumentPolicy::descargar). Ver el
     * archivo para revisarlo sigue siendo verDocumento().
     */
    public function descargarDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): StreamedResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
        abort_unless($usuario->can('descargar', $documento), 403, 'No tienes permiso para descargar documentos del expediente.');

        $nombre = $documento->original_name !== '' ? $documento->original_name : sprintf('documento-%d', $documento->id);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nombre, $this->nombreAscii($nombre)),
        ]);
    }

    /**
     * «Descargar expediente completo (ZIP)» desde la app: el MISMO
     * ExpedienteZipService y la misma Policy que la web
     * (Rh\ExpedienteController::descargarZip). Se borra al terminar de enviarse.
     */
    public function descargarZip(Request $request, Colaborador $colaborador, ExpedienteZipService $zip): BinaryFileResponse
    {
        abort_unless($request->user()->can('descargarExpediente', [EmployeeDocument::class, $colaborador]), 403, 'No tienes permiso para descargar el expediente completo.');

        return response()
            ->download($zip->generar($colaborador, $request->user()), $zip->nombreDescarga($colaborador), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * «Pedir corrección» desde la app: mismo servicio y Policy que la web
     * (Rh\EmployeeDocumentController::solicitarCorreccion).
     */
    public function correccionDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
        abort_unless($usuario->can('pedirCorreccion', $documento), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->solicitarCorreccionDocumento($documento, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Se pidió corrección al colaborador.']);
    }

    /**
     * Agrega a cada fila `id` (el del archivo vigente, como lo espera la app),
     * `puede_descargar` y `puede_pedir_correccion` (EmployeeDocumentPolicy).
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function conPermisosDeLaApp(User $usuario, array $filas): array
    {
        $ids = collect($filas)->pluck('documento_id')->filter()->values()->all();
        $documentos = EmployeeDocument::query()->with('colaborador')->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(function (array $fila) use ($usuario, $documentos): array {
            $documento = $documentos->get($fila['documento_id'] ?? 0);

            return $fila + [
                'id' => $fila['documento_id'] ?? null,
                'puede_descargar' => $documento !== null && $usuario->can('descargar', $documento),
                'puede_pedir_correccion' => $documento !== null && $documento->status->admiteDecisionDeRh() && $usuario->can('pedirCorreccion', $documento),
            ];
        }, $filas);
    }

    private function nombreAscii(string $nombre): string
    {
        $ascii = trim(preg_replace('/[^A-Za-z0-9 ._()-]+/', '', Str::ascii($nombre)) ?? '');

        return $ascii !== '' ? $ascii : 'documento';
    }

    /**
     * Sirve el documento en streaming, sin exponer nunca la ruta fisica del
     * disco NAS al cliente: el navegador/app solo conoce el id numerico de
     * `documento` y llega aqui siempre autenticado con Bearer token.
     */
    public function verDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): StreamedResponse
    {
        abort_unless($this->puedeVerDetalle($request->user(), $colaborador), 403);
        abort_unless($request->user()->can('rh.expedientes.documentos.ver'), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$documento->original_name.'"',
        ]);
    }

    public function aprobarDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
        // Mismo criterio que la web (EmployeeDocumentPolicy::aprobar).
        abort_unless($usuario->can('aprobar', $documento), 403);

        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:500']]);

        $this->incorporacion->aprobarDocumento($documento, $usuario, $datos['comentario'] ?? null);

        return response()->json(['message' => 'Documento aprobado.']);
    }

    public function rechazarDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
        // Mismo criterio que la web (EmployeeDocumentPolicy::rechazar).
        abort_unless($usuario->can('rechazar', $documento), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarDocumento($documento, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Documento rechazado.']);
    }

    public function autorizarCambioDocumento(Request $request, Colaborador $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
        // Mismo criterio que la web (EmployeeDocumentPolicy::autorizarCambio).
        abort_unless($usuario->can('autorizarCambio', $documento), 403);

        try {
            $this->incorporacion->autorizarCambio($documento, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Cambio autorizado. El colaborador ya puede subir la nueva version.']);
    }

    public function aprobarIncorporacion(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        // Misma acción que IncorporacionController::aprobar: acepta las DOS
        // familias de permiso (explorador de expedientes vs. bandeja de
        // incorporaciones) para no quitarle el acceso a ningún rol (P2-14).
        abort_unless($usuario->can('rh.expedientes.incorporacion.aprobar') || $usuario->can('rh.incorporaciones.aprobar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);

        try {
            $this->incorporacion->aprobarIncorporacion($colaborador, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['colaborador' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Incorporación aprobada: el colaborador quedó activo.']);
    }

    public function rechazarIncorporacion(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.incorporacion.rechazar') || $usuario->can('rh.incorporaciones.rechazar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarIncorporacion($colaborador, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Incorporación rechazada.']);
    }

    private function puedeVerDetalle(User $usuario, Colaborador $colaborador): bool
    {
        // Permiso móvil histórico o el de la web; el alcance siempre es el
        // del módulo «Expedientes» (mismo que la vista web del expediente).
        return ($usuario->can('rh.expedientes.detalle') || $this->alcance->puedeExplorarExpedientes($usuario))
            && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    /**
     * @return Builder<Colaborador>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return Colaborador::query()
            // Alcance del módulo «Expedientes», igual que el explorador web.
            ->tap(fn ($query) => $this->alcance->limitarExpedientesPorAlcance($query, $usuario))
            ->with(['sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre', 'departamento:id,nombre'])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                // Buscador único (nombre, número, puesto, sucursal…) o el
                // correo personal (quien ve expedientes sí puede verlo).
                $query->where(fn ($sub) => $sub
                    ->where(fn ($b) => $b->buscar($busqueda))
                    ->orWhere('correo_personal', 'like', "%{$busqueda}%"));
            })
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($sub) => $sub->where('empresa_id', $id)))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id));
    }
}
