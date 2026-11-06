# API — datos de la Solicitud de empleo y el Estudio socioeconómico

Contrato para la app (Expo) de la captura que llena **todos** los campos de los
PDF `solicitud_empleo.general` (sol_*) y `estudio_socioeconomico.general`
(ese_*). Fuente única del catálogo: `App\Services\Incorporacion\SolicitudEmpleo\CatalogoSolicitudEmpleo`
(el GET de abajo siempre lo refleja: si este archivo y el GET difieren, **manda
el GET**). Modelo de datos y reglas: `docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md`.

> Estado: los endpoints del registro (1, 2 y 3) **ya existen en producción** y
> la app ya los consume (`src/api/solicitudEmpleo.ts`). Lo nuevo de esta ronda
> está marcado **[NUEVO]** y es retrocompatible (solo agrega claves/rutas).

## Dónde se guarda (todo es del Colaborador)

| Almacén | Tabla / columna |
|---|---|
| `base` | `colaboradores` (nombre, sexo, nacimiento, celular, estado civil, correo, CURP, RFC, NSS, domicilio estructurado). Se captura en «Datos personales» (`POST registro/datos`); aquí llega `bloqueado: true` |
| `colaborador` | `colaboradores.telefono_alterno`, `colaboradores.escolaridad` |
| `medicos` | `colaborador_datos_medicos.tipo_sanguineo`, `.condicion_medica` (cifrados) |
| `perfil` | `colaborador_perfiles_ingreso` (1:1) |
| `economica` | `colaborador_perfiles_ingreso.situacion_economica` (JSON cifrado) |
| listas | `colaborador_dependientes` (≤6), `colaborador_empleos_anteriores` (≤3), `colaborador_referencias_personales` (2–4) |

## Endpoints (Sanctum, cuenta en sesión, nunca por id)

### 1. `GET /api/v1/colaborador/registro/solicitud` — esquema + valores

Cabecera: `X-People-Solicitud-Empleo: 1` (declara que la app implementa estas
pantallas → `exigida: true` y `registro/finalizar` exige la completitud).

```json
{
  "data": {
    "registro_abierto": true,
    "exigida": true,
    "modo": "registro",
    "secciones": [
      {
        "clave": "personal", "titulo": "Datos personales", "descripcion": "…",
        "completa": false, "porcentaje": 60, "faltantes": ["Tipo sanguíneo"],
        "guardada_en": "2026-11-04T10:00:00-06:00", "origen": "app",
        "campos": [
          {
            "campo": "tipo_sanguineo", "etiqueta": "Tipo sanguíneo", "tipo": "select",
            "opciones": [{ "value": "o_positivo", "label": "O+" }],
            "valor": null, "sugerencia": null,
            "requerido": true, "requerido_si": null,
            "permite_no_aplica": false, "no_aplica": false,
            "bloqueado": false, "origen": "solicitud",
            "documentos": ["estudio_socioeconomico"],
            "min": null, "max": null, "ayuda": "Si no lo sabes, elige «No lo sé»."
          }
        ],
        "lista": null
      }
    ],
    "completitud": {
      "completo": false, "porcentaje": 42, "total": 120, "completos": 50,
      "faltantes": [{ "seccion": "personal", "campo": "tipo_sanguineo", "etiqueta": "Tipo sanguíneo", "documentos": ["estudio_socioeconomico"], "origen": "solicitud" }],
      "secciones": [{ "clave": "personal", "titulo": "Datos personales", "completa": false, "porcentaje": 60, "faltantes": ["…"], "guardada_en": null, "origen": null }],
      "documentos": {
        "solicitud_empleo": { "clave": "solicitud_empleo", "nombre": "Solicitud de Empleo", "completo": false, "faltantes": ["…"], "mensaje": "Faltan 3 datos para generar la Solicitud de Empleo: …" },
        "estudio_socioeconomico": { "clave": "estudio_socioeconomico", "nombre": "Estudio Socioeconómico", "completo": false, "faltantes": ["…"], "mensaje": "…" }
      }
    }
  }
}
```

Sección con lista (`dependientes`, `experiencia`, `referencias`): `campos` trae
los campos sueltos de la sección (puede ser `[]`) y `lista`:

