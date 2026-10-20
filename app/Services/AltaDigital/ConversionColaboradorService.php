<?php

namespace App\Services\AltaDigital;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use App\Services\Colaboradores\AvisoAltaBajaService;
use App\Services\Colaboradores\DomicilioService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Reclutamiento\CvStorageService;
use App\Support\FechaMexico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Convierte un alta digital aprobada en un colaborador real: crea el
 * Colaborador (y su cuenta de acceso, siempre — quien pasa por Alta digital
 * siempre necesita entrar a la app móvil), traslada los documentos
 * capturados durante la liga publica al expediente, y cierra el ciclo del
 * candidato/vacante de origen. Es la unica forma valida de crear un
 * colaborador a partir de un alta (el controlador no debe construir el
 * Colaborador/User directamente).
 */
class ConversionColaboradorService
{
    public function __construct(
        private readonly AltaDigitalStorageService $altaStorage,
        private readonly DocumentoStorageService $documentoStorage,
        private readonly CvStorageService $cvStorage,
        private readonly MovimientoLaboralService $movimientos,
        private readonly AsignacionService $asignaciones,
        private readonly AvisoAltaBajaService $avisoAltaBaja,
    ) {}

    public function convertir(AltaDigital $alta, User $aprobadoPor): User
    {
        if ($alta->estado !== EstadoAltaDigital::Aprobada) {
            throw new RuntimeException('Solo un alta aprobada puede convertirse en colaborador.');
        }

        if ($alta->user_id !== null) {
            throw new RuntimeException('Esta alta ya fue convertida en colaborador.');
        }

        $usuario = DB::transaction(function () use ($alta, $aprobadoPor) {
            $colaborador = Colaborador::create([
                'name' => $alta->nombre,
                'apellidos' => $alta->apellidos,
                'telefono' => $alta->telefono,
                'sucursal_principal_id' => $alta->sucursal_id,
                'departamento_id' => $alta->departamento_id,
                'puesto_id' => $alta->puesto_id,
                'fecha_ingreso' => $alta->fecha_ingreso_propuesta,
                'estatus' => EstadoUsuario::Activo,
                'fecha_nacimiento' => $alta->fecha_nacimiento,
                'curp' => $alta->curp,
                'rfc' => $alta->rfc,
                'nss' => $alta->nss,
                'domicilio' => $alta->domicilio,
                // Domicilio estructurado tal cual lo capturó la persona.
                ...app(DomicilioService::class)->valores($alta),
                'contacto_emergencia_nombre' => $alta->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $alta->contacto_emergencia_telefono,
                'candidato_id' => $alta->candidato_id,
                'alta_registrada_por' => $aprobadoPor->id,
            ]);

            $usuario = User::create([
                'colaborador_id' => $colaborador->id,
                'name' => $alta->nombre,
                'apellidos' => $alta->apellidos,
                // Opcional y nunca inventado; si otra cuenta ya lo usa, se omite.
                'email' => $alta->correo !== null && User::withTrashed()->where('email', $alta->correo)->doesntExist() ? $alta->correo : null,
                'password' => Hash::make(Str::random(40)),
            ]);

            $usuario->assignRole('colaborador');

            // Capacitación (oculta tras el feature flag, ver
            // docs/CAPACITACION_PROXIMAMENTE.md): mismo llamado que
            // AltaColaboradorService::registrar() — un colaborador nuevo
            // entra ya inscrito en las asignaciones vigentes de su
            // sucursal/departamento/puesto/rol.
            $this->asignaciones->aplicarVigentesA($usuario);

            // Recargar con las relaciones que rutaBaseColaborador() necesita
            // para construir la carpeta legible del colaborador (empresa,
            // sucursal) — el Colaborador recién creado no las trae cargadas.
            $colaborador->loadMissing(['sucursalPrincipal.empresa']);
            $colaborador->setRelation('user', $usuario);

            if ($alta->foto_path !== null) {
                $extension = pathinfo($alta->foto_original_name ?? 'foto.jpg', PATHINFO_EXTENSION);
                $rutaDestino = $this->documentoStorage->rutaFoto($colaborador, $extension);

                // Copia verificada (SHA-256) del alta al expediente: si el NAS
                // no confirma la escritura, copiarDesdeDisco() lanza y toda la
                // conversión se revierte — nunca se deja foto_path apuntando a
                // un archivo inexistente (CLAUDE.md: confirmar antes de persistir).
                $this->documentoStorage->copiarDesdeDisco($this->altaStorage->disco(), $alta->foto_path, $rutaDestino);

                $colaborador->update(['foto_path' => $rutaDestino]);
            }

            foreach ($alta->documentos as $documentoAlta) {
                $tipoDocumento = DocumentType::query()->findOrFail($documentoAlta->document_type_id);
                $extension = pathinfo($documentoAlta->original_name, PATHINFO_EXTENSION);
                $rutaDestino = $this->documentoStorage->rutaDocumento($colaborador, $tipoDocumento, 1, $extension);

                // Copia verificada antes de registrar la fila: si la escritura
                // al NAS no se confirma, lanza y revierte (nunca un
                // EmployeeDocument que apunte a un archivo que no se guardó).
                $copia = $this->documentoStorage->copiarDesdeDisco($this->altaStorage->disco(), $documentoAlta->path, $rutaDestino);

                EmployeeDocument::create([
                    'colaborador_id' => $colaborador->id,
                    'user_id' => $usuario->id,
                    'empresa_id' => $alta->empresa_id,
                    'sucursal_id' => $alta->sucursal_id,
                    'document_type_id' => $documentoAlta->document_type_id,
                    'disk' => config('expedientes.disk'),
                    'path' => $rutaDestino,
                    'original_name' => $documentoAlta->original_name,
                    'stored_name' => basename($rutaDestino),
                    'mime' => $documentoAlta->mime,
                    'extension' => $extension !== '' ? $extension : null,
                    'size' => $documentoAlta->size,
                    'hash' => $copia['hash'],
                    'version' => 1,
                    'status' => EstadoDocumento::Cargado,
                    'uploaded_by' => $aprobadoPor->id,
                ]);
            }

            if ($alta->candidato?->cv_path) {
                $this->copiarCvDelCandidato($alta, $colaborador, $usuario, $aprobadoPor);
            }

            $alta->update([
                'user_id' => $usuario->id,
                'colaborador_id' => $colaborador->id,
                'estado' => EstadoAltaDigital::ConvertidaAColaborador,
            ]);

            if ($alta->candidato) {
                $estadoAnterior = $alta->candidato->estado;
                $alta->candidato->update([
                    'estado' => EstadoCandidato::Contratado,
                    'colaborador_id' => $colaborador->id,
                    'contratado_en' => now(),
                ]);
                $alta->candidato->seguimientos()->create([
                    'tipo' => TipoSeguimientoCandidato::CambioEstado,
                    'nota' => 'Alta digital aprobada: candidato convertido en colaborador.',
                    'estado_anterior' => $estadoAnterior->value,
                    'estado_nuevo' => EstadoCandidato::Contratado->value,
                    'fecha' => now(),
                    'registrado_por' => $aprobadoPor->id,
                ]);
            }

            if ($alta->vacante && ! in_array($alta->vacante->estado, [EstadoVacante::Cubierta, EstadoVacante::Cancelada], true)) {
                $alta->vacante->update([
                    'estado' => EstadoVacante::Cubierta,
                    'candidato_contratado_id' => $alta->candidato_id,
                    'colaborador_contratado_id' => $colaborador->id,
                    'fecha_cierre' => FechaMexico::hoy(),
                ]);
            }

            $this->movimientos->registrarAlta($colaborador, $aprobadoPor, $alta, $alta->vacante_id);

            return $usuario;
        });

        // ALTA → RH + Sistemas, ya fuera de la transacción.
        if ($usuario->colaborador !== null) {
            $this->avisoAltaBaja->alta($usuario->colaborador);
        }

        // Enlace de acceso FUERA de la transacción y envuelto en try/catch:
        // un fallo de SMTP (o del broker) nunca debe revertir el colaborador,
        // su usuario ni sus documentos ya creados (mismo patrón que
        // AltaColaboradorService::registrar). Sin correo no hay enlace: RH
        // entrega el acceso con «Generar credenciales».
        if ($usuario->email !== null) {
            try {
                Password::broker()->sendResetLink(['email' => $usuario->email]);
            } catch (Throwable $e) {
                Log::warning('ConversionColaboradorService: no se pudo enviar el correo de acceso.', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $usuario;
    }

    private function copiarCvDelCandidato(AltaDigital $alta, Colaborador $colaborador, User $usuario, User $aprobadoPor): void
    {
        $tipoCv = DocumentType::query()->where('clave', 'cv')->first();

        if (! $tipoCv || ! $alta->candidato?->cv_path) {
            return;
        }

        $nombreOriginal = $alta->candidato->cv_original_name ?? 'cv.pdf';
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $rutaDestino = $this->documentoStorage->rutaDocumento($colaborador, $tipoCv, 1, $extension);

        // Copia verificada del CV del candidato al expediente (lanza y
        // revierte si el NAS no confirma la escritura).
        $copia = $this->documentoStorage->copiarDesdeDisco($this->cvStorage->disco(), $alta->candidato->cv_path, $rutaDestino);

        EmployeeDocument::create([
            'colaborador_id' => $colaborador->id,
            'user_id' => $usuario->id,
            'empresa_id' => $alta->empresa_id,
            'sucursal_id' => $alta->sucursal_id,
            'document_type_id' => $tipoCv->id,
            'disk' => config('expedientes.disk'),
            'path' => $rutaDestino,
            'original_name' => $nombreOriginal,
            'stored_name' => basename($rutaDestino),
            'mime' => $alta->candidato->cv_mime,
            'extension' => $extension !== '' ? $extension : null,
            'size' => $alta->candidato->cv_size,
            'hash' => $copia['hash'],
            'version' => 1,
            'status' => EstadoDocumento::Cargado,
            'uploaded_by' => $aprobadoPor->id,
        ]);
    }
}
