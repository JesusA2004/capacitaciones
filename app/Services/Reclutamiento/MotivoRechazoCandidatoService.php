<?php

namespace App\Services\Reclutamiento;

use App\Models\MotivoRechazoCandidato;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;

/**
 * Catálogo administrable de motivos de rechazo (CLAUDE.md §10). Nunca
 * expone un destroy: un motivo ya usado se desactiva, no se borra.
 */
class MotivoRechazoCandidatoService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarMotivoRechazoCandidatoRequest.
     */
    public function guardar(array $datos, User $actor, ?MotivoRechazoCandidato $motivo = null): MotivoRechazoCandidato
    {
        $atributos = [
            'clave' => (string) $datos['clave'],
            'nombre' => (string) $datos['nombre'],
            'activo' => (bool) ($datos['activo'] ?? true),
            'no_recontratable_por_defecto' => (bool) ($datos['no_recontratable_por_defecto'] ?? false),
        ];

        if ($motivo === null) {
            $motivo = MotivoRechazoCandidato::query()->create($atributos);
        } else {
            $motivo->update($atributos);
        }

        $this->auditoria->registrar('motivo_rechazo_candidato_guardado', $motivo, $actor, ['clave' => $motivo->clave]);

        return $motivo;
    }
}