```json
{
  "clave": "dependientes", "etiqueta": "Dependientes económicos", "singular": "Dependiente",
  "minimo": 0, "maximo": 6, "bandera_vacia": "sin_dependientes", "etiqueta_vacia": "No tengo dependientes económicos",
  "vacia": false, "bloqueada": false,
  "campos": [{ "campo": "nombre", "etiqueta": "Nombre(s)", "tipo": "texto", "requerido": true, "opciones": [], "min": null, "max": 120 }],
  "filas": [{ "nombre": "Luis", "apellido_paterno": "Torres", "apellido_materno": "Ruiz", "parentesco": "hijo", "parentesco_otro": null, "fecha_nacimiento": "2016-05-01", "edad": null, "escolaridad_ocupacion": "Primaria" }]
}
```

Tipos: `texto` | `texto_largo` | `entero` | `moneda` (número, 2 decimales) |
`booleano` | `select` | `multiselect` (arreglo de `value`) | `fecha`
(AAAA-MM-DD) | `mes` (AAAA-MM, solo en listas).

- `requerido_si = [campo, operador, valor]`, operador `igual` | `en` |
  `distinto` | `positivo` | `contiene`. Mostrar el campo solo si se cumple; si
  no se cumple, el PDF imprime «No aplica» y no se pide.
- `permite_no_aplica`: la app ofrece el interruptor «No aplica».
- `sugerencia`: prellenado con lo que PEOPLE ya sabe (fuente del candidato,
  arraigo de la visita, INE si hay clave de elector…). Ponerla como valor
  inicial; solo se guarda al guardar la sección.
- `bloqueado: true`: no editable aquí (`origen: "datos_personales"` = se edita
  en el paso Datos personales; `origen: "rh"` = lo capturó RH;
  [NUEVO] `origen: "correccion"` = ya registrado, cambiarlo es por solicitud de corrección).

**Orden de secciones (wizard):** `personal`, `domicilio`, `dependientes`,
`vivienda`, `economica`, `escolaridad`, `experiencia`, `referencias`, `perfil`.

### 2. `POST /api/v1/colaborador/registro/solicitud/{seccion}` — guardado parcial

```json
{ "datos": { "numero_hijos": 2, "tipo_sanguineo": "o_positivo" }, "no_aplica": ["telefono_alterno"] }
```

- Solo cambian las claves enviadas; **nada se exige al guardar** (guardar y
  continuar). `no_aplica` = lista COMPLETA de esa sección (omitirla = sin cambios).
- Listas: se envía la lista completa (reemplaza) + su bandera:
  `{"datos": {"sin_dependientes": false, "dependientes": [ {…} ]}}`,
  `{"datos": {"sin_experiencia_laboral": false, "empleos": [ {…} ]}}`,
  `{"datos": {"referencias": [ {…} ]}}`.
- 200: `{ "data": <mismo objeto del GET>, "message": "Sección guardada." }`.
- 422 (español): `errors["datos.tipo_sanguineo"] = ["Elige una opción válida en Tipo sanguíneo."]`,
  `errors.registro = ["Tu registro ya está completo. …"]`, `errors.datos = ["Recursos Humanos ya registró: …"]`.

### 3. «Completar» = `POST /api/v1/colaborador/registro/finalizar`

Con `X-People-Solicitud-Empleo: 1` valida **todos** los requeridos (y los
condicionales que apliquen) de ambos documentos:
422 `errors.solicitud_empleo = ["Completa tu solicitud de empleo y estudio socioeconómico. Falta: Datos personales: …; Vivienda: …."]`.
Para validar sin cerrar el registro use `data.completitud` del GET (`completo`,
`faltantes`). `GET /api/v1/colaborador/registro` trae el resumen en
`data.solicitud_empleo` (`exigida`, `completo`, `porcentaje`, `secciones`,
`documentos`, `pendientes`).

### 4. [NUEVO] Registro ya cerrado — completar lo que falta

Para colaboradores activos que nunca capturaron estos datos:

- `GET /api/v1/colaborador/solicitud-empleo` → mismo `data` que (1), con
  `"modo": "completar"` y `registro_abierto: false`. Los campos que **ya tienen
  valor** llegan con `bloqueado: true` y `origen: "correccion"`; los vacíos son
  editables. Una lista ya capturada llega con `bloqueada: true`.
- `POST /api/v1/colaborador/solicitud-empleo/{seccion}` → mismo cuerpo y
  respuesta que (2). Solo escribe datos vacíos; un dato con valor distinto →
  422 `errors.datos = ["Estos datos ya están registrados; para cambiarlos solicita una corrección: …"]`.
