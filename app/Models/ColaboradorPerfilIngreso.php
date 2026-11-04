<?php

namespace App\Models;

use App\Enums\EstadoSalud;
use App\Enums\FuenteVacante;
use App\Enums\MaterialParedes;
use App\Enums\TenenciaVivienda;
use App\Enums\TipoIdentificacion;
use App\Enums\TipoVivienda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perfil de ingreso (1:1 con el colaborador): lo que la Solicitud de empleo
 * y el Estudio socioeconómico piden y no vive en `colaboradores`. Lo captura
 * la persona en el registro de la app (o RH completa lo faltante en la web);
 * ver App\Services\Incorporacion\SolicitudEmpleo\PerfilIngresoService.
 *
 * `situacion_economica` (egresos, INFONAVIT, inmueble, vehículo, bienes,
 * servicio médico) e `identificacion_folio` van cifrados en reposo. Nunca
 * se exponen en listados.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $numero_hijos
 * @property string|null $conyuge_nombre
 * @property EstadoSalud|null $estado_salud
 * @property bool|null $padece_enfermedad_cronica
 * @property bool|null $proceso_legal_laboral
 * @property TipoIdentificacion|null $identificacion_tipo
 * @property string|null $identificacion_otro
 * @property string|null $identificacion_institucion
 * @property string|null $identificacion_folio
 * @property int|null $tiempo_residencia_anios
 * @property int|null $tiempo_residencia_meses
 * @property string|null $con_quien_vive
 * @property int|null $tiempo_traslado_minutos
 * @property string|null $entre_calle_1
 * @property string|null $entre_calle_2
 * @property string|null $calle_posterior
 * @property string|null $calle_lateral
 * @property string|null $referencias_domicilio
 * @property bool $sin_dependientes
 * @property bool $sin_experiencia_laboral
 * @property TipoVivienda|null $vivienda_tipo
 * @property TenenciaVivienda|null $vivienda_tenencia
 * @property MaterialParedes|null $paredes
 * @property string|null $paredes_otro
 * @property int|null $numero_cuartos
 * @property int|null $numero_banos
 * @property bool|null $tiene_patio
 * @property string|null $construccion_otros
 * @property list<string>|null $servicios_vivienda
 * @property string|null $servicios_vivienda_otro
 * @property int|null $personas_habitan
 * @property array<string, mixed>|null $situacion_economica
 * @property string|null $area_cursos
 * @property bool|null $licencia_moto
 * @property int|null $anios_manejo_moto
 * @property bool|null $exp_ventas
 * @property int|null $exp_ventas_anios
 * @property bool|null $exp_cobranza
 * @property int|null $exp_cobranza_anios
 * @property bool|null $exp_creditos
 * @property string|null $exp_creditos_donde
 * @property bool|null $prospeccion_campo
 * @property bool|null $apps_moviles
 * @property bool|null $comisiones_objetivos
 * @property list<string>|null $estilos_trabajo
 * @property FuenteVacante|null $fuente_vacante
 * @property string|null $fuente_vacante_otro
 * @property bool|null $aplico_antes
 * @property bool|null $familiares_empresa
 * @property string|null $familiares_empresa_nombre
 * @property string|null $tres_palabras
 * @property string|null $fortalezas
 * @property string|null $logro_profesional
 * @property string|null $situacion_dificil
 * @property string|null $areas_oportunidad
 * @property string|null $como_te_describen
 * @property list<string>|null $no_aplica
 * @property array<string, mixed>|null $secciones Bitácora por sección (guardada_en, origen, por) y `_campos_rh`.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ColaboradorPerfilIngreso extends Model
{
    protected $table = 'colaborador_perfiles_ingreso';

    protected $guarded = ['id', 'colaborador_id'];

    protected $hidden = ['identificacion_folio', 'situacion_economica'];

    protected function casts(): array
    {
        return [
            'estado_salud' => EstadoSalud::class,
            'padece_enfermedad_cronica' => 'boolean',
            'proceso_legal_laboral' => 'boolean',
            'identificacion_tipo' => TipoIdentificacion::class,
            'identificacion_folio' => 'encrypted',
            'sin_dependientes' => 'boolean',
            'sin_experiencia_laboral' => 'boolean',
            'vivienda_tipo' => TipoVivienda::class,
            'vivienda_tenencia' => TenenciaVivienda::class,
            'paredes' => MaterialParedes::class,
            'tiene_patio' => 'boolean',
            'servicios_vivienda' => 'array',
            'situacion_economica' => 'encrypted:array',
            'licencia_moto' => 'boolean',
            'exp_ventas' => 'boolean',
            'exp_cobranza' => 'boolean',
            'exp_creditos' => 'boolean',
            'prospeccion_campo' => 'boolean',
            'apps_moviles' => 'boolean',
            'comisiones_objetivos' => 'boolean',
            'estilos_trabajo' => 'array',
            'fuente_vacante' => FuenteVacante::class,
            'aplico_antes' => 'boolean',
            'familiares_empresa' => 'boolean',
            'no_aplica' => 'array',
            'secciones' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
