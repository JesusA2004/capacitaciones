<?php

namespace App\Services\Plantillas;

use App\Models\DocumentTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Compara los marcadores {{...}} que de verdad aparecen en el DOCX de una
 * plantilla (PlantillaDocumentoService::variablesEnPlantilla) contra el
 * catálogo de variables conocidas (PlaceholderResolver) y las variables
 * manuales que RH ya declaró para esa plantilla (DocumentTemplate::variables_manuales),
 * para saber cuáles quedan "sin mapear" — ver docs/DOCX_TEMPLATES.md.
 */
class VariableMappingService
{
    /**
     * Agrupación de las variables conocidas para el catálogo de referencia
     * en Portal RH (docs/DOCX_TEMPLATES.md, sección "Variables disponibles").
     * Cualquier clave de PlaceholderResolver que no aparezca aquí cae en
     * "Otros" — nunca se oculta, solo queda sin agrupar.
     *
     * @var array<string, list<string>>
     */
    private const GRUPOS = [
        'Colaborador' => [
            'nombre_colaborador', 'apellidos_colaborador', 'nombre_completo', 'curp', 'rfc', 'nss',
            'domicilio', 'telefono', 'telefono_corporativo', 'correo', 'correo_personal', 'correo_corporativo',
            'fecha_nacimiento',
        ],
        'Laboral' => [
            'numero_empleado', 'sucursal', 'departamento', 'puesto', 'jefe_directo', 'gerente',
            'fecha_ingreso', 'sueldo_mensual', 'sueldo_diario', 'tipo_contratacion',
            'periodo_prueba_inicio', 'periodo_prueba_fin', 'fecha_inicio_contrato', 'fecha_fin_contrato',
        ],
        'Empresa' => ['empresa', 'empresa_razon_social', 'empresa_rfc'],
        'Solicitud' => [
            'dias_vacaciones', 'fecha_inicio_permiso', 'fecha_fin_permiso', 'motivo_permiso',
            'tipo_solicitud', 'folio_solicitud', 'fecha_inicio_incapacidad', 'fecha_fin_incapacidad',
            'motivo_solicitud', 'observaciones',
        ],
        'Préstamo' => [
            'monto_prestamo', 'plazo_prestamo', 'pago_prestamo', 'saldo_prestamo',
            'monto_solicitado_prestamo', 'periodicidad_prestamo', 'motivo_prestamo',
        ],
        'Cierre laboral' => ['tipo_baja', 'motivo_baja', 'fecha_baja'],
        'Actas' => [
            'folio_acta', 'tipo_acta', 'fecha_acta', 'hora_acta', 'lugar_acta',
            'hechos_acta', 'responsable_acta', 'testigos_acta', 'declaraciones_acta',
        ],
        'Fecha' => ['fecha_actual'],
    ];

    public function __construct(
        private readonly PlantillaDocumentoService $documento,
        private readonly PlaceholderResolver $resolver,
    ) {}

    /**
     * @return list<string>
     */
    public function clavesConocidas(): array
    {
        return array_keys($this->resolver->resolver(null));
    }

    /**
     * Catálogo de variables conocidas agrupado, para que RH copie el
     * marcador sin tener que memorizar los códigos (sección "Variables
     * disponibles" del editor de plantillas).
     *
     * @return list<array{grupo: string, variables: list<array{clave: string, etiqueta: string}>}>
     */
    public function catalogoConocidas(): array
    {
        $todas = $this->clavesConocidas();
        $agrupadas = [];
        $catalogo = [];

        foreach (self::GRUPOS as $grupo => $claves) {
            $enCatalogo = array_values(array_intersect($claves, $todas));
            if ($enCatalogo === []) {
                continue;
            }
            $agrupadas = [...$agrupadas, ...$enCatalogo];
            $catalogo[] = [
                'grupo' => $grupo,
                'variables' => array_map(fn (string $clave) => ['clave' => $clave, 'etiqueta' => $this->etiquetar($clave)], $enCatalogo),
            ];
        }

        $sinGrupo = array_values(array_diff($todas, $agrupadas));
        if ($sinGrupo !== []) {
            $catalogo[] = [
                'grupo' => 'Otros',
                'variables' => array_map(fn (string $clave) => ['clave' => $clave, 'etiqueta' => $this->etiquetar($clave)], $sinGrupo),
            ];
        }

        return $catalogo;
    }

