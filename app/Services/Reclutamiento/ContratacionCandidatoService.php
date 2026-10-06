<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoVacante;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoContratacion;
use App\Enums\TipoSeguimientoCandidato;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Enlace Etapa 1 → Etapa 2. SOLO un candidato con autorización final de RH
 * registrada (aprobación `seleccion_candidato` / `autorizacion_rh`) entra a
 * contratación. En un solo paso, sin duplicar a la persona:
 *
 *  - crea el Colaborador (AltaColaboradorService: valida que CURP/RFC/NSS no
 *    pertenezcan a otra persona, fija expediente, contrato de periodo de
 *    prueba con su vencimiento por puesto) y lo enlaza al candidato;
 *  - ocupa la plaza de la vacante;
 *  - copia el CV al expediente;
 *  - genera la invitación QR (token no predecible, temporal, revocable, de
 *    un solo uso) LIGADA a ese candidato y a ese colaborador: al escanearla
 *    la persona crea su cuenta (User) sobre el mismo Colaborador.
 *
 * Bloquea la fila del candidato: dos solicitudes simultáneas nunca crean dos
 * colaboradores ni dos QR.
 */
class ContratacionCandidatoService
{
    public const PERMISO_CONTRATAR = 'candidatos.contratar';

    public function __construct(
        private readonly AltaColaboradorService $altas,
        private readonly DocumentoStorageService $expediente,
        private readonly CvStorageService $cvStorage,
        private readonly AuditoriaService $auditoria,
        private readonly AprobacionService $aprobaciones,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly IncorporacionInvitacionService $invitaciones,
        private readonly TareaService $tareas,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Datos laborales validados por ContratarCandidatoRequest.
     * @return array{colaborador: Colaborador, invitacion: IncorporacionInvitacion, token: string}
     */
    public function iniciarContratacion(Candidato $candidato, array $datos, User $actor): array
    {
        if (! $actor->can(self::PERMISO_CONTRATAR) || ! $this->jerarquia->alcanzaCandidato($actor, $candidato)) {
            throw new AuthorizationException('No tienes permiso para iniciar la contratación de este candidato.');
        }

        $this->exigirAutorizado($candidato);

        // Un espontáneo no puede avanzar a contratación sin vincularse antes
        // a una vacante real disponible (CLAUDE.md §3): nunca se inventa una
        // plaza para poder contratarlo.
        if ($candidato->vacante_id === null) {
            throw ValidationException::withMessages([
                'vacante_id' => 'Este candidato es espontáneo: vincúlalo a una vacante disponible antes de iniciar su contratación.',
            ]);
        }

        $datosAlta = [
            ...$datos,
            'name' => $datos['name'] ?? $candidato->nombre,
            'apellidos' => $datos['apellidos'] ?? $candidato->apellidos,
            'telefono' => $datos['telefono'] ?? $candidato->telefono,
            'email' => null,
            'crear_acceso' => false,
            'sucursal_principal_id' => $datos['sucursal_principal_id'] ?? $candidato->sucursal_id,
            'departamento_id' => $datos['departamento_id'] ?? $candidato->departamento_id,
            'puesto_id' => $datos['puesto_id'] ?? $candidato->puesto_objetivo_id,
            'vacante_id' => $datos['vacante_id'] ?? $candidato->vacante_id,
            'tipo_contratacion' => $datos['tipo_contratacion'] ?? TipoContratacion::CapacitacionInicial->value,
            'candidato_id' => $candidato->id,
        ];

        foreach (['sucursal_principal_id' => 'sucursal', 'puesto_id' => 'puesto'] as $campo => $etiqueta) {
            if (empty($datosAlta[$campo])) {
                throw ValidationException::withMessages([$campo => "Indica la {$etiqueta} del nuevo colaborador (el candidato no la tiene registrada)."]);
            }
        }

        $correoInvitacion = $datos['email'] ?? $candidato->correo;

        if (! empty($correoInvitacion) && User::query()->where('email', $correoInvitacion)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo: si es un reingreso, búscalo en Reingresos.']);
        }

        $colaborador = $this->altas->registrar($datosAlta, $actor, function (Colaborador $colaborador) use ($candidato, $actor, $datosAlta): void {
            /** Bloqueo del candidato: dos RH no pueden convertirlo a la vez. */
            $bloqueado = Candidato::query()->lockForUpdate()->findOrFail($candidato->id);

            if ($bloqueado->colaborador_id !== null || $bloqueado->estado !== EstadoCandidato::AutorizadoRh) {
                throw ValidationException::withMessages(['candidato' => 'Este candidato ya entró a contratación.']);
            }

            $bloqueado->update([
                'estado' => EstadoCandidato::EnContratacion,
                'etapa_maxima' => max($bloqueado->etapa_maxima, EstadoCandidato::EnContratacion->orden()),
                'colaborador_id' => $colaborador->id,
            ]);

            $bloqueado->seguimientos()->create([
                'tipo' => TipoSeguimientoCandidato::CambioEstado,
                'nota' => "Inició la contratación: colaborador {$colaborador->numero_empleado} creado y QR de registro generado.",
                'estado_anterior' => EstadoCandidato::AutorizadoRh->value,
                'estado_nuevo' => EstadoCandidato::EnContratacion->value,
                'fecha' => now(),
                'registrado_por' => $actor->id,
            ]);

            if (! empty($datosAlta['vacante_id'])) {
                $this->ocuparPlaza((int) $datosAlta['vacante_id'], $bloqueado, $colaborador);
            }

            $this->copiarCv($bloqueado, $colaborador, $actor);
        });

        ['invitacion' => $invitacion, 'token' => $token] = $this->invitaciones->crear([
            'candidato_id' => $candidato->id,
            'colaborador_id' => $colaborador->id,
            'email' => $correoInvitacion,
            'telefono' => $colaborador->telefono,
            'nombre_prellenado' => $colaborador->nombreCompleto(),
            'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'departamento_id' => $colaborador->departamento_id,
            'puesto_id' => $colaborador->puesto_id,
            'duracion_horas' => $datos['duracion_horas'] ?? null,
        ], $actor);

        $this->tareas->resolver(TipoTarea::deReclutamiento(), $candidato, $actor);
        $this->auditoria->registrar('candidato_contratacion_iniciada', $candidato, $actor, [
            'candidato_id' => $candidato->id,
            'colaborador_id' => $colaborador->id,
            'invitacion_id' => $invitacion->id,
        ]);

        return ['colaborador' => $colaborador, 'invitacion' => $invitacion, 'token' => $token];
    }

    /**
     * Compatibilidad con POST /api/v1/rh/candidatos/{candidato}/contratar:
     * misma regla (solo candidatos autorizados por RH), misma implementación.
     *
     * @param  array<string, mixed>  $datos
     */
    public function contratar(Candidato $candidato, array $datos, User $actor): Colaborador
    {
        return $this->iniciarContratacion($candidato, $datos, $actor)['colaborador'];
    }

    /**
     * Cierra la Etapa 1 del candidato cuando su colaborador terminó la
     * contratación (contratos firmados). Idempotente.
     */
    public function marcarContratado(Colaborador $colaborador, ?User $actor = null): void
    {
        if ($colaborador->candidato_id === null) {
            return;
        }

        try {
            DB::transaction(function () use ($colaborador, $actor): void {
                $candidato = Candidato::query()->lockForUpdate()->find($colaborador->candidato_id);

                if ($candidato === null || $candidato->estado !== EstadoCandidato::EnContratacion) {
                    return;
                }

                $candidato->update([
                    'estado' => EstadoCandidato::Contratado,
                    'etapa_maxima' => EstadoCandidato::Contratado->orden(),
                    'contratado_en' => now(),
                ]);

                $candidato->seguimientos()->create([
                    'tipo' => TipoSeguimientoCandidato::CambioEstado,
                    'nota' => 'Contratado: contratos firmados, inicia onboarding.',
                    'estado_anterior' => EstadoCandidato::EnContratacion->value,
                    'estado_nuevo' => EstadoCandidato::Contratado->value,
                    'fecha' => now(),
                    'registrado_por' => $actor?->id,
                ]);
            });

            $this->auditoria->registrar('candidato_contratado', $colaborador, $actor, ['candidato_id' => $colaborador->candidato_id, 'colaborador_id' => $colaborador->id]);
        } catch (Throwable $e) {
            Log::warning('ContratacionCandidatoService: no se pudo marcar al candidato como contratado.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }
    }

    private function exigirAutorizado(Candidato $candidato): void
    {
        if ($candidato->colaborador_id !== null || $candidato->estado === EstadoCandidato::EnContratacion) {
            throw ValidationException::withMessages(['candidato' => 'Este candidato ya entró a contratación: si el QR venció, regenéralo desde su invitación.']);
        }

        if ($candidato->estado !== EstadoCandidato::AutorizadoRh || ! $this->aprobaciones->estaAutorizadoPorRh($candidato, ProcesoAprobacion::SeleccionCandidato)) {
            throw ValidationException::withMessages([
                'candidato' => "El candidato está en «{$candidato->estado->etiqueta()}»: solo se contrata con la autorización final de RH registrada.",
            ]);
        }
    }

    /**
     * plaza autorizada → vacante → candidato contratado → plaza ocupada.
     */
    private function ocuparPlaza(int $vacanteId, Candidato $candidato, Colaborador $colaborador): void
    {
        $vacante = Vacante::query()->lockForUpdate()->find($vacanteId);

        if ($vacante === null || in_array($vacante->estado, [EstadoVacante::Cubierta, EstadoVacante::Cancelada], true)) {
            return;
        }

        $cubiertas = $vacante->plazas_cubiertas + 1;
        $requeridas = max($vacante->plazas_requeridas, 1);
        $completa = $cubiertas >= $requeridas;

        $vacante->update([
            'candidato_contratado_id' => $candidato->id,
            'colaborador_contratado_id' => $colaborador->id,
            'plazas_cubiertas' => $cubiertas,
            'plazas_disponibles' => max($requeridas - $cubiertas, 0),
            'estado' => $completa ? EstadoVacante::Cubierta : $vacante->estado,
            'fecha_cierre' => $completa ? now()->toDateString() : null,
        ]);
    }

    private function copiarCv(Candidato $candidato, Colaborador $colaborador, User $actor): void
    {
        if ($candidato->cv_path === null) {
            return;
        }

        $tipoCv = DocumentType::query()->where('clave', 'cv')->first();

        if ($tipoCv === null) {
            Log::warning('ContratacionCandidatoService: no existe el tipo documental "cv"; el CV no se copió al expediente.', ['candidato_id' => $candidato->id]);

            return;
        }

        try {
            $nombreOriginal = $candidato->cv_original_name ?? 'cv.pdf';
            $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
            $ruta = $this->expediente->rutaDocumento($colaborador, $tipoCv, 1, $extension);
            $contenido = $this->cvStorage->disco()->get($candidato->cv_path);

            if ($contenido === null) {
                return;
            }

            $this->expediente->disco()->put($ruta, $contenido);

            EmployeeDocument::query()->create([
                'colaborador_id' => $colaborador->id,
                'user_id' => $colaborador->user?->id,
                'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                'sucursal_id' => $colaborador->sucursal_principal_id,
                'document_type_id' => $tipoCv->id,
                'disk' => config('expedientes.disk'),
                'path' => $ruta,
                'original_name' => $nombreOriginal,
                'stored_name' => basename($ruta),
                'mime' => $candidato->cv_mime,
                'extension' => $extension !== '' ? $extension : null,
                'size' => $candidato->cv_size,
                'hash' => hash('sha256', $contenido),
                'version' => 1,
                'status' => EstadoDocumento::Cargado,
                'uploaded_by' => $actor->id,
            ]);
        } catch (Throwable $e) {
            // El CV es un documento de apoyo: si el NAS falla al copiarlo, el
            // alta no se revierte (el CV sigue en el candidato).
            Log::warning('ContratacionCandidatoService: no se pudo copiar el CV al expediente.', ['candidato_id' => $candidato->id, 'error' => $e->getMessage()]);
        }
    }
}
