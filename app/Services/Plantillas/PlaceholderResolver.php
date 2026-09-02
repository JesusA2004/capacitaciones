<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\User;

/**
 * Unica fuente de verdad de que placeholder mapea a que dato (ver
 * claude/formatos/placeholders/PLACEHOLDERS.md). Si se agrega un placeholder
 * nuevo, debe registrarse aqui primero.
 */
class PlaceholderResolver
{
    /**
     * @param  array<string, mixed>  $extra  Placeholders adicionales especificos de una solicitud (fecha_inicio_permiso, motivo_permiso, etc.), fusionados sobre los calculados.
     * @return array<string, string>
     */
    public function resolver(User|Candidato|null $sujeto, array $extra = []): array
    {
        $base = [
            'nombre_colaborador' => '',
            'apellidos_colaborador' => '',
            'nombre_completo' => '',
            'curp' => '',
            'rfc' => '',
            'nss' => '',
            'domicilio' => '',
            'telefono' => '',
            'correo' => '',
            'empresa' => '',
            'sucursal' => '',
            'departamento' => '',
            'puesto' => '',
            'jefe_directo' => '',
            'fecha_ingreso' => '',
            'fecha_actual' => now()->format('d/m/Y'),
            'dias_vacaciones' => '',
            'fecha_inicio_permiso' => '',
            'fecha_fin_permiso' => '',
            'motivo_permiso' => '',
            'tipo_solicitud' => '',
            'folio_solicitud' => '',
        ];

        if ($sujeto instanceof User) {
            $base = [
                ...$base,
                'nombre_colaborador' => (string) $sujeto->name,
                'apellidos_colaborador' => (string) $sujeto->apellidos,
                'nombre_completo' => $sujeto->nombreCompleto(),
                'curp' => (string) $sujeto->curp,
                'rfc' => (string) $sujeto->rfc,
                'nss' => (string) $sujeto->nss,
                'domicilio' => (string) $sujeto->domicilio,
                'telefono' => (string) $sujeto->telefono,
                'correo' => (string) $sujeto->email,
                'empresa' => (string) $sujeto->empresa()?->nombre,
                'sucursal' => (string) $sujeto->sucursalPrincipal?->nombre,
                'departamento' => (string) $sujeto->departamento?->nombre,
                'puesto' => (string) $sujeto->puesto?->nombre,
                'jefe_directo' => $sujeto->jefe?->nombreCompleto() ?? '',
                'fecha_ingreso' => $sujeto->fecha_ingreso?->format('d/m/Y') ?? '',
            ];
        }

        if ($sujeto instanceof Candidato) {
            $base = [
                ...$base,
                'nombre_colaborador' => (string) $sujeto->nombre,
                'apellidos_colaborador' => (string) $sujeto->apellidos,
                'nombre_completo' => $sujeto->nombreCompleto(),
                'telefono' => (string) $sujeto->telefono,
                'correo' => (string) $sujeto->correo,
                'empresa' => (string) $sujeto->empresa?->nombre,
                'sucursal' => (string) $sujeto->sucursal?->nombre,
                'departamento' => (string) $sujeto->departamento?->nombre,
                'puesto' => (string) $sujeto->puestoObjetivo?->nombre,
            ];
        }

        foreach ($extra as $clave => $valor) {
            $base[$clave] = (string) $valor;
        }

        return $base;
    }
}