    /**
     * Acepta acrónimos conocidos (CURP, RFC, NSS) tal cual, para que un
     * mensaje de "falta este dato" no diga "Curp" sino "CURP" — el resto se
     * humaniza reemplazando guion_bajo por espacio.
     */
    private const ACRONIMOS = ['curp' => 'CURP', 'rfc' => 'RFC', 'nss' => 'NSS'];

    public function etiquetar(string $clave): string
    {
        return self::ACRONIMOS[$clave] ?? Str::of($clave)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Definiciones que RH declaró para esta plantilla en `variables_manuales`,
     * filtradas a solo las que corresponden a un marcador SIN dato real
     * conocido (lo que el editor "Variables" llama manuales/fill-in) — un
     * dato automático marcado como requerido vive en variables_manuales con
     * la misma forma, pero se consulta aparte con automaticasConfiguradas(),
     * nunca aquí (nunca se le pide a RH que "llene a mano" un dato
     * automático, ver docs/DOCX_TEMPLATES.md).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function manuales(DocumentTemplate $plantilla): Collection
    {
        $conocidas = $this->clavesConocidas();
        $resultado = [];

        foreach ($plantilla->variables_manuales ?? [] as $def) {
            if (! in_array($def['clave'] ?? null, $conocidas, true)) {
                $resultado[] = $def;
            }
        }

        return collect($resultado);
    }

    /**
     * Subconjunto de `variables_manuales` que SÍ corresponde a un dato
     * automático conocido (PlaceholderResolver) — RH solo puede declarar
     * ahí si lo marca requerido u opcional, nunca su etiqueta/tipo/valor: el
     * valor lo sigue resolviendo el dato real del colaborador/candidato.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function automaticasConfiguradas(DocumentTemplate $plantilla): Collection
    {
        $conocidas = array_flip($this->clavesConocidas());
        $resultado = [];

        foreach ($plantilla->variables_manuales ?? [] as $def) {
            $clave = $def['clave'] ?? null;

            if (is_string($clave) && isset($conocidas[$clave])) {
                $resultado[] = $def;
            }
        }

        return collect($resultado);
    }

    /**
     * @return list<string>
     */
    public function clavesManuales(DocumentTemplate $plantilla): array
    {
        return array_values($this->manuales($plantilla)->pluck('clave')->map(fn (mixed $clave): string => (string) $clave)->all());
    }

    /**
     * Claves marcadas como requeridas en `variables_manuales` — sea un
     * marcador manual (RH lo llena) o uno automático que RH decidió volver
     * obligatorio (ej. {{curp}}): ambos bloquean `puede_generar` igual si
     * resuelven vacío, ver FormatoPreviewService::previsualizar().
     *
     * @return list<string>
     */
    public function clavesRequeridas(DocumentTemplate $plantilla): array
    {
        return array_values(collect($plantilla->variables_manuales ?? [])
            ->where('requerido', true)
            ->pluck('clave')
            ->map(fn (mixed $clave): string => (string) $clave)
            ->all());
    }

    /**
     * Marcadores detectados en el DOCX que no corresponden a ningún dato
     * real (PlaceholderResolver) — el universo del que puede salir una
     * variable manual, esté ya declarada o no.
     *
     * @return list<string>
     */
    public function clavesNoConocidas(DocumentTemplate $plantilla): array
    {
        $detectadas = $this->documento->variablesEnPlantilla($plantilla);

        return array_values(array_diff($detectadas, $this->clavesConocidas()));
    }

    /**
     * Marcadores detectados en el DOCX que no son ni una variable conocida
     * ni una variable manual ya declarada — RH debe mapearlos o el
     * placeholder queda literal en el documento generado.
     *
     * @return list<string>
     */
    public function sinMapear(DocumentTemplate $plantilla): array
    {
        return array_values(array_diff($this->clavesNoConocidas($plantilla), $this->clavesManuales($plantilla)));
    }

    /**
     * Claves permitidas en `extra` al previsualizar/generar con esta
     * plantilla: variables conocidas + las manuales que RH ya declaró.
     * Cualquier otra clave se rechaza — nunca se inyecta un placeholder
     * arbitrario que no exista en el catálogo ni en la configuración de la
     * plantilla.
     *
     * @return list<string>
     */
    public function clavesPermitidasEnExtra(DocumentTemplate $plantilla): array
    {
        return array_values(array_unique([...$this->clavesConocidas(), ...$this->clavesManuales($plantilla)]));
    }
}
