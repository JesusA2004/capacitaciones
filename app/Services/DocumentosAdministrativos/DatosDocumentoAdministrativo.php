<?php

namespace App\Services\DocumentosAdministrativos;

use App\Enums\CausalPermisoEspecial;
use App\Enums\FamiliaAdministrativa;
use App\Enums\GocePermiso;
use App\Enums\PeriodicidadNomina;
use App\Enums\TipoConceptoNomina;
use App\Enums\TipoPermisoSolicitado;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Services\Formatos\Variables\NumeroALetras;
use Illuminate\Support\Carbon;

/**
 * DATOS de cada documento administrativo, ya calculados por su servicio de
 * negocio y solo formateados para mostrarse (montos «$1,234.56», fechas
 * dd-mm-aaaa como el formato oficial). Nada de aquí lo puede cambiar el
 * diseño: la plantilla solo decide cómo se ve. Los datos de ejemplo de la
 * vista previa son ficticios y evidentes (nunca de una persona real).
 *
 * Encabezado patronal: razón social REAL de la empresa del colaborador
 * (CLAUDE.md §21), con su RFC, registro patronal, domicilio fiscal y C.P.
 * MR. LANA PEOPLE es el sistema, nunca el patrón.
 */
class DatosDocumentoAdministrativo
{
    public function __construct(private readonly NumeroALetras $letras) {}