- No requiere cabecera ni bloquea la app: mostrar aviso «Completa tu solicitud
  de empleo» mientras `completitud.completo = false`.

## Cómo llega al PDF

`App\Services\Incorporacion\SolicitudEmpleo\DatosSolicitudEmpleoService::valores()`
convierte los datos en los `sol_*`, `ese_*` y `marca_*` (casillas = «X»).

- Estado civil (Estudio): casado/soltero/divorciado/viudo marcan su casilla;
  **unión libre marca «Otro» y escribe «Unión libre»**.
- Teléfono (Estudio): el celular marca «Celular»; el teléfono alterno va en «Otros».
- Croquis de ambos formatos: se dibuja a mano (no hay campo).

## Campos (generado de `CatalogoSolicitudEmpleo`)

«requerido: sí» = obligatorio siempre; «condicional» = solo si se cumple
`requerido_si`; «no_aplica: sí» = admite «No aplica». Los `base` se capturan en
Datos personales (aquí vienen bloqueados).

### `personal` — Datos personales

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `name` Nombre(s) | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `apellidos` Apellidos | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `genero` Sexo | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo |
| `fecha_nacimiento` Fecha de nacimiento | fecha | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `telefono` Teléfono celular | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `estado_civil` Estado civil | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `correo_personal` Correo electrónico | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo |
| `curp` CURP | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `rfc` RFC | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo |
| `nss` Número de seguridad social (IMSS) | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo |
| `telefono_alterno` Teléfono alterno (recados) | texto | sí |  | sí | max 20 | solicitud_empleo |
| `numero_hijos` Número de hijos | entero | sí |  |  | min 0 max 20 | solicitud_empleo |
| `conyuge_nombre` Nombre de tu cónyuge | texto | condicional | `["estado_civil","en",["casado","union_libre"]]` |  | max 191 | solicitud_empleo |
| `tipo_sanguineo` Tipo sanguíneo | select | sí |  |  | `o_positivo`, `o_negativo`, `a_positivo`, `a_negativo`, `b_positivo`, `b_negativo`, `ab_positivo`, `ab_negativo`, `desconocido` | estudio_socioeconomico |
| `estado_salud` ¿Cómo consideras tu estado de salud? | select | sí |  |  | `bueno`, `regular`, `malo` | solicitud_empleo |
| `padece_enfermedad_cronica` ¿Padeces alguna enfermedad crónica? | booleano | sí |  |  |  | solicitud_empleo |
| `enfermedad_cronica_cual` ¿Cuál enfermedad crónica? | texto | condicional | `["padece_enfermedad_cronica","igual",true]` |  | max 120 | solicitud_empleo |
| `proceso_legal_laboral` ¿Estás o has estado en algún proceso legal laboral? | booleano | sí |  |  |  | solicitud_empleo |
| `identificacion_tipo` Identificación oficial | select | sí |  |  | `ine`, `licencia`, `cedula`, `otro` | estudio_socioeconomico |
| `identificacion_otro` Otra identificación (cuál) | texto | condicional | `["identificacion_tipo","igual","otro"]` |  | max 80 | estudio_socioeconomico |
| `identificacion_institucion` Institución que expide la identificación | texto | condicional | `["identificacion_tipo","distinto","ine"]` |  | max 150 | estudio_socioeconomico |
| `identificacion_folio` Número de folio de la identificación | texto | sí |  |  | max 40 | estudio_socioeconomico |

### `domicilio` — Domicilio

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `domicilio_calle` Calle | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `domicilio_colonia` Colonia | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `domicilio_municipio` Municipio | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `domicilio_estado` Estado | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `domicilio_cp` Código postal | texto | sí (datos personales, bloqueado) |  |  |  | solicitud_empleo, estudio_socioeconomico |
| `tiempo_residencia_anios` Años viviendo en tu domicilio | entero | sí |  |  | min 0 max 99 | solicitud_empleo, estudio_socioeconomico |
| `tiempo_residencia_meses` Meses adicionales viviendo en tu domicilio | entero | no |  |  | min 0 max 11 | solicitud_empleo, estudio_socioeconomico |
| `con_quien_vive` ¿Con quién vives? | texto | sí |  |  | max 150 | solicitud_empleo |
| `tiempo_traslado_minutos` Minutos de tu domicilio a la sucursal | entero | sí |  |  | min 1 max 600 | solicitud_empleo |
| `entre_calle_1` Entre calle | texto | sí |  |  | max 120 | estudio_socioeconomico |
| `entre_calle_2` Y calle | texto | sí |  |  | max 120 | estudio_socioeconomico |
| `calle_posterior` Calle de atrás | texto | sí |  | sí | max 120 | estudio_socioeconomico |
| `calle_lateral` Otra calle de referencia | texto | sí |  | sí | max 120 | estudio_socioeconomico |
| `referencias_domicilio` Referencias para llegar a tu domicilio | texto_largo | sí |  |  | max 300 | solicitud_empleo |

