<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Enums\BienMueble;
use App\Enums\Escolaridad;
use App\Enums\EstiloTrabajo;
use App\Enums\Genero;
use App\Enums\ParentescoDependiente;
use App\Enums\TenenciaVivienda;
use App\Enums\TipoSanguineo;
use App\Models\Colaborador;
use App\Support\DatoMarcador;
use Carbon\CarbonImmutable;

/**
 * Datos con los que el motor documental (DatosDocumentoService) llena la
 * Solicitud de empleo (sol_*) y el Estudio socioeconómico (ese_*): sale del
 * perfil de ingreso + colaborador, en la forma exacta del formato (casillas
 * marca_* = «X», fechas partidas, «No aplica» donde la persona lo declaró o
 * la condición no aplica). Nunca inventa: un dato sin capturar sale vacío y
 * el motor bloquea (DATOS_FALTANTES).
 *
 * Edad y fecha del documento: calendario de Ciudad de México a la fecha del
 * documento (la misma `hoy` del ContextoDocumento).
 */
class DatosSolicitudEmpleoService
{
    public const NO_APLICA = 'No aplica';

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Campo del formato → dato del catálogo (o lista) del que depende: para
     * que un faltante diga «Número de hijos», nunca «sol hijos».
     *
     * @var array<string, string>
     */
    private const BASE = [
        'sol_hijos' => 'numero_hijos', 'sol_anios_moto' => 'anios_manejo_moto', 'sol_tiempo_traslado' => 'tiempo_traslado_minutos',
        'sol_con_quien_vive' => 'con_quien_vive', 'sol_tenencia' => 'vivienda_tenencia', 'sol_tiempo_residencia' => 'tiempo_residencia_anios',
        'sol_dependientes' => 'dependientes', 'sol_telefono_alterno' => 'telefono_alterno', 'sol_conyuge' => 'conyuge_nombre',
        'sol_enfermedades' => 'padece_enfermedad_cronica', 'sol_referencias_domicilio' => 'referencias_domicilio',
        'sol_vacante_otro' => 'fuente_vacante_otro', 'sol_familiares_nombre' => 'familiares_empresa_nombre', 'sol_escolaridad' => 'escolaridad',
        'sol_area_cursos' => 'area_cursos', 'sol_ventas_anios' => 'exp_ventas_anios', 'sol_cobranza_anios' => 'exp_cobranza_anios',
        'sol_creditos_donde' => 'exp_creditos_donde', 'sol_tres_palabras' => 'tres_palabras', 'sol_fortalezas' => 'fortalezas',
        'sol_logro' => 'logro_profesional', 'sol_situacion_dificil' => 'situacion_dificil', 'sol_areas_oportunidad' => 'areas_oportunidad',
        'sol_jefes' => 'como_te_describen',
        'ese_civil_otro' => 'estado_civil', 'ese_domicilio_1' => 'domicilio_calle', 'ese_domicilio_2' => 'domicilio_colonia',
        'ese_residencia_anios' => 'tiempo_residencia_anios', 'ese_tel_otro' => 'telefono', 'ese_id_otro' => 'identificacion_otro',
        'ese_id_institucion' => 'identificacion_institucion', 'ese_id_folio' => 'identificacion_folio', 'ese_tipo_sanguineo' => 'tipo_sanguineo',
        'ese_entre_calle_1' => 'entre_calle_1', 'ese_entre_calle_2' => 'entre_calle_2', 'ese_calle_posterior' => 'calle_posterior', 'ese_calle_lateral' => 'calle_lateral',
        'ese_inm_ubicacion' => 'tiene_inmueble', 'ese_inm_metros' => 'inmueble_metros', 'ese_inm_valor' => 'inmueble_valor', 'ese_inm_especifique' => 'inmueble_adquisicion_otro',
        'ese_egreso_otros_concepto' => 'egreso_otros_concepto', 'ese_paredes_especifique' => 'paredes_otro', 'ese_cuartos' => 'numero_cuartos',
        'ese_banos' => 'numero_banos', 'ese_patio' => 'tiene_patio', 'ese_construccion_otros' => 'construccion_otros',
        'ese_servicios_especifique' => 'servicios_vivienda_otro', 'ese_personas_habitan' => 'personas_habitan',
        'ese_vehiculo_especifique' => 'vehiculo_otro', 'ese_vehiculo_modelo' => 'tiene_vehiculo', 'ese_vehiculo_tipo' => 'vehiculo_tipo_detalle',
        'ese_vehiculo_marca' => 'vehiculo_marca', 'ese_vehiculo_valor' => 'vehiculo_valor', 'ese_muebles_especifique' => 'bienes_muebles_otro',
        'ese_sm_especifique' => 'servicio_medico',
    ];

