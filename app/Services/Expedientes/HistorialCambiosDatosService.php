<?php

namespace App\Services\Expedientes;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\Models\Activity;

/**
 * «Historial de cambios» del expediente: cada cambio administrativo de los
 * datos de la persona (edición completa desde el expediente, edición rápida
 * del Directorio, movimientos desde el Organigrama y correcciones aplicadas
 * por RH a partir de una solicitud) con antes/después, quién, cuándo,
 * módulo y motivo. Solo lee la bitácora de negocio (activity_log, log «rh»)
 * que escriben DatosExpedienteService y ActualizacionDatosService: nunca
 * una tabla paralela.
 *
 * Los datos médicos se guardan cifrados en la bitácora y solo se descifran
 * para quien tiene `expedientes.datos_medicos.ver`.
 */
class HistorialCambiosDatosService
{
    /** Corrección aplicada por RH desde una solicitud (ActualizacionDatosService). */
    public const EVENTO_CORRECCION = 'expediente_datos_actualizados';

    private const MODULOS = [
        'expediente' => 'Expediente',
        'directorio' => 'Directorio',
        'organigrama' => 'Organigrama',
        'solicitud' => 'Solicitud de corrección',
        'app' => 'App móvil',
    ];

    /**
     * @return list<array{id: int, fecha: string|null, por: string|null, modulo: string, motivo: string|null, solicitud: string|null, cambios: list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, critico: bool, confidencial: bool}>}>
     */
    public function paraColaborador(Colaborador $colaborador, User $viewer, int $limite = 50): array
    {
        $veMedicos = $viewer->can('expedientes.datos_medicos.ver');

        $actividades = Activity::query()
            ->where('log_name', AuditoriaService::LOG)
            ->where('subject_type', $colaborador->getMorphClass())
            ->where('subject_id', $colaborador->id)
            ->whereIn('event', [DatosExpedienteService::EVENTO_PERSONALES, DatosExpedienteService::EVENTO_LABORALES, self::EVENTO_CORRECCION])
            ->with('causer')
            ->latest('id')
            ->limit($limite)
            ->get();

        $historial = [];

        foreach ($actividades as $actividad) {
            $propiedades = $actividad->properties?->toArray() ?? [];
            $evento = (string) $actividad->event;
            $causer = $actividad->causer;
            $cambios = [];

            // Dos formas en la bitácora: lista [{campo, antes, despues…}]
            // (DatosExpedienteService) o mapa {campo: {antes, despues}}
            // (corrección aplicada, ActualizacionDatosService).
            foreach ((array) ($propiedades['cambios'] ?? []) as $llave => $cambio) {
                if (is_array($cambio) && ! isset($cambio['campo']) && is_string($llave)) {
                    $cambio['campo'] = $llave;
                }

                if (! is_array($cambio) || ! is_string($cambio['campo'] ?? null)) {
                    continue;
                }

                $confidencial = (bool) ($cambio['confidencial'] ?? false);
                $cambios[] = [
                    'campo' => $cambio['campo'],
                    'etiqueta' => is_string($cambio['etiqueta'] ?? null) ? $cambio['etiqueta'] : DatosExpedienteService::etiquetaCampo($cambio['campo']),
                    'antes' => $this->valor($cambio['antes'] ?? null, $confidencial, $veMedicos),
                    'despues' => $this->valor($cambio['despues'] ?? null, $confidencial, $veMedicos),
                    'critico' => (bool) ($cambio['critico'] ?? false),
                    'confidencial' => $confidencial,
                ];
            }

            // Corrección aplicada desde una solicitud: la bitácora antigua
            // solo guardaba los campos (el antes/después vive en la solicitud).
            if ($cambios === [] && $evento === self::EVENTO_CORRECCION) {
                foreach ((array) ($propiedades['campos'] ?? []) as $campo) {
                    if (is_string($campo)) {
                        $cambios[] = ['campo' => $campo, 'etiqueta' => DatosExpedienteService::etiquetaCampo($campo), 'antes' => null, 'despues' => null, 'critico' => in_array($campo, DatosExpedienteService::CAMPOS_CRITICOS, true), 'confidencial' => false];
                    }
                }
            }

            $modulo = $evento === self::EVENTO_CORRECCION ? 'solicitud' : (string) ($propiedades['modulo'] ?? 'expediente');

            $historial[] = [
                'id' => (int) $actividad->id,
                'fecha' => $actividad->created_at?->toIso8601String(),
                'por' => $causer instanceof User ? $causer->nombreCompleto() : null,
                'modulo' => self::MODULOS[$modulo] ?? ucfirst($modulo),
                'motivo' => $this->motivo($propiedades),
                'solicitud' => is_string($propiedades['solicitud'] ?? null) ? $propiedades['solicitud'] : null,
                'cambios' => $cambios,
            ];
        }

        return $historial;
    }

    /**
     * @param  array<mixed>  $propiedades
     */
    private function motivo(array $propiedades): ?string
    {
        foreach (['motivo', 'comentario'] as $clave) {
            if (is_string($propiedades[$clave] ?? null) && trim($propiedades[$clave]) !== '') {
                return $propiedades[$clave];
            }
        }

        return null;
    }

    private function valor(mixed $valor, bool $confidencial, bool $veMedicos): ?string
    {
        if (! is_scalar($valor) || (string) $valor === '') {
            return null;
        }

        if (! $confidencial) {
            return (string) $valor;
        }

        if (! $veMedicos) {
            return 'Confidencial';
        }

        try {
            return Crypt::decryptString((string) $valor);
        } catch (DecryptException) {
            return 'Confidencial';
        }
    }
}