    /**
     * @return array<string, mixed>
     */
    public function recibo(ReciboNomina $recibo): array
    {
        $recibo->loadMissing(['colaborador.puesto', 'colaborador.departamento', 'colaborador.sucursalPrincipal.empresa']);
        $conceptos = $recibo->conceptos()->orderBy('orden')->get();
        $periodicidad = PeriodicidadNomina::tryFrom((string) $recibo->tipo_periodo);

        $lista = function (TipoConceptoNomina $tipo, array $respaldo) use ($conceptos): array {
            $base = $tipo === TipoConceptoNomina::Percepcion ? 1 : 101;

            if ($conceptos->isNotEmpty()) {
                return array_values($conceptos->where('tipo', $tipo)->values()->map(fn ($c, int $i) => [
                    'clave' => (string) ($c->clave ?: sprintf('%03d', $base + $i)),
                    'concepto' => (string) $c->concepto,
                    'importe' => $this->dinero((float) $c->importe),
                ])->all());
            }

            return array_map(fn (array $c, int $i) => [
                'clave' => sprintf('%03d', $base + $i),
                'concepto' => (string) ($c['concepto'] ?? ''),
                'importe' => $this->dinero((float) ($c['monto'] ?? 0)),
            ], $respaldo, array_keys($respaldo));
        };

        $numero = $recibo->numero_periodo !== null ? (string) $recibo->numero_periodo : '—';

        return [
            ...$this->base($recibo->colaborador),
            'periodo' => ['inicio' => $this->fecha($recibo->periodo_inicio), 'fin' => $this->fecha($recibo->periodo_fin)],
            'numero_nomina' => $numero,
            'frecuencia' => $periodicidad?->etiqueta() ?? 'Otro periodo',
            'caja' => [
                [['No. de nómina', $numero], ['Frecuencia', $periodicidad?->etiqueta() ?? '—']],
                [['Fecha inicial', $this->fecha($recibo->periodo_inicio)], ['Fecha final', $this->fecha($recibo->periodo_fin)]],
            ],
            'fecha_pago' => $this->fecha($recibo->fecha_pago),
            'folio' => (string) ($recibo->folio ?? $recibo->id),
            'dias' => [
                'pagados' => $this->dias($recibo->dias_pagados ?? $this->diasDelPeriodo($recibo)),
                'falta' => $this->dias($recibo->dias_falta ?? 0),
                'incapacidad' => $this->dias($recibo->dias_incapacidad ?? 0),
            ],
            'percepciones' => $lista(TipoConceptoNomina::Percepcion, (array) ($recibo->percepciones ?? [])),
            'deducciones' => $lista(TipoConceptoNomina::Deduccion, (array) ($recibo->deducciones ?? [])),
            'total_percepciones' => $this->dinero((float) $recibo->total_percepciones),
            'total_deducciones' => $this->dinero((float) $recibo->total_deducciones),
            'neto' => $this->dinero((float) $recibo->neto),
            'neto_letra' => $this->letras->moneda((float) $recibo->neto),
            'observaciones' => (string) ($recibo->observaciones ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $desglose  FiniquitoService::desglose()
     * @return array<string, mixed>
     */
    public function finiquito(FiniquitoCalculo $finiquito, array $desglose): array
    {
        $finiquito->loadMissing(['colaborador.puesto', 'colaborador.departamento', 'colaborador.sucursalPrincipal.empresa', 'solicitudInterna']);
        $renglones = fn (string $tipo) => array_values(array_map(
            fn (array $r) => [
                'clave' => (string) ($r['clave'] ?? ''),
                'concepto' => (string) $r['concepto'].(($r['observaciones'] ?? null) && ($r['origen'] ?? '') === 'manual' ? ' — '.$r['observaciones'] : ''),
                'dias' => isset($r['dias']) ? $this->dias((float) $r['dias']) : '',
                'importe' => $this->dinero((float) $r['importe']),
            ],
            array_filter($desglose, fn (array $r) => ($r['tipo'] ?? null) === $tipo),
        ));
        $neto = (float) ($finiquito->neto ?? $finiquito->total_ajustado);
        $diasTrabajados = number_format((int) $finiquito->fecha_ingreso->diffInDays($finiquito->fecha_baja) + 1);

        return [
            ...$this->base($finiquito->colaborador),
            'folio' => (string) ($finiquito->solicitudInterna->folio ?? $finiquito->id),
            'fecha_ingreso' => $this->fecha($finiquito->fecha_ingreso),
            'fecha_baja' => $this->fecha($finiquito->fecha_baja),
            'dias_trabajados' => $diasTrabajados,
            'caja' => [
                [['Fecha de alta', $this->fecha($finiquito->fecha_ingreso)], ['Fecha de baja', $this->fecha($finiquito->fecha_baja)]],
                [['Días trabajados', $diasTrabajados]],
            ],
            'antiguedad' => sprintf('%d año(s), %d mes(es)', (int) $finiquito->antiguedad_anios, (int) $finiquito->antiguedad_meses),
            'sueldo_mensual' => $this->dinero((float) $finiquito->sueldo_mensual),
            'sueldo_diario' => $this->dinero((float) $finiquito->sueldo_diario),
            'percepciones' => $renglones(TipoConceptoNomina::Percepcion->value),
            'deducciones' => $renglones(TipoConceptoNomina::Deduccion->value),
            'total_percepciones' => $this->dinero((float) $finiquito->total_percepciones),
            'total_deducciones' => $this->dinero((float) $finiquito->total_deducciones),
            'neto' => $this->dinero($neto),
            'neto_letra' => $this->letras->moneda($neto),
            'observaciones' => (string) ($finiquito->comentarios_ajuste ?? ''),
        ];
    }

    /**
     * Formato oficial de permiso de una solicitud AUTORIZADA por RH.
     *
     * @return array<string, mixed>
     */
    public function permiso(SolicitudInterna $solicitud, Colaborador $colaborador): array
    {
        $solicitud->loadMissing('revisadoPor');
        $tipo = TipoPermisoSolicitado::tryFrom((string) $solicitud->permiso_tipo);
        $goce = GocePermiso::tryFrom((string) $solicitud->permiso_goce);
        $causal = CausalPermisoEspecial::tryFrom((string) $solicitud->permiso_causal);
        $fechaPermiso = $solicitud->fecha_inicio !== null
            ? ($solicitud->fecha_fin !== null && ! $solicitud->fecha_fin->isSameDay($solicitud->fecha_inicio)
                ? sprintf('%s al %s', $this->fecha($solicitud->fecha_inicio), $this->fecha($solicitud->fecha_fin))
                : $this->fecha($solicitud->fecha_inicio))
            : '—';
        $horaSalida = $solicitud->hora_salida !== null ? substr((string) $solicitud->hora_salida, 0, 5) : '';
        $horaEntrada = $solicitud->hora_entrada !== null ? substr((string) $solicitud->hora_entrada, 0, 5) : '';

        return [
            ...$this->base($colaborador),
            'folio' => (string) $solicitud->folio,
            'fecha_permiso' => $fechaPermiso,
            'caja' => [[['Fecha de permiso', $fechaPermiso]]],
            'tipo_permiso' => $tipo?->etiqueta() ?? '',
            'goce' => $goce?->etiqueta() ?? '',
            'causal' => $causal?->etiqueta() ?? '',
            'permiso' => [
                'tipo' => $tipo->value ?? '',
                'goce' => $goce->value ?? '',
                'causal' => $causal->value ?? '',
                'dias' => $solicitud->dias_solicitados !== null ? (string) $solicitud->dias_solicitados : '1',
                'horas' => $tipo === TipoPermisoSolicitado::SalirTemprano ? ($horaSalida !== '' ? 'desde las '.$horaSalida : '') : ($horaEntrada !== '' ? 'hasta las '.$horaEntrada : ''),
                'hora_salida' => $horaSalida,
                'hora_entrada' => $horaEntrada,
            ],
            'observaciones' => trim(implode(' · ', array_filter([(string) $solicitud->motivo, (string) ($solicitud->observaciones ?? '')]))),
            'autorizo' => $solicitud->revisadoPor !== null ? trim($solicitud->revisadoPor->name.' '.$solicitud->revisadoPor->apellidos) : '',
            'fecha_autorizacion' => $solicitud->revisado_en?->timezone('America/Mexico_City')->format('d-m-Y H:i') ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comprobante(SolicitudInterna $solicitud, Colaborador $colaborador): array
    {
        $solicitud->loadMissing(['revisadoPor', 'diasVacaciones']);
        $dias = $solicitud->tipo === TipoSolicitudInterna::Vacaciones
            ? $solicitud->diasVacaciones->map(fn ($d) => $d->fecha->format('d-m-Y'))->implode(', ')
            : '';
        $periodo = $solicitud->fecha_inicio !== null
            ? ($solicitud->fecha_fin !== null && ! $solicitud->fecha_fin->isSameDay($solicitud->fecha_inicio)
                ? sprintf('%s — %s', $this->fecha($solicitud->fecha_inicio), $this->fecha($solicitud->fecha_fin))
                : $this->fecha($solicitud->fecha_inicio))
            : '—';

        return [
            ...$this->base($colaborador),
            'folio' => (string) $solicitud->folio,
            'tipo' => mb_strtoupper($solicitud->tipo->etiqueta()),
            'caja' => [[['Folio', (string) $solicitud->folio], ['Días', $solicitud->dias_solicitados !== null ? (string) $solicitud->dias_solicitados : '—']]],
            'periodo' => $periodo,
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
        $base = $this->base($colaborador);
        $datos = $base['colaborador'];
        $empresa = $base['empresa_razon_social'];

        return [
            ...$base,
            'empresa' => $empresa,
            'caja' => [[['Fecha', $this->fecha(Carbon::now('America/Mexico_City'))]]],
            'fecha_ingreso' => $this->fecha($colaborador->fecha_ingreso),
            'lugar_fecha' => sprintf('%s, a %s', (string) ($colaborador->sucursalPrincipal->nombre ?? 'México'), $this->fechaLarga(Carbon::now('America/Mexico_City'))),
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
        $colaborador = [
            'nombre' => 'COLABORADOR DE EJEMPLO', 'numero_empleado' => 'EMP-0000', 'puesto' => 'Gestor', 'sucursal' => 'Sucursal de ejemplo',
            'departamento' => 'Comercial', 'departamento_sucursal' => 'Comercial / Sucursal de ejemplo', 'nss' => '00000000000',
            'rfc' => 'XAXX010101000', 'curp' => 'XEXX010101HNEXXXA4', 'fecha_ingreso' => '01-03-2024', 'salario_diario' => '$300.00',
        ];
        $base = [
            'colaborador' => $colaborador,
            'empresa_razon_social' => 'EMPRESA DE EJEMPLO S.A. DE C.V.',
            'empresa' => ['razon_social' => 'EMPRESA DE EJEMPLO S.A. DE C.V.', 'rfc' => 'EEJ000000XX0', 'registro_patronal' => 'X0000000000', 'domicilio_fiscal' => 'Calle de ejemplo 100, Col. Centro', 'codigo_postal' => '00000'],
            'fecha_documento' => '15-10-2026',
        ];

        return match ($familia) {
            FamiliaAdministrativa::ReciboNomina => [
                ...$base, 'periodo' => ['inicio' => '05-10-2026', 'fin' => '11-10-2026'], 'numero_nomina' => '41', 'frecuencia' => 'Semanal',
                'caja' => [[['No. de nómina', '41'], ['Frecuencia', 'Semanal']], [['Fecha inicial', '05-10-2026'], ['Fecha final', '11-10-2026']]],
                'fecha_pago' => '11-10-2026', 'folio' => 'RIN-EJEMPLO', 'dias' => ['pagados' => '7', 'falta' => '0', 'incapacidad' => '0'],
                'percepciones' => [['clave' => '001', 'concepto' => 'Sueldo', 'importe' => '$2,100.00'], ['clave' => '002', 'concepto' => 'Bono de productividad', 'importe' => '$350.00']],
                'deducciones' => [['clave' => '101', 'concepto' => 'ISR retenido', 'importe' => '$120.00']],
                'total_percepciones' => '$2,450.00', 'total_deducciones' => '$120.00', 'neto' => '$2,330.00',
                'neto_letra' => 'DOS MIL TRESCIENTOS TREINTA PESOS 00/100 M.N.', 'observaciones' => 'Vista previa con datos ficticios.',
            ],
            FamiliaAdministrativa::Finiquito => [
                ...$base, 'folio' => 'SOL-EJEMPLO', 'fecha_ingreso' => '01-03-2024', 'fecha_baja' => '15-10-2026', 'dias_trabajados' => '960',
                'caja' => [[['Fecha de alta', '01-03-2024'], ['Fecha de baja', '15-10-2026']], [['Días trabajados', '960']]],
                'antiguedad' => '2 año(s), 7 mes(es)', 'sueldo_mensual' => '$9,000.00', 'sueldo_diario' => '$300.00',
                'percepciones' => [
                    ['clave' => '001', 'concepto' => 'Sueldo pendiente de pago', 'dias' => '5', 'importe' => '$1,500.00'],
                    ['clave' => '002', 'concepto' => 'Aguinaldo proporcional', 'dias' => '', 'importe' => '$3,452.05'],
                    ['clave' => '003', 'concepto' => 'Vacaciones proporcionales', 'dias' => '8', 'importe' => '$2,400.00'],
                    ['clave' => '004', 'concepto' => 'Prima vacacional (25%)', 'dias' => '', 'importe' => '$600.00'],
                ],
                'deducciones' => [['clave' => '101', 'concepto' => 'ISR retenido', 'importe' => '$0.00'], ['clave' => '102', 'concepto' => 'Otras deducciones', 'importe' => '$500.00']],
                'total_percepciones' => '$7,952.05', 'total_deducciones' => '$500.00', 'neto' => '$7,452.05',
                'neto_letra' => 'SIETE MIL CUATROCIENTOS CINCUENTA Y DOS PESOS 05/100 M.N.', 'observaciones' => 'Vista previa con datos ficticios.',
            ],
            FamiliaAdministrativa::Permiso => [
                ...$base, 'folio' => 'SOL-EJEMPLO', 'fecha_permiso' => '20-10-2026', 'caja' => [[['Fecha de permiso', '20-10-2026']]],
                'tipo_permiso' => 'Permiso para salir temprano', 'goce' => 'Permiso especial', 'causal' => 'Cumpleaños',
                'permiso' => ['tipo' => 'salir_temprano', 'goce' => 'especial', 'causal' => 'cumpleanos', 'dias' => '', 'horas' => 'desde las 15:00', 'hora_salida' => '15:00', 'hora_entrada' => ''],
                'observaciones' => 'Vista previa con datos ficticios.', 'autorizo' => 'Recursos Humanos', 'fecha_autorizacion' => '18-10-2026 10:30',
            ],
            FamiliaAdministrativa::ComprobanteSolicitud => [
                ...$base, 'folio' => 'SOL-EJEMPLO', 'tipo' => 'VACACIONES', 'caja' => [[['Folio', 'SOL-EJEMPLO'], ['Días', '5']]], 'periodo' => '12-10-2026 — 17-10-2026',
                'dias' => '5', 'dias_detalle' => '12-10-2026, 13-10-2026, 14-10-2026, 16-10-2026, 17-10-2026', 'motivo' => 'Viaje familiar (ejemplo)',
                'autorizo' => 'Recursos Humanos', 'fecha_autorizacion' => '05-10-2026', 'observaciones' => '',
            ],
            FamiliaAdministrativa::ConstanciaLaboral => [
                ...$base, 'empresa' => 'EMPRESA DE EJEMPLO S.A. DE C.V.', 'caja' => [[['Fecha', '05-10-2026']]], 'fecha_ingreso' => '01-03-2024',
                'lugar_fecha' => 'Sucursal de ejemplo, a 5 de octubre de 2026',
                'cuerpo' => 'Por medio de la presente se hace constar que COLABORADOR DE EJEMPLO labora en EMPRESA DE EJEMPLO S.A. DE C.V. desde el 01-03-2024, desempeñando el puesto de Gestor.',
            ],
        };
    }

    /**
     * Campos comunes: colaborador, empresa patronal y fecha del documento.
     *
     * @return array{colaborador: array<string, string>, empresa_razon_social: string, empresa: array<string, string>, fecha_documento: string}
     */
    private function base(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['puesto', 'departamento', 'sucursalPrincipal.empresa']);
        $empresa = $colaborador->sucursalPrincipal?->empresa;
        $razon = (string) ($empresa?->razon_social ?: $empresa?->nombre ?: 'la empresa');

        return [
            'colaborador' => $this->colaborador($colaborador),
            'empresa_razon_social' => $razon,
            'empresa' => [
                'razon_social' => $razon,
                'rfc' => (string) ($empresa->rfc ?? ''),
                'registro_patronal' => (string) ($empresa->registro_patronal ?? ''),
                'domicilio_fiscal' => (string) ($empresa->domicilio_fiscal ?? ''),
                'codigo_postal' => (string) ($empresa->codigo_postal_fiscal ?? ''),
            ],
            'fecha_documento' => $this->fecha(Carbon::now('America/Mexico_City')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function colaborador(Colaborador $colaborador): array
    {
        $sucursal = (string) ($colaborador->sucursalPrincipal->nombre ?? '');
        $departamento = (string) ($colaborador->departamento->nombre ?? '');
        $mensual = (float) ($colaborador->sueldo_mensual ?? 0);

        return [
            'nombre' => mb_strtoupper($colaborador->nombreCompleto()),
            'numero_empleado' => (string) ($colaborador->numero_empleado ?? '—'),
            'puesto' => (string) ($colaborador->puesto->nombre ?? '—'),
            'sucursal' => $sucursal !== '' ? $sucursal : '—',
            'departamento' => $departamento !== '' ? $departamento : '—',
            'departamento_sucursal' => implode(' / ', array_filter([$departamento, $sucursal])) ?: '—',
            'nss' => (string) ($colaborador->nss ?? '—'),
            'rfc' => (string) ($colaborador->rfc ?? '—'),
            'curp' => (string) ($colaborador->curp ?? '—'),
            'fecha_ingreso' => $this->fecha($colaborador->fecha_ingreso),
            'salario_diario' => $mensual > 0 ? $this->dinero(round($mensual / 30, 2)) : '—',
        ];
    }

    private function diasDelPeriodo(ReciboNomina $recibo): float
    {
        return (float) ((int) $recibo->periodo_inicio->diffInDays($recibo->periodo_fin) + 1);
    }

    private function dias(float|string|int $valor): string
    {
        $numero = (float) $valor;

        return fmod($numero, 1.0) === 0.0 ? (string) (int) $numero : number_format($numero, 2);
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
        return $fecha?->format('d-m-Y') ?? '—';
    }
}
