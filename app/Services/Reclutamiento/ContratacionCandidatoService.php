<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoVacante;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Enlace reclutamiento → administración de personal. Convierte a un
 * candidato "listo para contratación" (o ya marcado "contratado" en el
 * tablero) en colaborador SIN duplicar la persona:
 *
 *  - los datos del candidato (nombre, contacto, empresa/sucursal/puesto
 *    objetivo, vacante) alimentan el alta; RH solo completa lo laboral
 *    (sueldo, fecha de ingreso, jefe, tipo de contratación...);
 *  - el candidato queda "contratado" y enlazado al colaborador
 *    (candidatos.colaborador_id, colaboradores.candidato_id) con seguimiento;
 *  - la vacante registra candidato/colaborador contratado y, si ya no quedan
 *    plazas, se cierra como cubierta con fecha de cierre;
 *  - el CV pasa al expediente;
 *  - el resto (expediente, checklist, contrato y documentos contractuales,
 *    acceso) lo hace AltaColaboradorService en la misma transacción.
 */
class ContratacionCandidatoService
{
    public function __construct(
        private readonly AltaColaboradorService $altas,
        private readonly DocumentoStorageService $expediente,
        private readonly CvStorageService $cvStorage,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Datos laborales validados por ContratarCandidatoRequest.
     */
    public function contratar(Candidato $candidato, array $datos, User $actor): Colaborador
    {
        if ($candidato->colaborador_id !== null) {
            throw ValidationException::withMessages(['candidato' => 'Este candidato ya fue convertido en colaborador.']);
        }

        if (! in_array($candidato->estado, [EstadoCandidato::ListoParaContratacion, EstadoCandidato::OfertaAprobacion, EstadoCandidato::Contratado], true)) {
            throw ValidationException::withMessages([
                'candidato' => "El candidato está en «{$candidato->estado->etiqueta()}»: solo se contrata desde oferta aprobada, listo para contratación o contratado.",
            ]);
        }

        $datosAlta = [
            ...$datos,
            'name' => $datos['name'] ?? $candidato->nombre,
            'apellidos' => $datos['apellidos'] ?? $candidato->apellidos,
            'telefono' => $datos['telefono'] ?? $candidato->telefono,
            'email' => array_key_exists('email', $datos) ? $datos['email'] : $candidato->correo,
            'sucursal_principal_id' => $datos['sucursal_principal_id'] ?? $candidato->sucursal_id,
            'departamento_id' => $datos['departamento_id'] ?? $candidato->departamento_id,
            'puesto_id' => $datos['puesto_id'] ?? $candidato->puesto_objetivo_id,
            'vacante_id' => $datos['vacante_id'] ?? $candidato->vacante_id,
            'candidato_id' => $candidato->id,
        ];

        foreach (['sucursal_principal_id' => 'sucursal', 'puesto_id' => 'puesto'] as $campo => $etiqueta) {
            if (empty($datosAlta[$campo])) {
                throw ValidationException::withMessages([$campo => "Indica la {$etiqueta} del nuevo colaborador (el candidato no la tiene registrada)."]);
            }
        }

        if (! empty($datosAlta['email']) && User::query()->where('email', $datosAlta['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo.']);
        }

        $colaborador = $this->altas->registrar($datosAlta, $actor, function (Colaborador $colaborador) use ($candidato, $actor, $datosAlta): void {
            /** Bloqueo del candidato: dos RH no pueden convertirlo a la vez. */
            $bloqueado = Candidato::query()->lockForUpdate()->findOrFail($candidato->id);

            if ($bloqueado->colaborador_id !== null) {
                throw ValidationException::withMessages(['candidato' => 'Este candidato ya fue convertido en colaborador.']);
            }

            $estadoAnterior = $bloqueado->estado;
            $bloqueado->update([
                'estado' => EstadoCandidato::Contratado,
                'colaborador_id' => $colaborador->id,
                'contratado_en' => now(),
            ]);

            $bloqueado->seguimientos()->create([
                'tipo' => TipoSeguimientoCandidato::CambioEstado,
                'nota' => "Contratado: convertido en colaborador {$colaborador->numero_empleado}.",
                'estado_anterior' => $estadoAnterior->value,
                'estado_nuevo' => EstadoCandidato::Contratado->value,
                'fecha' => now(),
                'registrado_por' => $actor->id,
            ]);

            if (! empty($datosAlta['vacante_id'])) {
                $this->ocuparPlaza((int) $datosAlta['vacante_id'], $bloqueado, $colaborador);
            }

            $this->copiarCv($bloqueado, $colaborador, $actor);
        });

        $this->auditoria->registrar('candidato_contratado', $candidato, $actor, ['colaborador_id' => $colaborador->id]);

        return $colaborador;
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
