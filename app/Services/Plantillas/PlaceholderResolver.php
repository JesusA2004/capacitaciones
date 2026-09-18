<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Prestamo;

/**
 * Unica fuente de verdad de que placeholder mapea a que dato (ver
 * claude/formatos/placeholders/PLACEHOLDERS.md). Si se agrega un placeholder
 * nuevo, debe registrarse aqui primero.
 *
 * El sujeto de un formato es un App\Models\Colaborador (persona/empleo) o un
 * App\Models\Candidato — nunca App\Models\User (cuenta de acceso, sin datos
 * personales/de empleo desde la separacion Usuario/Colaborador, ver
 * docs/ROLES_Y_NAVEGACION.md).
 */
class PlaceholderResolver
{
    /**
     * @param  array<string, mixed>  $extra  Placeholders adicionales especificos de una solicitud (fecha_inicio_permiso, motivo_permiso, etc.), fusionados sobre los calculados.
     * @return array<string, string>
     */
    public function resolver(Colaborador|Candidato|null $sujeto, array $extra = []): array
    {
        $base = [
            'nombre_colaborador' => '',
            'apellidos_colaborador' => '',
            'nombre_completo' => '',
            'numero_empleado' => '',
            'curp' => '',
            'rfc' => '',
            'nss' => '',
            'domicilio' => '',
            'telefono' => '',
            'telefono_corporativo' => '',
            'correo' => '',
            'correo_personal' => '',
            'correo_corporativo' => '',
            'empresa' => '',
            'sucursal' => '',
            'departamento' => '',
            'puesto' => '',
            'jefe_directo' => '',
            'fecha_ingreso' => '',
            'fecha_actual' => now()->format('d/m/Y'),
            'sueldo_mensual' => '',
            'sueldo_diario' => '',
            'dias_vacaciones' => '',
            'fecha_inicio_permiso' => '',
            'fecha_fin_permiso' => '',
            'motivo_permiso' => '',
            'tipo_solicitud' => '',
            'folio_solicitud' => '',
            'monto_prestamo' => '',
            'plazo_prestamo' => '',
            'pago_prestamo' => '',
            'saldo_prestamo' => '',
            'fecha_inicio_incapacidad' => '',
            'fecha_fin_incapacidad' => '',
            'motivo_solicitud' => '',
            'observaciones' => '',
        ];

        if ($sujeto instanceof Colaborador) {
            $base = [
                ...$base,
                ...$this->datosColaborador($sujeto),
                ...$this->placeholdersPrestamo($sujeto),
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

    /**
     * @return array<string, string>
     */
    private function datosColaborador(Colaborador $sujeto): array
    {
        $correoCorporativo = $sujeto->user?->email;
        $sueldoMensual = $sujeto->sueldo_mensual !== null ? (float) $sujeto->sueldo_mensual : null;

        return [
            'nombre_colaborador' => (string) $sujeto->name,
            'apellidos_colaborador' => (string) $sujeto->apellidos,
            'nombre_completo' => $sujeto->nombreCompleto(),
            'numero_empleado' => (string) $sujeto->numero_empleado,
            'curp' => (string) $sujeto->curp,
            'rfc' => (string) $sujeto->rfc,
            'nss' => (string) $sujeto->nss,
            'domicilio' => (string) $sujeto->domicilio,
            'telefono' => (string) $sujeto->telefono,
            'telefono_corporativo' => (string) $sujeto->telefono_corporativo,
            'correo' => (string) ($correoCorporativo ?? $sujeto->correo_personal ?? ''),
            'correo_personal' => (string) $sujeto->correo_personal,
            'correo_corporativo' => (string) ($correoCorporativo ?? ''),
            'empresa' => (string) $sujeto->empresa()?->nombre,
            'sucursal' => (string) $sujeto->sucursalPrincipal?->nombre,
            'departamento' => (string) $sujeto->departamento?->nombre,
            'puesto' => (string) $sujeto->puesto?->nombre,
            'jefe_directo' => $sujeto->jefe?->nombreCompleto() ?? '',
            'fecha_ingreso' => $sujeto->fecha_ingreso?->format('d/m/Y') ?? '',
            'sueldo_mensual' => $sueldoMensual !== null ? sprintf('$%s', number_format($sueldoMensual, 2)) : '',
            'sueldo_diario' => $sueldoMensual !== null ? sprintf('$%s', number_format($sueldoMensual / 30, 2)) : '',
        ];
    }

    /**
     * Placeholders del préstamo interno activo del colaborador
     * (monto_prestamo, plazo_prestamo, pago_prestamo, saldo_prestamo). Ver
     * App\Models\Prestamo / App\Services\Nomina\PrestamoService — nace en
     * 'pendiente_entrega', pasa a 'activo' al confirmar la entrega y a
     * 'liquidado' cuando el saldo llega a 0; solo el préstamo 'activo' (si
     * hay más de uno histórico, el más reciente) alimenta el contrato.
     *
     * @return array<string, string>
     */
    private function placeholdersPrestamo(Colaborador $sujeto): array
    {
        $vacio = [
            'monto_prestamo' => '',
            'plazo_prestamo' => '',
            'pago_prestamo' => '',
            'saldo_prestamo' => '',
        ];

        $prestamo = Prestamo::query()
            ->where('colaborador_id', $sujeto->id)
            ->where('estado', 'activo')
            ->latest()
            ->first();

        if ($prestamo === null) {
            return $vacio;
        }

        return [
            'monto_prestamo' => sprintf('$%s', number_format((float) $prestamo->monto_original, 2)),
            'plazo_prestamo' => sprintf('%s', $prestamo->plazo),
            'pago_prestamo' => sprintf('$%s', number_format((float) $prestamo->pago_programado, 2)),
            'saldo_prestamo' => sprintf('$%s', number_format((float) $prestamo->saldo, 2)),
        ];
    }
}