    private const EGRESOS = ['alimentacion', 'renta', 'agua', 'luz', 'telefono', 'servicios_medicos', 'vestido', 'diversion', 'predial', 'hipoteca', 'transporte', 'gasolina', 'gas', 'otros'];

    public function __construct(private readonly PerfilIngresoService $perfil) {}

    /**
     * ¿El campo del formato pertenece a la Solicitud / Estudio socioeconómico?
     */
    public static function esCampo(string $campo): bool
    {
        return str_starts_with($campo, 'sol_') || str_starts_with($campo, 'ese_') || $campo === 'fecha_nacimiento_corta';
    }

    /**
     * Fuente de un campo del formato (para pedir solo lo que falta). null si
     * el campo no es de estos formatos.
     *
     * @return array{campo: string, base: string, fuente: string, columna: string, etiqueta: string, tipo: string}|null
     */
    public static function fuente(string $campo): ?array
    {
        if ($campo === 'fecha_nacimiento_corta') {
            return ['campo' => $campo, 'base' => 'fecha_nacimiento', 'fuente' => 'colaborador', 'columna' => 'fecha_nacimiento', 'etiqueta' => 'Fecha de nacimiento', 'tipo' => 'fecha'];
        }

        if (! self::esCampo($campo)) {
            return null;
        }

        $base = self::BASE[$campo] ?? self::baseDeLista($campo);

        if ($base === null && preg_match('/^ese_egreso_([a-z_]+)$/', $campo, $m) === 1) {
            $base = 'egreso_'.$m[1];
        }

        $base ??= $campo;
        $listas = CatalogoSolicitudEmpleo::listas();
        $etiqueta = match (true) {
            CatalogoSolicitudEmpleo::existe($base) => CatalogoSolicitudEmpleo::campo($base)['etiqueta'],
            isset($listas[$base]) => $listas[$base]['etiqueta'],
            default => ucfirst(str_replace('_', ' ', (string) preg_replace('/^(sol|ese)_/', '', $campo))),
        };

        return ['campo' => $campo, 'base' => $base, 'fuente' => 'incorporacion', 'columna' => $base, 'etiqueta' => $etiqueta, 'tipo' => 'incorporacion'];
    }

    private static function baseDeLista(string $campo): ?string
    {
        return match (true) {
            preg_match('/^ese_dep_\d+_/', $campo) === 1 => 'dependientes',
            preg_match('/^sol_empleo_\d+_/', $campo) === 1 => 'empleos',
            preg_match('/^ese_ref_\d+$/', $campo) === 1 => 'referencias',
            default => null,
        };
    }

