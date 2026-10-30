<?php

namespace App\Enums;

/**
 * Documentos administrativos que el sistema genera en HTML → PDF y cuyo
 * DISEÑO (no sus datos) RH administra en Documentos maestros → Documentos
 * administrativos (App\Services\DocumentosAdministrativos).
 *
 * El diseño de fábrica de recibo, finiquito y permiso ES el formato oficial
 * entregado por RH (docs/formatosRH/*.docx): encabezado patronal con logo,
 * caja de título a la derecha, bandas de sección y firmas físicas. RH
 * puede ajustar colores, logo, fondo, tamaños y textos, nunca los datos.
 */
enum FamiliaAdministrativa: string
{
    case ReciboNomina = 'recibo_nomina';
    case Finiquito = 'finiquito';
    case Permiso = 'permiso';
    case ComprobanteSolicitud = 'comprobante_solicitud';
    case ConstanciaLaboral = 'constancia_laboral';
    case ConvenioTerminacion = 'convenio_terminacion';
    case AvisoRescision = 'aviso_rescision';
    case ConstanciaTerminacion = 'constancia_terminacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ReciboNomina => 'Recibo de nómina',
            self::Finiquito => 'Finiquito',
            self::Permiso => 'Solicitud de permiso',
            self::ComprobanteSolicitud => 'Comprobante de vacaciones',
            self::ConstanciaLaboral => 'Constancia laboral',
            self::ConvenioTerminacion => 'Convenio de terminación',
            self::AvisoRescision => 'Aviso de rescisión',
            self::ConstanciaTerminacion => 'Constancia de terminación',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::ReciboNomina => 'Formato oficial «Recibo de nómina» (semanal o quincenal). Montos, percepciones y deducciones salen del lote de nómina.',
            self::Finiquito => 'Formato oficial «Finiquito». Conceptos (001–004, 101–102) y montos salen del cálculo revisado por RH.',
            self::Permiso => 'Formato oficial «Solicitud de permiso». Se llena solo al autorizarlo Recursos Humanos.',
            self::ComprobanteSolicitud => 'Comprobante de vacaciones autorizadas.',
            self::ConstanciaLaboral => 'Constancia laboral con los datos vigentes del colaborador.',
            self::ConvenioTerminacion => 'Documento de salida de una no renovación. Se llena con los datos del expediente de la baja.',
            self::AvisoRescision => 'Documento de salida de una rescisión: causal, fundamento, hechos, testigos y entrega capturados en el expediente.',
            self::ConstanciaTerminacion => 'Constancia de otras terminaciones (fallecimiento, incapacidad permanente total, jubilación o pensión).',
        };
    }

    /**
     * Secciones que el diseño puede mostrar u ocultar.
     *
     * @return array<string, string>
     */
    public function secciones(): array
    {
        return match ($this) {
            self::ReciboNomina => ['datos_colaborador' => 'Datos del trabajador', 'dias_periodo' => 'Días del periodo', 'percepciones' => 'Percepciones', 'deducciones' => 'Deducciones', 'neto' => 'Neto a pagar', 'observaciones' => 'Observaciones', 'leyenda' => 'Leyenda'],
            self::Finiquito => ['datos_colaborador' => 'Datos del trabajador', 'percepciones' => 'Percepciones', 'deducciones' => 'Deducciones', 'neto' => 'Total a pagar e importe con letra', 'observaciones' => 'Comentarios del ajuste', 'notas' => 'Notas', 'leyenda' => 'Leyenda'],
            self::Permiso => ['datos_colaborador' => 'Datos del colaborador', 'permiso_solicitado' => 'Permiso solicitado', 'tipo_permiso' => 'Tipo de permiso y causal', 'observaciones' => 'Observaciones', 'leyenda' => 'Leyenda'],
            self::ComprobanteSolicitud => ['datos_colaborador' => 'Datos del colaborador', 'detalle' => 'Detalle de la solicitud', 'observaciones' => 'Observaciones', 'leyenda' => 'Leyenda'],
            self::ConstanciaLaboral => ['datos_colaborador' => 'Datos del colaborador', 'notas' => 'Nota', 'leyenda' => 'Leyenda'],
            self::ConvenioTerminacion, self::ConstanciaTerminacion => ['datos_colaborador' => 'Datos del colaborador', 'notas' => 'Nota', 'leyenda' => 'Leyenda'],
            self::AvisoRescision => ['datos_colaborador' => 'Datos del colaborador', 'hechos' => 'Hechos', 'entrega' => 'Entrega del aviso', 'notas' => 'Nota', 'leyenda' => 'Leyenda'],
        };
    }

    /**
     * Campos que se pueden usar dentro de los textos editables (título,
     * subtítulo, leyenda, nota, pie). Las tablas de conceptos son bloques
     * fijos, nunca sustitución de texto.
     *
     * @return list<string>
     */
    public function campos(): array
    {
        $colaborador = ['colaborador.nombre', 'colaborador.numero_empleado', 'colaborador.puesto', 'colaborador.sucursal', 'colaborador.departamento', 'colaborador.rfc', 'colaborador.curp', 'colaborador.nss'];
        $empresa = ['empresa_razon_social', 'empresa.rfc', 'empresa.registro_patronal', 'empresa.domicilio_fiscal', 'empresa.codigo_postal', 'fecha_documento'];

        return match ($this) {
            self::ReciboNomina => [...$colaborador, ...$empresa, 'periodo.inicio', 'periodo.fin', 'numero_nomina', 'frecuencia', 'fecha_pago', 'folio', 'total_percepciones', 'total_deducciones', 'neto', 'observaciones'],
            self::Finiquito => [...$colaborador, ...$empresa, 'folio', 'fecha_ingreso', 'fecha_baja', 'dias_trabajados', 'antiguedad', 'total_percepciones', 'total_deducciones', 'neto', 'neto_letra'],
            self::Permiso => [...$colaborador, ...$empresa, 'folio', 'fecha_permiso', 'tipo_permiso', 'goce', 'causal', 'autorizo', 'fecha_autorizacion'],
            self::ComprobanteSolicitud => [...$colaborador, ...$empresa, 'folio', 'tipo', 'periodo', 'dias'],
            self::ConstanciaLaboral => [...$colaborador, ...$empresa, 'empresa', 'fecha_ingreso', 'lugar_fecha'],
            self::ConvenioTerminacion, self::ConstanciaTerminacion => [...$colaborador, ...$empresa, 'folio', 'fecha_ingreso', 'fecha_baja', 'causal', 'lugar_fecha'],
            self::AvisoRescision => [...$colaborador, ...$empresa, 'folio', 'fecha_ingreso', 'fecha_baja', 'causal', 'fundamento', 'fecha_aviso', 'lugar_fecha'],
        };
    }

    /**
     * Textos por defecto (pueden llevar {{ campo }}). Recibo, finiquito y
     * permiso: los textos literales del formato oficial.
     *
     * @return array{titulo: string, subtitulo: string, leyenda: string, nota: string}
     */
    public function contenidoPorDefecto(): array
    {
        return match ($this) {
            self::ReciboNomina => [
                'titulo' => 'RECIBO DE NÓMINA',
                'subtitulo' => 'Comprobante de pago de salarios y prestaciones',
                'leyenda' => 'Recibí de la empresa arriba señalada la cantidad neta indicada en este recibo, por concepto de pago de mi salario y demás prestaciones correspondientes al periodo de pago señalado, conforme a lo dispuesto en la Ley Federal del Trabajo. Manifiesto mi conformidad con las percepciones y deducciones aquí detalladas y que, a la fecha, no se me adeuda cantidad alguna por los conceptos y periodo indicados.',
                'nota' => '',
            ],
            self::Finiquito => [
                'titulo' => 'FINIQUITO',
                'subtitulo' => 'Comprobante de pago por terminación laboral',
                'leyenda' => 'Recibo pago de cada una de mis prestaciones a las que tuve derecho al día de hoy {{ fecha_documento }}, no se me adeuda nada, doy por terminada la relación laboral que me unía a la empresa {{ empresa_razon_social }}.',
                'nota' => '',
            ],
            self::Permiso => [
                'titulo' => 'SOLICITUD DE PERMISO',
                'subtitulo' => 'Autorización de ausencia o cambio de horario',
                'leyenda' => '',
                'nota' => '',
            ],
            self::ComprobanteSolicitud => [
                'titulo' => 'COMPROBANTE DE {{ tipo }}',
                'subtitulo' => 'Folio {{ folio }}',
                'leyenda' => 'Comprobante generado al autorizarse la solicitud.',
                'nota' => '',
            ],
            self::ConstanciaLaboral => [
                'titulo' => 'CONSTANCIA LABORAL',
                'subtitulo' => '',
                'leyenda' => '',
                'nota' => 'Se extiende la presente a petición del interesado para los fines que a éste convengan.',
            ],
            // Formatos base sin clausulado jurídico propio: Jurídico/RH los
            // validan y ajustan en Documentos administrativos (la nota lo dice).
            self::ConvenioTerminacion => [
                'titulo' => 'CONVENIO DE TERMINACIÓN',
                'subtitulo' => 'Folio {{ folio }}',
                'leyenda' => '',
                'nota' => 'Formato base pendiente de validación de Jurídico. Se ajusta en Documentos maestros → Documentos administrativos.',
            ],
            self::AvisoRescision => [
                'titulo' => 'AVISO DE RESCISIÓN',
                'subtitulo' => 'Folio {{ folio }}',
                'leyenda' => '',
                'nota' => 'Formato base pendiente de validación de Jurídico. Se ajusta en Documentos maestros → Documentos administrativos.',
            ],
            self::ConstanciaTerminacion => [
                'titulo' => 'CONSTANCIA DE TERMINACIÓN',
                'subtitulo' => 'Folio {{ folio }}',
                'leyenda' => '',
                'nota' => 'Formato base pendiente de validación de Jurídico. Se ajusta en Documentos maestros → Documentos administrativos.',
            ],
        };
    }

    /**
     * Bloques de firma por defecto (líneas para firma física).
     *
     * @return list<array{label: string, detalle?: string}>
     */
    public function firmasPorDefecto(): array
    {
        return match ($this) {
            self::ReciboNomina => [['label' => 'Firma del trabajador', 'detalle' => 'Nombre completo']],
            self::Finiquito => [['label' => 'Nombre y firma del trabajador', 'detalle' => 'Recibí de conformidad']],
            self::Permiso => [['label' => 'Firma jefe inmediato'], ['label' => 'Firma Recursos Humanos'], ['label' => 'Firma colaborador']],
            self::ComprobanteSolicitud => [],
            self::ConstanciaLaboral => [['label' => 'Recursos Humanos']],
            self::ConvenioTerminacion => [['label' => 'Nombre y firma del trabajador'], ['label' => '{{ empresa_razon_social }}', 'detalle' => 'Representante legal']],
            self::AvisoRescision => [['label' => '{{ empresa_razon_social }}', 'detalle' => 'Representante legal'], ['label' => 'Recibí: nombre y firma del trabajador'], ['label' => 'Testigo'], ['label' => 'Testigo']],
            self::ConstanciaTerminacion => [['label' => '{{ empresa_razon_social }}', 'detalle' => 'Representante legal']],
        };
    }
}