### `dependientes` — Familia y dependientes

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|

Lista `dependientes` (0–6 renglones; bandera `sin_dependientes`):

| campo | tipo | requerido | opciones / límites |
|---|---|---|---|
| `nombre` Nombre(s) | texto | sí | max 120 |
| `apellido_paterno` Apellido paterno | texto | sí | max 80 |
| `apellido_materno` Apellido materno | texto | no | max 80 |
| `parentesco` Parentesco | select | sí | `hijo`, `conyuge`, `padre`, `madre`, `hermano`, `abuelo`, `nieto`, `otro` |
| `parentesco_otro` Otro parentesco (cuál) | texto | no | max 60 |
| `fecha_nacimiento` Fecha de nacimiento | fecha | no |  |
| `edad` Edad (si no sabes la fecha de nacimiento) | entero | no | min 0 max 120 |
| `escolaridad_ocupacion` Escolaridad u ocupación | texto | sí | max 120 |

### `vivienda` — Vivienda

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `vivienda_tipo` Tipo de vivienda | select | sí |  |  | `casa`, `departamento`, `cuarto` | estudio_socioeconomico |
| `vivienda_tenencia` La vivienda es | select | sí |  |  | `propia`, `renta`, `prestada`, `hipotecada` | solicitud_empleo, estudio_socioeconomico |
| `paredes` Material de las paredes | select | sí |  |  | `concreto`, `lamina`, `adobe`, `otro` | estudio_socioeconomico |
| `paredes_otro` Otro material de paredes (cuál) | texto | condicional | `["paredes","igual","otro"]` |  | max 80 | estudio_socioeconomico |
| `numero_cuartos` Número de cuartos | entero | sí |  |  | min 0 max 30 | estudio_socioeconomico |
| `numero_banos` Número de baños | entero | sí |  |  | min 0 max 20 | estudio_socioeconomico |
| `tiene_patio` ¿Tiene patio? | booleano | sí |  |  |  | estudio_socioeconomico |
| `construccion_otros` Otras características de la construcción | texto | sí |  | sí | max 120 | estudio_socioeconomico |
| `servicios_vivienda` Servicios con los que cuenta la vivienda | multiselect | sí |  |  | `agua`, `luz`, `drenaje`, `gas`, `telefono`, `otro` | estudio_socioeconomico |
| `servicios_vivienda_otro` Otro servicio (cuál) | texto | condicional | `["servicios_vivienda","contiene","otro"]` |  | max 120 | estudio_socioeconomico |
| `personas_habitan` Personas que habitan la vivienda | entero | sí |  |  | min 1 max 30 | estudio_socioeconomico |