    /**
     * Todos los campos de ambos formatos, ya formateados.
     *
     * @return array<string, string>
     */
    public function valores(Colaborador $colaborador, CarbonImmutable $hoy): array
    {
        $v = $this->perfil->valores($colaborador);
        $listas = $this->perfil->listas($colaborador);
        $noAplica = $this->perfil->noAplica($colaborador);
        $campos = CatalogoSolicitudEmpleo::campos();

        // Texto del dato; «No aplica» si la persona lo declaró o su condición no aplica.
        $txt = function (string $clave) use ($v, $noAplica, $campos): string {
            $valor = $v[$clave] ?? null;

            if ($valor !== null && $valor !== '' && ! is_array($valor)) {
                return is_bool($valor) ? ($valor ? 'Sí' : 'No') : (string) $valor;
            }

            if (in_array($clave, $noAplica, true) || (isset($campos[$clave]) && $this->perfil->noAplicaPorCondicion($campos[$clave], $v))) {
                return self::NO_APLICA;
            }

            return '';
        };
        $marca = fn (bool $condicion): string => $condicion ? 'X' : '';
        $siNo = fn (string $clave): array => [$marca(($v[$clave] ?? null) === true), $marca(($v[$clave] ?? null) === false)];
        $en = fn (string $clave, string $valor): bool => is_array($v[$clave] ?? null) && in_array($valor, $v[$clave], true);

        $d = [];
        $nacimiento = $colaborador->fecha_nacimiento;
        $d['fecha_nacimiento_corta'] = $nacimiento !== null ? $nacimiento->format('d/m/Y') : '';

        // ── Solicitud de empleo · página 1 ──
        $d['sol_fecha_dia'] = $hoy->format('d');
        $d['sol_fecha_mes'] = $hoy->format('m');
        $d['sol_fecha_anio'] = $hoy->format('Y');
        $d['marca_sexo_f'] = $marca($colaborador->genero === Genero::Femenino);
        $d['marca_sexo_m'] = $marca($colaborador->genero === Genero::Masculino);
        $d['sol_hijos'] = $v['numero_hijos'] !== null ? (string) $v['numero_hijos'] : '';
        [$d['marca_licencia_si'], $d['marca_licencia_no']] = $siNo('licencia_moto');
        $d['sol_anios_moto'] = $this->anios($txt('anios_manejo_moto'));
        $d['sol_tiempo_traslado'] = is_int($v['tiempo_traslado_minutos']) ? $this->minutos($v['tiempo_traslado_minutos']) : '';
        $d['sol_con_quien_vive'] = $txt('con_quien_vive');
        $tenencia = is_string($v['vivienda_tenencia']) ? TenenciaVivienda::tryFrom($v['vivienda_tenencia']) : null;
        $d['sol_tenencia'] = $tenencia?->etiqueta() ?? '';
        $d['sol_tiempo_residencia'] = $this->residencia($v['tiempo_residencia_anios'], $v['tiempo_residencia_meses']);
        $d['sol_dependientes'] = $this->resumenDependientes($listas['dependientes']['filas'], $listas['dependientes']['vacia'], $hoy);
        $d['sol_telefono_alterno'] = $txt('telefono_alterno');
        $d['sol_conyuge'] = $txt('conyuge_nombre');
        [$d['marca_infonavit_si'], $d['marca_infonavit_no']] = $siNo('infonavit');
        [$d['marca_retencion_si'], $d['marca_retencion_no']] = $siNo('hoja_retencion');
        $d['sol_enfermedades'] = match ($v['padece_enfermedad_cronica']) {
            true => trim((string) $v['enfermedad_cronica_cual']) !== '' ? sprintf('Sí: %s', (string) $v['enfermedad_cronica_cual']) : '',
            false => 'No',
            default => '',
        };
        [$d['marca_proceso_legal_si'], $d['marca_proceso_legal_no']] = $siNo('proceso_legal_laboral');
        $d['marca_salud_bueno'] = $marca($v['estado_salud'] === 'bueno');
        $d['marca_salud_regular'] = $marca($v['estado_salud'] === 'regular');
        $d['marca_salud_malo'] = $marca($v['estado_salud'] === 'malo');
        $d['sol_referencias_domicilio'] = $txt('referencias_domicilio');

        // ── Solicitud de empleo · página 2 ──
        $d['marca_vacante_redes'] = $marca($v['fuente_vacante'] === 'redes_sociales');
        $d['marca_vacante_recomendacion'] = $marca($v['fuente_vacante'] === 'recomendacion');
        $d['marca_vacante_sucursal'] = $marca($v['fuente_vacante'] === 'sucursal');
        $d['marca_vacante_bolsa'] = $marca($v['fuente_vacante'] === 'bolsa_trabajo');
        $d['marca_vacante_otros'] = $marca($v['fuente_vacante'] === 'otro');
        $d['sol_vacante_otro'] = $v['fuente_vacante'] === 'otro' ? $txt('fuente_vacante_otro') : '';
        [$d['marca_aplico_si'], $d['marca_aplico_no']] = $siNo('aplico_antes');
        [$d['marca_familiares_si'], $d['marca_familiares_no']] = $siNo('familiares_empresa');
        $d['sol_familiares_nombre'] = $v['familiares_empresa'] === true ? $txt('familiares_empresa_nombre') : '';
        $escolaridad = is_string($v['escolaridad']) ? Escolaridad::tryFrom($v['escolaridad']) : null;
        $d['marca_escolaridad_bachillerato'] = $marca($escolaridad === Escolaridad::Bachillerato);
        $d['marca_escolaridad_tecnica'] = $marca($escolaridad === Escolaridad::CarreraTecnica);
        $d['marca_escolaridad_licenciatura'] = $marca(in_array($escolaridad, [Escolaridad::LicenciaturaTrunca, Escolaridad::Licenciatura, Escolaridad::Posgrado], true));
        $d['sol_escolaridad'] = $escolaridad?->etiqueta() ?? '';
        $d['sol_area_cursos'] = $txt('area_cursos');
        [$d['marca_ventas_si'], $d['marca_ventas_no']] = $siNo('exp_ventas');
        $d['sol_ventas_anios'] = $this->anios($txt('exp_ventas_anios'));
        [$d['marca_cobranza_si'], $d['marca_cobranza_no']] = $siNo('exp_cobranza');
        $d['sol_cobranza_anios'] = $this->anios($txt('exp_cobranza_anios'));
        [$d['marca_creditos_si'], $d['marca_creditos_no']] = $siNo('exp_creditos');
        $d['sol_creditos_donde'] = $txt('exp_creditos_donde');
        [$d['marca_prospeccion_si'], $d['marca_prospeccion_no']] = $siNo('prospeccion_campo');
        [$d['marca_apps_si'], $d['marca_apps_no']] = $siNo('apps_moviles');
        [$d['marca_comisiones_si'], $d['marca_comisiones_no']] = $siNo('comisiones_objetivos');
        $d['marca_estilo_metas'] = $marca($en('estilos_trabajo', EstiloTrabajo::OrientadoMetas->value));
        $d['marca_estilo_cliente'] = $marca($en('estilos_trabajo', EstiloTrabajo::EnfocadoCliente->value));
        $d['marca_estilo_campo'] = $marca($en('estilos_trabajo', EstiloTrabajo::TrabajoCampo->value));
        $d['marca_estilo_presion'] = $marca($en('estilos_trabajo', EstiloTrabajo::TrabajoPresion->value));
        $d['sol_tres_palabras'] = $txt('tres_palabras');
        $d['sol_fortalezas'] = $txt('fortalezas');
        $d['sol_logro'] = $txt('logro_profesional');
        $d['sol_situacion_dificil'] = $txt('situacion_dificil');
        $d['sol_areas_oportunidad'] = $txt('areas_oportunidad');
        $d['sol_jefes'] = $txt('como_te_describen');

        // ── Solicitud de empleo · páginas 3 y 4 (últimos 3 empleos) ──
        $empleos = $listas['empleos']['filas'];

        for ($n = 1; $n <= 3; $n++) {
            $e = $empleos[$n - 1] ?? null;
            $d["sol_empleo_{$n}_empresa"] = (string) ($e['empresa'] ?? '');
            $d["sol_empleo_{$n}_puesto"] = (string) ($e['puesto'] ?? '');
            $d["sol_empleo_{$n}_periodo"] = $e !== null ? $this->periodo($e['desde'] ?? null, $e['hasta'] ?? null) : '';
            $d["sol_empleo_{$n}_funciones"] = (string) ($e['funciones'] ?? '');
            $d["sol_empleo_{$n}_motivo"] = (string) ($e['motivo_salida'] ?? '');
            $d["sol_empleo_{$n}_telefono"] = (string) ($e['telefono'] ?? '');
        }

        if ($empleos === [] && $listas['empleos']['vacia']) {
            $d['sol_empleo_1_empresa'] = 'Sin experiencia laboral (primer empleo)';
        }

        // ── Estudio socioeconómico · hoja 1 ──
        $estadoCivil = $colaborador->estado_civil?->value;
        $d['marca_civil_casado'] = $marca($estadoCivil === 'casado');
        $d['marca_civil_soltero'] = $marca($estadoCivil === 'soltero');
        $d['marca_civil_divorciado'] = $marca($estadoCivil === 'divorciado');
        $d['marca_civil_viudo'] = $marca($estadoCivil === 'viudo');
        $d['ese_civil_otro'] = $estadoCivil === 'union_libre' ? 'Unión libre' : '';
        [$d['ese_domicilio_1'], $d['ese_domicilio_2']] = $this->domicilioEnDosRenglones($colaborador);
        $anios = $v['tiempo_residencia_anios'];
        $d['ese_residencia_anios'] = is_int($anios) ? ($anios >= 1 ? (string) $anios : '<1') : '';
        // El teléfono que PEOPLE registra es el celular de la persona; el
        // alterno (recados) va en «Otros».
        $d['marca_tel_casa'] = '';
        $d['marca_tel_oficina'] = '';
        $d['marca_tel_celular'] = $marca(trim((string) $colaborador->telefono) !== '');
        $alterno = trim((string) $v['telefono_alterno']);
        $d['ese_tel_otro'] = $alterno !== '' ? sprintf('Alterno: %s', $alterno) : $txt('telefono_alterno');
        $tipoId = $v['identificacion_tipo'];
        $d['marca_id_ine'] = $marca($tipoId === 'ine');
        $d['marca_id_licencia'] = $marca($tipoId === 'licencia');
        $d['marca_id_cedula'] = $marca($tipoId === 'cedula');
        $d['ese_id_otro'] = $tipoId === 'otro' ? $txt('identificacion_otro') : '';
        $d['ese_id_institucion'] = $tipoId === 'ine' ? 'Instituto Nacional Electoral' : ($tipoId !== null ? $txt('identificacion_institucion') : '');
        $d['ese_id_folio'] = $txt('identificacion_folio');
        $sangre = is_string($v['tipo_sanguineo']) ? TipoSanguineo::tryFrom($v['tipo_sanguineo']) : null;
        $d['ese_tipo_sanguineo'] = $sangre?->etiqueta() ?? '';

        $dependientes = $listas['dependientes']['filas'];

        for ($n = 1; $n <= 6; $n++) {
            $dep = $dependientes[$n - 1] ?? null;
            $parentesco = $dep !== null && is_string($dep['parentesco'] ?? null) ? ParentescoDependiente::tryFrom($dep['parentesco']) : null;
            $d["ese_dep_{$n}_nombre"] = (string) ($dep['nombre'] ?? '');
            $d["ese_dep_{$n}_paterno"] = (string) ($dep['apellido_paterno'] ?? '');
            $d["ese_dep_{$n}_materno"] = (string) ($dep['apellido_materno'] ?? '');
            $d["ese_dep_{$n}_parentesco"] = $parentesco === ParentescoDependiente::Otro ? (string) ($dep['parentesco_otro'] ?? 'Otro') : ($parentesco?->etiqueta() ?? '');
            $edad = $dep !== null ? $this->edad($dep, $hoy) : null;
            $d["ese_dep_{$n}_edad"] = $edad !== null ? (string) $edad : '';
            $d["ese_dep_{$n}_escolaridad"] = (string) ($dep['escolaridad_ocupacion'] ?? '');
        }

        if ($dependientes === [] && $listas['dependientes']['vacia']) {
            $d['ese_dep_1_nombre'] = 'Sin dependientes económicos';
        }

        $d['ese_entre_calle_1'] = $txt('entre_calle_1');
        $d['ese_entre_calle_2'] = $txt('entre_calle_2');
        $d['ese_calle_posterior'] = $txt('calle_posterior');
        $d['ese_calle_lateral'] = $txt('calle_lateral');

        // ── Estudio socioeconómico · hoja 2 ──
        $inmueble = $v['tiene_inmueble'];
        $d['ese_inm_ubicacion'] = match ($inmueble) {
            true => $txt('inmueble_ubicacion'),
            false => 'No aplica (sin bienes inmuebles)',
            default => '',
        };
        $d['ese_inm_metros'] = $inmueble === true && $v['inmueble_metros'] !== null ? sprintf('%s m²', number_format((float) $v['inmueble_metros'])) : '';
        $d['ese_inm_valor'] = $inmueble === true ? $this->moneda($v['inmueble_valor']) : '';

        foreach (['privada', 'ejidal', 'comunal'] as $tipo) {
            $d["marca_inm_{$tipo}"] = $marca($inmueble === true && $v['inmueble_tipo_propiedad'] === $tipo);
        }

        foreach (['urbana', 'semiurbana', 'rural'] as $zona) {
            $d["marca_inm_{$zona}"] = $marca($inmueble === true && $v['inmueble_zona'] === $zona);
        }

        foreach (['compraventa', 'herencia', 'legado', 'otro'] as $forma) {
            $d['marca_inm_'.($forma === 'otro' ? 'otros' : $forma)] = $marca($inmueble === true && $v['inmueble_adquisicion'] === $forma);
        }

        $d['ese_inm_especifique'] = $inmueble === true && $v['inmueble_adquisicion'] === 'otro' ? $txt('inmueble_adquisicion_otro') : '';

        foreach (self::EGRESOS as $egreso) {
            $d["ese_egreso_{$egreso}"] = $this->moneda($v["egreso_{$egreso}"]);
        }

        $d['ese_egreso_otros_concepto'] = is_numeric($v['egreso_otros']) && (float) $v['egreso_otros'] > 0 ? $txt('egreso_otros_concepto') : '';

        $referencias = $listas['referencias']['filas'];

        for ($n = 1; $n <= 4; $n++) {
            $r = $referencias[$n - 1] ?? null;
            $d["ese_ref_{$n}"] = $r !== null ? $this->referencia($r) : '';
        }

        $vivienda = $v['vivienda_tipo'];
        $d['marca_viv_casa'] = $marca($vivienda === 'casa');
        $d['marca_viv_departamento'] = $marca($vivienda === 'departamento');
        $d['marca_viv_cuarto'] = $marca($vivienda === 'cuarto');

        foreach (['propia', 'renta', 'prestada', 'hipotecada'] as $tenenciaClave) {
            $d["marca_viv_{$tenenciaClave}"] = $marca($v['vivienda_tenencia'] === $tenenciaClave);
        }

        // ── Estudio socioeconómico · hoja 3 ──
        foreach (['concreto', 'lamina', 'adobe', 'otro'] as $pared) {
            $d['marca_pared_'.($pared === 'otro' ? 'otros' : $pared)] = $marca($v['paredes'] === $pared);
        }

        $d['ese_paredes_especifique'] = $v['paredes'] === 'otro' ? $txt('paredes_otro') : '';
        $d['ese_cuartos'] = $v['numero_cuartos'] !== null ? (string) $v['numero_cuartos'] : '';
        $d['ese_banos'] = $v['numero_banos'] !== null ? (string) $v['numero_banos'] : '';
        $d['ese_patio'] = is_bool($v['tiene_patio']) ? ($v['tiene_patio'] ? 'Sí' : 'No') : '';
        $d['ese_construccion_otros'] = $txt('construccion_otros');

        foreach (['agua', 'luz', 'drenaje', 'gas', 'telefono', 'otro'] as $servicio) {
            $d['marca_serv_'.($servicio === 'otro' ? 'otros' : $servicio)] = $marca($en('servicios_vivienda', $servicio));
        }

        $d['ese_servicios_especifique'] = $en('servicios_vivienda', 'otro') ? $txt('servicios_vivienda_otro') : '';
        $d['ese_personas_habitan'] = $v['personas_habitan'] !== null ? (string) $v['personas_habitan'] : '';

        $vehiculo = $v['tiene_vehiculo'];
        $d['marca_bien_vehiculo'] = $marca($vehiculo === true && $v['vehiculo_tipo'] === 'automovil');
        $d['marca_bien_moto'] = $marca($vehiculo === true && $v['vehiculo_tipo'] === 'moto');
        $d['marca_bien_otros'] = $marca($vehiculo === true && $v['vehiculo_tipo'] === 'otro');
        $d['ese_vehiculo_especifique'] = $vehiculo === true && $v['vehiculo_tipo'] === 'otro' ? $txt('vehiculo_otro') : '';
        $d['ese_vehiculo_modelo'] = match ($vehiculo) {
            true => $txt('vehiculo_modelo'),
            false => 'No aplica (sin vehículo)',
            default => '',
        };
        $d['ese_vehiculo_tipo'] = $vehiculo === true ? $txt('vehiculo_tipo_detalle') : '';
        $d['ese_vehiculo_marca'] = $vehiculo === true ? $txt('vehiculo_marca') : '';
        $d['ese_vehiculo_valor'] = $vehiculo === true ? $this->moneda($v['vehiculo_valor']) : '';

        foreach (BienMueble::cases() as $bien) {
            $d['marca_mueble_'.$bien->value] = $marca($en('bienes_muebles', $bien->value));
        }

        $d['ese_muebles_especifique'] = $en('bienes_muebles', 'otro') ? $txt('bienes_muebles_otro') : '';

        foreach (['issste', 'imss', 'seguro_popular', 'particular'] as $servicioMedico) {
            $d["marca_sm_{$servicioMedico}"] = $marca($v['servicio_medico'] === $servicioMedico);
        }

        $d['ese_sm_especifique'] = $v['servicio_medico'] === 'ninguno' ? 'Ninguno' : '';
        $d['ese_fecha_dia'] = $hoy->format('d');
        $d['ese_fecha_mes'] = self::MESES[$hoy->month - 1];
        $d['ese_fecha_anio'] = $hoy->format('Y');

        return $d;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function resumenDependientes(array $filas, bool $vacia, CarbonImmutable $hoy): string
    {
        if ($filas === []) {
            return $vacia ? 'Sin dependientes económicos' : '';
        }

        return implode(', ', array_map(function (array $f) use ($hoy): string {
            $nombre = trim(sprintf('%s %s', (string) ($f['nombre'] ?? ''), (string) ($f['apellido_paterno'] ?? '')));
            $edad = $this->edad($f, $hoy);

            return $edad !== null ? sprintf('%s (%d)', $nombre, $edad) : $nombre;
        }, $filas));
    }

    /**
     * Edad a la fecha del documento (CDMX); sin fecha de nacimiento, la declarada.
     *
     * @param  array<string, mixed>  $fila
     */
    private function edad(array $fila, CarbonImmutable $hoy): ?int
    {
        $nacimiento = $fila['fecha_nacimiento'] ?? null;

        if (is_string($nacimiento) && $nacimiento !== '') {
            return (int) CarbonImmutable::parse($nacimiento, 'America/Mexico_City')->startOfDay()->diffInYears($hoy->startOfDay());
        }

        return is_numeric($fila['edad'] ?? null) ? (int) $fila['edad'] : null;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function referencia(array $r): string
    {
        $partes = [trim((string) ($r['nombre'] ?? '')), trim((string) ($r['relacion'] ?? '')), sprintf('Tel. %s', trim((string) ($r['telefono'] ?? '')))];

        if (is_numeric($r['anios_conocerlo'] ?? null)) {
            $partes[] = sprintf('%d año%s de conocerle', (int) $r['anios_conocerlo'], (int) $r['anios_conocerlo'] === 1 ? '' : 's');
        }

        return implode(' — ', array_filter($partes, fn (string $p): bool => $p !== ''));
    }

    private function periodo(mixed $desde, mixed $hasta): string
    {
        $formato = fn (mixed $mes): string => is_string($mes) && preg_match('/^(\d{4})-(\d{2})/', $mes, $m) === 1 ? sprintf('%s/%s', $m[2], $m[1]) : '';
        $inicio = $formato($desde);

        if ($inicio === '') {
            return '';
        }

        $fin = $formato($hasta);

        return sprintf('%s – %s', $inicio, $fin !== '' ? $fin : 'actual');
    }

    private function anios(string $valor): string
    {
        if ($valor === '' || $valor === self::NO_APLICA) {
            return $valor;
        }

        return sprintf('%s año%s', $valor, $valor === '1' ? '' : 's');
    }

    private function minutos(int $minutos): string
    {
        if ($minutos < 60) {
            return sprintf('%d minutos', $minutos);
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto === 0 ? sprintf('%d h', $horas) : sprintf('%d h %d min', $horas, $resto);
    }

    private function residencia(mixed $anios, mixed $meses): string
    {
        if (! is_int($anios)) {
            return '';
        }

        $meses = is_int($meses) ? $meses : 0;
        $partes = [];

        if ($anios > 0) {
            $partes[] = sprintf('%d año%s', $anios, $anios === 1 ? '' : 's');
        }

        if ($meses > 0) {
            $partes[] = sprintf('%d mes%s', $meses, $meses === 1 ? '' : 'es');
        }

        return $partes === [] ? 'Menos de un mes' : implode(' y ', $partes);
    }

    private function moneda(mixed $valor): string
    {
        return is_numeric($valor) ? number_format((float) $valor, 2) : '';
    }

    /**
     * Domicilio del Estudio socioeconómico en sus dos renglones: calle y
     * número / colonia y C.P. (municipio y entidad tienen su propia caja).
     *
     * @return array{0: string, 1: string}
     */
    private function domicilioEnDosRenglones(Colaborador $c): array
    {
        // Un marcador de relleno («REGISTRADO», «S/D»…) nunca se imprime:
        // sin calle real el renglón queda vacío y el motor la pide.
        $calle = DatoMarcador::limpiar($c->domicilio_calle);
        $colonia = trim((string) $c->domicilio_colonia);
        $cp = trim((string) $c->domicilio_cp);
        $renglon2 = implode(', ', array_filter([$colonia !== '' ? sprintf('Col. %s', $colonia) : '', $cp !== '' ? sprintf('C.P. %s', $cp) : '']));

        if ($calle === '') {
            $legado = DatoMarcador::limpiar($c->domicilio);

            return $legado !== '' ? [$legado, ''] : ['', ''];
        }

        $exterior = trim((string) $c->domicilio_numero_exterior);
        $interior = trim((string) $c->domicilio_numero_interior);
        $renglon1 = trim(sprintf('%s %s%s', $calle, $exterior !== '' ? $exterior : 'S/N', $interior !== '' ? sprintf(', int. %s', $interior) : ''));

        return [$renglon1, $renglon2];
    }
}
