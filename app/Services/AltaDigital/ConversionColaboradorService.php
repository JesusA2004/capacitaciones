<?php

namespace App\Services\AltaDigital;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\AltaDigital;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Reclutamiento\CvStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Convierte un alta digital aprobada en un colaborador real: crea el User,
 * traslada los documentos capturados durante la liga publica al expediente,
 * y cierra el ciclo del candidato/vacante de origen. Es la unica forma
 * valida de crear un colaborador a partir de un alta (el controlador no
 * debe construir el User directamente).
 */
class ConversionColaboradorService
{
    public function __construct(
        private readonly AltaDigitalStorageService $altaStorage,
        private readonly DocumentoStorageService $documentoStorage,
        private readonly CvStorageService $cvStorage,
        private readonly MovimientoLaboralService $movimientos,
    ) {}

    public function convertir(AltaDigital $alta, User $aprobadoPor): User
    {
        if ($alta->estado !== EstadoAltaDigital::Aprobada) {
            throw new RuntimeException('Solo un alta aprobada puede convertirse en colaborador.');
        }

        if ($alta->user_id !== null) {
            throw new RuntimeException('Esta alta ya fue convertida en colaborador.');
        }

        return DB::transaction(function () use ($alta, $aprobadoPor) {
            $usuario = User::create([
                'name' => $alta->nombre,
                'apellidos' => $alta->apellidos,
                'email' => $alta->correo,
                'password' => Hash::make(Str::random(40)),
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
                'contacto_emergencia_nombre' => $alta->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $alta->contacto_emergencia_telefono,
            ]);

            $usuario->assignRole('colaborador');

            if ($alta->foto_path !== null) {
                $nombreInterno = $this->documentoStorage->nombreInterno($alta->foto_original_name ?? 'foto.jpg');
                $rutaDestino = $this->documentoStorage->rutaFoto($usuario->id, $nombreInterno);

                $this->documentoStorage->disco()->put(
                    $rutaDestino,
                    $this->altaStorage->disco()->get($alta->foto_path),
                );

                $usuario->update(['foto_path' => $rutaDestino]);
            }

            foreach ($alta->documentos as $documentoAlta) {
                $nombreInterno = $this->documentoStorage->nombreInterno($documentoAlta->original_name);
                $rutaDestino = $this->documentoStorage->rutaDocumento($usuario->id, $nombreInterno);

                $this->documentoStorage->disco()->put(
                    $rutaDestino,
                    $this->altaStorage->disco()->get($documentoAlta->path),
                );

                EmployeeDocument::create([
                    'user_id' => $usuario->id,
                    'empresa_id' => $alta->empresa_id,
                    'sucursal_id' => $alta->sucursal_id,
                    'document_type_id' => $documentoAlta->document_type_id,
                    'disk' => config('expedientes.disk'),
                    'path' => $rutaDestino,
                    'original_name' => $documentoAlta->original_name,
                    'stored_name' => $nombreInterno,
                    'mime' => $documentoAlta->mime,
                    'size' => $documentoAlta->size,
                    'status' => EstadoDocumento::Cargado,
                    'uploaded_by' => $aprobadoPor->id,
                ]);
            }

            if ($alta->candidato?->cv_path) {
                $this->copiarCvDelCandidato($alta, $usuario, $aprobadoPor);
            }

            $alta->update([
                'user_id' => $usuario->id,
                'estado' => EstadoAltaDigital::ConvertidaAColaborador,
            ]);

            if ($alta->candidato) {
                $estadoAnterior = $alta->candidato->estado;
                $alta->candidato->update(['estado' => EstadoCandidato::Contratado]);
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
                $alta->vacante->update(['estado' => EstadoVacante::Cubierta]);
            }

            $this->movimientos->registrarAlta($usuario, $aprobadoPor, $alta, $alta->vacante_id);

            Password::broker()->sendResetLink(['email' => $usuario->email]);

            return $usuario;
        });
    }

    private function copiarCvDelCandidato(AltaDigital $alta, User $usuario, User $aprobadoPor): void
    {
        $tipoCv = DocumentType::query()->where('clave', 'cv')->first();

        if (! $tipoCv || ! $alta->candidato?->cv_path) {
            return;
        }

        $nombreInterno = $this->documentoStorage->nombreInterno($alta->candidato->cv_original_name ?? 'cv.pdf');
        $rutaDestino = $this->documentoStorage->rutaDocumento($usuario->id, $nombreInterno);

        $this->documentoStorage->disco()->put(
            $rutaDestino,
            $this->cvStorage->disco()->get($alta->candidato->cv_path),
        );

        EmployeeDocument::create([
            'user_id' => $usuario->id,
            'empresa_id' => $alta->empresa_id,
            'sucursal_id' => $alta->sucursal_id,
            'document_type_id' => $tipoCv->id,
            'disk' => config('expedientes.disk'),
            'path' => $rutaDestino,
            'original_name' => $alta->candidato->cv_original_name ?? 'cv.pdf',
            'stored_name' => $nombreInterno,
            'mime' => $alta->candidato->cv_mime,
            'size' => $alta->candidato->cv_size,
            'status' => EstadoDocumento::Cargado,
            'uploaded_by' => $aprobadoPor->id,
        ]);
    }
}