### `economica` — Situación económica

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `egreso_alimentacion` Gasto mensual en alimentación | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_renta` Gasto mensual en renta | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_agua` Gasto mensual en agua | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_luz` Gasto mensual en luz | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_telefono` Gasto mensual en teléfono | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_servicios_medicos` Gasto mensual en servicios médicos | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_vestido` Gasto mensual en vestido | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_diversion` Gasto mensual en diversión | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_predial` Gasto mensual en predial | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_hipoteca` Gasto mensual en hipoteca | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_transporte` Gasto mensual en transporte | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_gasolina` Gasto mensual en gasolina y mantenimiento del vehículo | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_gas` Gasto mensual en gas | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_otros` Otros gastos mensuales | moneda | sí |  |  |  | estudio_socioeconomico |
| `egreso_otros_concepto` Concepto de otros gastos | texto | condicional | `["egreso_otros","positivo"]` |  | max 60 | estudio_socioeconomico |
| `infonavit` ¿Tienes crédito INFONAVIT? | booleano | sí |  |  |  | solicitud_empleo |
| `hoja_retencion` ¿Anexas hoja de retención INFONAVIT? | booleano | condicional | `["infonavit","igual",true]` |  |  | solicitud_empleo |
| `tiene_inmueble` ¿Tienes bienes inmuebles (casa, terreno)? | booleano | sí |  |  |  | estudio_socioeconomico |
| `inmueble_ubicacion` Ubicación del inmueble | texto | condicional | `["tiene_inmueble","igual",true]` |  | max 191 | estudio_socioeconomico |
| `inmueble_metros` Metros cuadrados del inmueble | entero | condicional | `["tiene_inmueble","igual",true]` |  | min 1 max 1000000 | estudio_socioeconomico |
| `inmueble_valor` Valor aproximado del inmueble | moneda | condicional | `["tiene_inmueble","igual",true]` |  |  | estudio_socioeconomico |
| `inmueble_tipo_propiedad` Tipo de propiedad del inmueble | select | condicional | `["tiene_inmueble","igual",true]` |  | `privada`, `ejidal`, `comunal` | estudio_socioeconomico |
| `inmueble_zona` Zona del inmueble | select | condicional | `["tiene_inmueble","igual",true]` |  | `urbana`, `semiurbana`, `rural` | estudio_socioeconomico |
| `inmueble_adquisicion` Forma de adquisición del inmueble | select | condicional | `["tiene_inmueble","igual",true]` |  | `compraventa`, `herencia`, `legado`, `otro` | estudio_socioeconomico |
| `inmueble_adquisicion_otro` Otra forma de adquisición (cuál) | texto | condicional | `["inmueble_adquisicion","igual","otro"]` |  | max 80 | estudio_socioeconomico |
| `tiene_vehiculo` ¿Tienes vehículo o moto? | booleano | sí |  |  |  | estudio_socioeconomico |
| `vehiculo_tipo` Tipo de vehículo | select | condicional | `["tiene_vehiculo","igual",true]` |  | `automovil`, `moto`, `otro` | estudio_socioeconomico |
| `vehiculo_otro` Otro vehículo (cuál) | texto | condicional | `["vehiculo_tipo","igual","otro"]` |  | max 80 | estudio_socioeconomico |
| `vehiculo_modelo` Modelo del vehículo | texto | condicional | `["tiene_vehiculo","igual",true]` |  | max 80 | estudio_socioeconomico |
| `vehiculo_tipo_detalle` Tipo (versión) del vehículo | texto | condicional | `["tiene_vehiculo","igual",true]` |  | max 80 | estudio_socioeconomico |
| `vehiculo_marca` Marca del vehículo | texto | condicional | `["tiene_vehiculo","igual",true]` |  | max 80 | estudio_socioeconomico |
| `vehiculo_valor` Valor aproximado del vehículo | moneda | condicional | `["tiene_vehiculo","igual",true]` |  |  | estudio_socioeconomico |
| `bienes_muebles` Otros bienes muebles | multiselect | sí |  |  | `dvd`, `radio`, `lavadora`, `computadora`, `television`, `alhajas`, `estufa`, `sala`, `comedor`, `refrigerador`, `microondas`, `otro` | estudio_socioeconomico |
| `bienes_muebles_otro` Otro bien mueble (cuál) | texto | condicional | `["bienes_muebles","contiene","otro"]` |  | max 80 | estudio_socioeconomico |
| `servicio_medico` Servicio médico con el que cuentas | select | sí |  |  | `issste`, `imss`, `seguro_popular`, `particular`, `ninguno` | estudio_socioeconomico |

### `escolaridad` — Escolaridad y competencias

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `escolaridad` Último grado de estudios | select | sí |  |  | `sin_estudios`, `primaria`, `secundaria`, `bachillerato`, `carrera_tecnica`, `licenciatura_trunca`, `licenciatura`, `posgrado` | solicitud_empleo |
| `area_cursos` Área y cursos relevantes (ventas, atención a clientes, cobranza…) | texto | sí |  | sí | max 200 | solicitud_empleo |
| `licencia_moto` ¿Cuentas con licencia y manejo de motocicleta? | booleano | sí |  |  |  | solicitud_empleo |
| `anios_manejo_moto` Años de experiencia manejando moto | entero | condicional | `["licencia_moto","igual",true]` |  | min 0 max 60 | solicitud_empleo |
| `exp_ventas` ¿Tienes experiencia en ventas? | booleano | sí |  |  |  | solicitud_empleo |
| `exp_ventas_anios` Años de experiencia en ventas | entero | condicional | `["exp_ventas","igual",true]` |  | min 0 max 60 | solicitud_empleo |
| `exp_cobranza` ¿Tienes experiencia en cobranza? | booleano | sí |  |  |  | solicitud_empleo |
| `exp_cobranza_anios` Años de experiencia en cobranza | entero | condicional | `["exp_cobranza","igual",true]` |  | min 0 max 60 | solicitud_empleo |
| `exp_creditos` ¿Tienes experiencia en créditos o financieras? | booleano | sí |  |  |  | solicitud_empleo |
| `exp_creditos_donde` ¿Dónde (créditos o financiera)? | texto | condicional | `["exp_creditos","igual",true]` |  | max 120 | solicitud_empleo |
| `prospeccion_campo` ¿Has hecho prospección en campo? | booleano | sí |  |  |  | solicitud_empleo |
| `apps_moviles` ¿Manejas apps móviles? | booleano | sí |  |  |  | solicitud_empleo |
| `comisiones_objetivos` ¿Has trabajado con comisiones y objetivos? | booleano | sí |  |  |  | solicitud_empleo |
| `estilos_trabajo` Tu estilo de trabajo | multiselect | sí |  |  | `orientado_metas`, `enfocado_cliente`, `trabajo_campo`, `trabajo_presion` min 1 | solicitud_empleo |

### `experiencia` — Experiencia laboral

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|

Lista `empleos` (0–3 renglones; bandera `sin_experiencia_laboral`):

| campo | tipo | requerido | opciones / límites |
|---|---|---|---|
| `empresa` Empresa | texto | sí | max 150 |
| `puesto` Puesto | texto | sí | max 120 |
| `desde` Desde (mes y año) | mes | sí |  |
| `hasta` Hasta (mes y año; vacío si sigues ahí) | mes | no |  |
| `funciones` Funciones | texto_largo | sí | max 300 |
| `motivo_salida` Motivo de salida | texto | sí | max 191 |
| `telefono` Teléfono de la empresa | texto | sí | max 20 |

### `referencias` — Referencias personales

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|

Lista `referencias` (2–4 renglones):

| campo | tipo | requerido | opciones / límites |
|---|---|---|---|
| `nombre` Nombre completo | texto | sí | max 150 |
| `relacion` Relación (amigo, vecino, familiar…) | texto | sí | max 60 |
| `telefono` Teléfono | texto | sí | max 20 |
| `anios_conocerlo` Años de conocerte | entero | no | min 0 max 99 |

### `perfil` — Perfil humano y vacante

| campo | tipo | requerido | condición | no_aplica | opciones / límites | documento |
|---|---|---|---|---|---|---|
| `fuente_vacante` ¿Cómo supiste de la vacante? | select | sí |  |  | `redes_sociales`, `recomendacion`, `sucursal`, `bolsa_trabajo`, `otro` | solicitud_empleo |
| `fuente_vacante_otro` ¿Por qué otro medio supiste de la vacante? | texto | condicional | `["fuente_vacante","igual","otro"]` |  | max 80 | solicitud_empleo |
| `aplico_antes` ¿Habías aplicado con nosotros antes? | booleano | sí |  |  |  | solicitud_empleo |
| `familiares_empresa` ¿Tienes familiares en la empresa? | booleano | sí |  |  |  | solicitud_empleo |
| `familiares_empresa_nombre` Nombre del familiar en la empresa | texto | condicional | `["familiares_empresa","igual",true]` |  | max 150 | solicitud_empleo |
| `tres_palabras` Descríbete en 3 palabras | texto | sí |  |  | max 80 | solicitud_empleo |
| `fortalezas` Principales fortalezas personales y laborales | texto_largo | sí |  |  | max 300 | solicitud_empleo |
| `logro_profesional` Un logro profesional del que estés orgulloso | texto_largo | sí |  |  | max 300 | solicitud_empleo |
| `situacion_dificil` Una situación difícil con un cliente y cómo la resolviste | texto_largo | sí |  |  | max 300 | solicitud_empleo |
| `areas_oportunidad` Áreas de oportunidad | texto_largo | sí |  |  | max 200 | solicitud_empleo |
| `como_te_describen` ¿Cómo te describirían tus jefes anteriores? | texto_largo | sí |  |  | max 200 | solicitud_empleo |
