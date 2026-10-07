<?php

namespace App\Services\DocumentosAdministrativos;

use App\Enums\FamiliaAdministrativa;
use App\Enums\TipoConceptoNomina;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use Illuminate\Support\Carbon;

/**
 * DATOS de cada documento administrativo, ya calculados por su servicio de
 * negocio y solo formateados para mostrarse (montos «$1,234.56», fechas
 * dd/mm/aaaa). Nada de aquí lo puede cambiar el diseño: la plantilla solo
 * decide cómo se ve. Los datos de ejemplo de la vista previa son
 * ficticios y evidentes (nunca de una persona real).
 */
class DatosDocumentoAdministrativo
{
    /**
     * @return array<string, mixed>
     */
    public function recibo(ReciboNomina $recibo): array
    {
        $recibo->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal.empresa']);
        $conceptos = $recibo->conceptos()->orderBy('orden')->get();

        $lista = fn (TipoConceptoNomina $tipo, array $respaldo) => $conceptos->isNotEmpty()
            ? array_values($conceptos->where('tipo', $tipo)->map(fn ($c) => ['concepto' => (string) $c->concepto, 'importe' => $this->dinero((float) $c->importe)])->all())
            : array_map(fn (array $c) => ['concepto' => (string) ($c['concepto'] ?? ''), 'importe' => $this->dinero((float) ($c['monto'] ?? 0))], $respaldo);

        return [
            'colaborador' => $this->colaborador($recibo->colaborador),
            'empresa_razon_social' => $this->empresaRazonSocial($recibo->colaborador),
            'periodo' => ['inicio' => $this->fecha($recibo->periodo_inicio), 'fin' => $this->fecha($recibo->periodo_fin)],
            'fecha_pago' => $this->fecha($recibo->fecha_pago),
            'folio' => (string) ($recibo->folio ?? $recibo->id),
            'percepciones' => $lista(TipoConceptoNomina::Percepcion, (array) ($recibo->percepciones ?? [])),
            'deducciones' => $lista(TipoConceptoNomina::Deduccion, (array) ($recibo->deducciones ?? [])),
            'total_percepciones' => $this->dinero((float) $recibo->total_percepciones),
            'total_deducciones' => $this->dinero((float) $recibo->total_deducciones),
            'neto' => $this->dinero((float) $recibo->neto),
            'observaciones' => (string) ($recibo->observaciones ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $desglose  FiniquitoService::desglose()
     * @return array<string, mixed>
     */
    public function finiquito(FiniquitoCalculo $finiquito, array $desglose): array
    {
        $finiquito->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal.empresa', 'solicitudInterna']);
        $renglones = fn (string $tipo) => array_values(array_map(
            fn (array $r) => ['concepto' => (string) $r['concepto'].(($r['observaciones'] ?? null) && ($r['origen'] ?? '') === 'manual' ? ' — '.$r['observaciones'] : ''), 'importe' => $this->dinero((float) $r['importe'])],
            array_filter($desglose, fn (array $r) => ($r['tipo'] ?? null) === $tipo),
        ));

        return [
            'colaborador' => $this->colaborador($finiquito->colaborador),
            'empresa_razon_social' => $this->empresaRazonSocial($finiquito->colaborador),
            'folio' => (string) ($finiquito->solicitudInterna->folio ?? $finiquito->id),
            'fecha_ingreso' => $this->fecha($finiquito->fecha_ingreso),
            'fecha_baja' => $this->fecha($finiquito->fecha_baja),
            'antiguedad' => sprintf('%d año(s), %d mes(es)', (int) $finiquito->antiguedad_anios, (int) $finiquito->antiguedad_meses),
            'sueldo_mensual' => $this->dinero((float) $finiquito->sueldo_mensual),
            'sueldo_diario' => $this->dinero((float) $finiquito->sueldo_diario),
            'percepciones' => $renglones(TipoConceptoNomina::Percepcion->value),
            'deducciones' => $renglones(TipoConceptoNomina::Deduccion->value),
            'total_percepciones' => $this->dinero((float) $finiquito->total_percepciones),
            'total_deducciones' => $this->dinero((float) $finiquito->total_deducciones),
            'neto' => $this->dinero((float) ($finiquito->neto ?? $finiquito->total_ajustado)),
            'observaciones' => (string) ($finiquito->comentarios_ajuste ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comprobante(SolicitudInterna $solicitud, Colaborador $colaborador): array
    {
        $solicitud->loadMissing(['revisadoPor', 'diasVacaciones']);
        $colaborador->loadMissing(['puesto', 'sucursalPrincipal.empresa']);
        $dias = $solicitud->tipo === TipoSolicitudInterna::Vacaciones
            ? $solicitud->diasVacaciones->map(fn ($d) => $d->fecha->format('d/m/Y'))->implode(', ')
            : '';

        return [
            'colaborador' => $this->colaborador($colaborador),
            'empresa_razon_social' => $this->empresaRazonSocial($colaborador),
            'folio' => (string) $solicitud->folio,
            'tipo' => mb_strtolower($solicitud->tipo->etiqueta()),
            'periodo' => $solicitud->fecha_inicio !== null
                ? ($solicitud->fecha_fin !== null && ! $solicitud->fecha_fin->isSameDay($solicitud->fecha_inicio)
                    ? sprintf('%s — %s', $this->fecha($solicitud->fecha_inicio), $this->fecha($solicitud->fecha_fin))
                    : $this->fecha($solicitud->fecha_inicio))
                : '—',
            'dias' => $solicitud->dias_solicitados !== null ? (string) $solicitud->dias_solicitados : '',
            'dias_detalle' => $dias,
            'motivo' => (string) $solicitud->motivo,
            'autorizo' => $solicitud->revisadoPor !== null ? trim($solicitud->revisadoPor->name.' '.$solicitud->revisadoPor->apellidos) : '—',
            'fecha_autorizacion' => $this->fecha($solicitud->revisado_en),
            'observaciones' => (string) ($solicitud->observaciones ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function constancia(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['puesto', 'sucursalPrincipal.empresa']);
        $empresa = $this->empresaRazonSocial($colaborador);
        $datos = $this->colaborador($colaborador);

        return [
            'colaborador' => $datos,
            'empresa' => $empresa,
            'empresa_razon_social' => $empresa,
            'fecha_ingreso' => $this->fecha($colaborador->fecha_ingreso),
            'lugar_fecha' => sprintf('%s, a %s', (string) ($colaborador->sucursalPrincipal->nombre ?? 'México'), $this->fechaLarga(Carbon::now())),
            'cuerpo' => sprintf(
                'Por medio de la presente se hace constar que %s labora en %s desde el %s, desempeñando el puesto de %s en la sucursal %s.',
                $datos['nombre'], $empresa, $this->fecha($colaborador->fecha_ingreso), $datos['puesto'], $datos['sucursal'],
            ),
        ];
    }

    /**
     * Datos FICTICIOS y evidentes para la vista previa.
     *
     * @return array<string, mixed>
     */
    public function ejemplo(FamiliaAdministrativa $familia): array
    {
        $colaborador = ['nombre' => 'Colaborador de Ejemplo', 'numero_empleado' => 'EMP-0000', 'puesto' => 'Gestor', 'sucursal' => 'Sucursal de ejemplo', 'nss' => '00000000000'];
        $percepciones = [['concepto' => 'Sueldo base', 'importe' => '$4,500.00'], ['concepto' => 'Bono de productividad', 'importe' => '$750.00']];
        $deducciones = [['concepto' => 'Préstamo interno', 'importe' => '$300.00']];

        $empresaEjemplo = 'Empresa de Ejemplo S.A. de C.V.';

        return match ($familia) {
            FamiliaAdministrativa::ReciboNomina => [
                'colaborador' => $colaborador, 'empresa_razon_social' => $empresaEjemplo, 'periodo' => ['inicio' => '01/10/2026', 'fin' => '15/10/2026'], 'fecha_pago' => '15/10/2026',
                'folio' => 'RIN-EJEMPLO', 'percepciones' => $percepciones, 'deducciones' => $deducciones,
                'total_percepciones' => '$5,250.00', 'total_deducciones' => '$300.00', 'neto' => '$4,950.00',
                'observaciones' => 'Vista previa con datos ficticios.',
            ],
            FamiliaAdministrativa::Finiquito => [
                'colaborador' => $colaborador, 'empresa_razon_social' => $empresaEjemplo, 'folio' => 'SOL-EJEMPLO', 'fecha_ingreso' => '01/03/2024', 'fecha_baja' => '15/10/2026',
                'antiguedad' => '2 año(s), 7 mes(es)', 'sueldo_mensual' => '$9,000.00', 'sueldo_diario' => '$300.00',
                'percepciones' => [['concepto' => 'Sueldo pendiente', 'importe' => '$1,500.00'], ['concepto' => 'Aguinaldo proporcional', 'importe' => '$3,452.05'], ['concepto' => 'Vacaciones pendientes', 'importe' => '$2,400.00'], ['concepto' => 'Prima vacacional', 'importe' => '$600.00']],
                'deducciones' => [['concepto' => 'Adeudos', 'importe' => '$500.00']],
                'total_percepciones' => '$7,952.05', 'total_deducciones' => '$500.00', 'neto' => '$7,452.05',
                'observaciones' => 'Vista previa con datos ficticios.',
            ],
            FamiliaAdministrativa::ComprobanteSolicitud => [
                'colaborador' => $colaborador, 'empresa_razon_social' => $empresaEjemplo, 'folio' => 'SOL-EJEMPLO', 'tipo' => 'vacaciones', 'periodo' => '12/10/2026 — 17/10/2026',
                'dias' => '5', 'dias_detalle' => '12/10/2026, 13/10/2026, 14/10/2026, 16/10/2026, 17/10/2026', 'motivo' => 'Viaje familiar (ejemplo)',
                'autorizo' => 'Recursos Humanos', 'fecha_autorizacion' => '05/10/2026', 'observaciones' => '',
            ],
            FamiliaAdministrativa::ConstanciaLaboral => [
                'colaborador' => $colaborador, 'empresa' => $empresaEjemplo, 'empresa_razon_social' => $empresaEjemplo, 'fecha_ingreso' => '01/03/2024',
                'lugar_fecha' => 'Sucursal de ejemplo, a 5 de octubre de 2026',
                'cuerpo' => "Por medio de la presente se hace constar que Colaborador de Ejemplo labora en {$empresaEjemplo} desde el 01/03/2024, desempeñando el puesto de Gestor.",
            ],
        };
    }

    /**
     * Razón social real de la empresa del colaborador (CLAUDE.md §21): nunca
     * "MR. LANA PEOPLE" (eso es el sistema, no el patrón). Si la empresa no
     * tiene razón social capturada, cae al nombre corto antes que a un
     * genérico.
     */
    private function empresaRazonSocial(Colaborador $colaborador): string
    {
        $empresa = $colaborador->sucursalPrincipal?->empresa;

        return (string) ($empresa?->razon_social ?: $empresa?->nombre ?: 'la empresa');
    }

    /**
     * @return array{nombre: string, numero_empleado: string, puesto: string, sucursal: string, nss: string}
     */
    private function colaborador(Colaborador $colaborador): array
    {
        return [
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => (string) ($colaborador->numero_empleado ?? '—'),
            'puesto' => (string) ($colaborador->puesto->nombre ?? '—'),
            'sucursal' => (string) ($colaborador->sucursalPrincipal->nombre ?? '—'),
            'nss' => (string) ($colaborador->nss ?? '—'),
        ];
    }

    private function fechaLarga(Carbon $fecha): string
    {
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return sprintf('%d de %s de %d', $fecha->day, $meses[$fecha->month - 1], $fecha->year);
    }

    private function dinero(float $valor): string
    {
        return '$'.number_format($valor, 2);
    }

    private function fecha(?\DateTimeInterface $fecha): string
    {
        return $fecha?->format('d/m/Y') ?? '—';
    }
}
