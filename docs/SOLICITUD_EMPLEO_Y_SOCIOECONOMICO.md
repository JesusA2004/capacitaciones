# Solicitud de empleo y Estudio socioeconómico

Dos formatos internos de RH que PEOPLE **genera** con lo que ya sabe de la
persona y con lo que ella captura en su registro (app). Son **obligatorios
antes de generar el contrato de contratación**.

| | Solicitud de empleo | Estudio socioeconómico |
|---|---|---|
| Original | `docs/formatos_fuente/2. SOLICITUD DE EMPLEO PARA GESTOR 2025.pdf` (4 págs. carta) | `docs/formatos_fuente/4. ESTUDIO SOCIOECÓNOMICO MR. LANA.pdf` (3 hojas: 596×794 pt; la 3ª 613×793 pt) |
| Familia (motor `pdf_overlay`) | `solicitud_empleo.general` | `estudio_socioeconomico.general` |
| Tipo documental del expediente | `solicitud_empleo` (ya existía) | `estudio_socioeconomico` (nuevo, migración 2026_11_12_100000) |
| Proceso | `incorporacion` | `incorporacion` |

Los originales también quedan en `docs/formatos/` (sin tocar). El importador
los encuentra por SHA-256 (`php artisan people:importar-formatos-juridicos`).

## Piezas

| Qué | Dónde |
|---|---|
| Catálogo único (secciones, campos, condiciones, «No aplica», documentos) | `App\Services\Incorporacion\SolicitudEmpleo\CatalogoSolicitudEmpleo` |
| Captura, prellenado, completitud | `…\PerfilIngresoService` |
| Datos del overlay (`sol_*`, `ese_*`, `marca_*`) | `…\DatosSolicitudEmpleoService` (lo llama `DatosDocumentoService::resolver()`) |
| Regla «antes del contrato» | `…\RequisitoDocumentosIngresoService` (la consultan `DocumentoProcesoService` y `ContratoLaboralService`) |
| Bloque del registro en la app | `…\SolicitudEmpleoRegistroService` (en `RegistroAppService::estado()/finalizar()`) |
| Coordenadas (mm, medidas con `pdftotext -bbox` + detección de cajas) | `config/documentos_maestros.php` (`$solicitudEmpleoCampos`, `$estudioCampos`) |
| Web RH | Ciclo laboral del colaborador → tarjeta «Solicitud de empleo y estudio socioeconómico» + «Documentos» (vista previa / generar) |

## Modelo de datos (sin duplicar)

- Reutiliza `colaboradores` (nombre, sexo, nacimiento, teléfono, estado civil,
  correo, CURP, RFC, NSS, domicilio estructurado, clave de elector),
  `colaborador_datos_medicos.condicion_medica` («¿cuáles enfermedades
  crónicas?»), `candidatos.fuente` y `candidato_socioeconomicos.checklist`
  (arraigo) solo como **sugerencia** de prellenado.
- Nuevas columnas en `colaboradores`: `telefono_alterno`, `escolaridad`
  (`App\Enums\Escolaridad`).
- `colaborador_datos_medicos.tipo_sanguineo` (cifrado).
- `colaborador_perfiles_ingreso` (1:1): domicilio ampliado, vivienda,
  competencias, perfil humano, vacante. `situacion_economica` (egresos,
  INFONAVIT, inmueble, vehículo, bienes, servicio médico) e
  `identificacion_folio` cifrados. `no_aplica` = campos declarados «No aplica»;
  `secciones` = bitácora por sección (`guardada_en`, `origen` app/rh, `por`).
- Listas: `colaborador_dependientes` (≤6), `colaborador_empleos_anteriores`
  (≤3), `colaborador_referencias_personales` (2–4). «No tengo dependientes» /
  «Es mi primer empleo» son banderas explícitas (`sin_dependientes`,
  `sin_experiencia_laboral`).

Edad (de la persona y de sus dependientes): se deriva de la fecha de
nacimiento a la fecha del documento (calendario de Ciudad de México).

## Reglas

1. **Completitud exacta** por documento: `«Faltan 3 datos para generar la
   Solicitud de Empleo: Último grado de estudios, …»`. Condicionales: cónyuge
   solo casado/unión libre; años de moto solo con licencia; hoja de retención
   solo con INFONAVIT; inmueble/vehículo solo si tiene; «cuál» solo si eligió
   «Otro». Cuando la condición no aplica, el PDF imprime «No aplica».
2. **Nunca un PDF incompleto**: generar con datos faltantes → 422
   `DATOS_FALTANTES` con `message` y `faltantes[].etiqueta`. La **vista previa**
   siempre está disponible (marca «VISTA PREVIA — NO VÁLIDA PARA FIRMA»).
3. **Quién captura**: la persona, durante su registro (después, correcciones
   por solicitud). RH solo **completa lo vacío**: nunca recaptura ni pisa lo
   que la persona dio (y la persona no cambia lo que completó RH).
4. **Antes del contrato**: el paquete de alta (botón, API o generación
   automática) se bloquea con `«Antes del contrato se deben generar la
   Solicitud de Empleo y el Estudio Socioeconómico. Pendiente: …»` hasta que
   ambos estén generados. No retroactivo: solo personas en incorporación
   (`pendiente_documentos`, `documentacion_en_revision`, `pendiente_contrato`)
   cuyo contrato inicial aún no tiene documentos; nunca renovaciones.
   Interruptor: `INCORPORACION_DOCUMENTOS_INGRESO_ANTES_DE_CONTRATO`.
5. **Registro en la app**: `finalizar()` exige la completitud solo a la app que
   declara implementar estas pantallas (cabecera `X-People-Solicitud-Empleo: 1`)
   — `INCORPORACION_REGISTRO_EXIGE_SOLICITUD` = `cliente` (default) | `siempre`
   | `nunca`. Las versiones instaladas cierran su registro como siempre y RH
   completa en la web; el contrato igual queda bloqueado sin ambos documentos.
6. Generado: PDF al expediente (`Personales`), `GeneratedDocument` con
   snapshot, hash del master, versión, auditoría `documento_generado`; se
   imprime y firma a mano (croquis también a mano). Regenerar: reglas del
   motor (motivo, permiso; un firmado se conserva).

7. **Requisito del expediente cumplido por generación**
   (`App\Services\Expedientes\RequisitoDocumentoGeneradoService`, fuente
   única que usan DocumentosPendientesService, BloqueoDocumentosService,
   carta compromiso, ExpedienteIncompletoService, guía, RegistroAppService,
   IncorporacionService y ExpedienteService/ProgresoExpediente): un tipo
   documental con master generable (tipo_documental de un master de
   `documentos_incorporacion` con fuente activa) **se cumple con el
   GeneratedDocument vigente (no cancelado)** — basta generado; el original
   firmado lo controla Recepción documental. Un escaneo ya subido sigue
   cumpliendo. Sin ninguno de los dos **nunca es «documento por subir»**: no
   bloquea la app, no va en la carta compromiso ni cuenta para su límite. La
   app lo muestra sin botón de subir (`generado_por_sistema` en cada
   documento de `colaborador/incorporacion` y del registro;
   `documentos_generados` en `colaborador/carta-compromiso`): durante el
   registro «Se genera con tus datos de la Solicitud de empleo» (captura
   `registro`); después «Lo prepara Recursos Humanos» (captura `rh`: RH lo
   genera desde la web).

## Contrato JSON para la app (registro)

Cuenta en sesión (Sanctum). Permitidas por `registro.app` durante el registro.

### `GET /api/v1/colaborador/registro/solicitud`

```json
{
  "data": {
    "registro_abierto": true,
    "exigida": true,
    "secciones": [
      {
        "clave": "personal",
        "titulo": "Datos personales",
        "descripcion": "…",
        "completa": false,
        "porcentaje": 60,
        "faltantes": ["Tipo sanguíneo", "Número de folio de la identificación"],
        "guardada_en": "2026-11-04T10:00:00-06:00",
        "origen": "app",
        "campos": [
          {
            "campo": "tipo_sanguineo",
            "etiqueta": "Tipo sanguíneo",
            "tipo": "select",
            "opciones": [{ "value": "o_positivo", "label": "O+" }, { "value": "desconocido", "label": "No lo sé" }],
            "valor": null,
            "sugerencia": null,
            "requerido": true,
            "requerido_si": null,
            "permite_no_aplica": false,
            "no_aplica": false,
            "bloqueado": false,
            "origen": "solicitud",
            "documentos": ["estudio_socioeconomico"],
            "min": null, "max": null,
            "ayuda": "Si no lo sabes, elige «No lo sé»."
          }
        ],
        "lista": null
      },
      {
        "clave": "dependientes",
        "campos": [],
        "lista": {
          "clave": "dependientes", "etiqueta": "Dependientes económicos", "singular": "Dependiente",
          "minimo": 0, "maximo": 6,
          "bandera_vacia": "sin_dependientes", "etiqueta_vacia": "No tengo dependientes económicos",
          "vacia": false, "bloqueada": false,
          "campos": [{ "campo": "nombre", "etiqueta": "Nombre(s)", "tipo": "texto", "requerido": true, "opciones": [], "min": null, "max": 120 }],
          "filas": [{ "nombre": "Luis", "apellido_paterno": "Torres", "parentesco": "hijo", "fecha_nacimiento": "2016-05-01", "edad": null, "escolaridad_ocupacion": "Primaria" }]
        }
      }
    ],
    "completitud": {
      "completo": false, "porcentaje": 42, "total": 120, "completos": 50,
      "faltantes": [{ "seccion": "personal", "campo": "tipo_sanguineo", "etiqueta": "Tipo sanguíneo", "documentos": ["estudio_socioeconomico"], "origen": "solicitud" }],
      "secciones": [{ "clave": "personal", "titulo": "Datos personales", "completa": false, "porcentaje": 60, "faltantes": ["…"], "guardada_en": null, "origen": null }],
      "documentos": {
        "solicitud_empleo": { "clave": "solicitud_empleo", "nombre": "Solicitud de Empleo", "completo": false, "faltantes": ["…"], "mensaje": "Faltan 3 datos para generar la Solicitud de Empleo: …" },
        "estudio_socioeconomico": { "…": "…" }
      }
    }
  }
}
```

- `tipo`: `texto` | `texto_largo` | `entero` | `moneda` | `booleano` | `select`
  | `multiselect` | `fecha` (AAAA-MM-DD) | `mes` (AAAA-MM, solo en listas).
- `bloqueado`: no editable aquí. `origen: datos_personales` = se captura en el
  paso «Datos personales» del registro (`POST registro/datos`); los demás, ya
  capturados por RH.
- `sugerencia`: prellenado (fuente del candidato, arraigo de la visita, INE si
  hay clave de elector…). La app la pone como valor inicial; **solo se guarda
  cuando la persona guarda la sección**.
- `requerido_si`: para mostrar/ocultar en la app sin esperar al servidor
  (`operador`: `igual` | `en` | `distinto` | `positivo` | `contiene`).
- Campos con `origen = datos_personales` que falten aparecen en `faltantes`
  con `origen: "datos_personales"`.

Secciones (en orden): `personal`, `domicilio`, `dependientes`, `vivienda`,
`economica`, `escolaridad`, `experiencia`, `referencias`, `perfil`.

### `POST /api/v1/colaborador/registro/solicitud/{seccion}`

Guardado **parcial** de una sección (guardar y continuar). Solo las claves que
se envían cambian; nada se exige al guardar.

```json
{
  "datos": {
    "numero_hijos": 2,
    "tipo_sanguineo": "o_positivo",
    "padece_enfermedad_cronica": false
  },
  "no_aplica": ["telefono_alterno"]
}
```

- `no_aplica`: lista COMPLETA de los campos de ESA sección declarados «No
  aplica» (solo los que tienen `permite_no_aplica`; omitirla = sin cambios).
- Listas: la sección envía la lista completa (reemplaza) y su bandera:

```json
{ "datos": { "sin_dependientes": false, "dependientes": [ { "nombre": "Luis", "apellido_paterno": "Torres", "apellido_materno": null, "parentesco": "hijo", "parentesco_otro": null, "fecha_nacimiento": "2016-05-01", "edad": null, "escolaridad_ocupacion": "Primaria" } ] } }
```

```json
{ "datos": { "sin_experiencia_laboral": false, "empleos": [ { "empresa": "Financiera Sol", "puesto": "Promotora", "desde": "2021-03", "hasta": null, "funciones": "…", "motivo_salida": "…", "telefono": "7771112233" } ] } }
```

```json
{ "datos": { "referencias": [ { "nombre": "Rosa Gómez", "relacion": "Vecina", "telefono": "7772223344", "anios_conocerlo": 8 } ] } }
```

Respuesta 200: `{ "data": <mismo objeto que el GET>, "message": "Sección guardada." }`.

Errores 422 (mensajes en español):
- Formato: `errors["datos.tipo_sanguineo"] = ["Elige una opción válida en Tipo sanguíneo."]`.
- Registro cerrado: `errors.registro = ["Tu registro ya está completo. Si necesitas cambiar un dato, solicita una corrección desde la app."]`.
- Dato ya registrado por RH: `errors.datos = ["Recursos Humanos ya registró: …"]`.
- «No aplica» no permitido, lista con «No tengo…» y renglones a la vez, más
  renglones que los del formato.

### Registro (`GET /api/v1/colaborador/registro`)

Agrega `data.solicitud_empleo`:

```json
{ "exigida": true, "completo": false, "porcentaje": 42,
  "secciones": [{ "clave": "personal", "titulo": "Datos personales", "completa": false, "porcentaje": 60, "faltantes": ["…"] }],
  "documentos": [{ "clave": "solicitud_empleo", "nombre": "Solicitud de Empleo", "completo": false, "mensaje": "Faltan …" }],
  "pendientes": ["Datos personales: Tipo sanguíneo, …"] }
```

Con `exigida = true` (cabecera `X-People-Solicitud-Empleo: 1`),
`puede_finalizar` incluye la completitud y `POST registro/finalizar` responde
422 `errors.solicitud_empleo = ["Completa tu solicitud de empleo y estudio
socioeconómico. Falta: Datos personales: …; Vivienda: …."]`.

## Endpoints de RH (web JSON = API v1, mismo controlador)

| Método | Ruta (`/rh/…` web, `/api/v1/rh/…` app) |
|---|---|
| GET | `colaboradores/{colaborador}/solicitud-empleo` |
| POST | `colaboradores/{colaborador}/solicitud-empleo/{seccion}` (mismo cuerpo que la app; solo completa lo vacío) |
| GET | `documentos-proceso/colaborador/{id}` → sección `proceso = incorporacion` |
| GET | `documentos-proceso/colaborador/{id}/vista-previa?clave=solicitud_empleo&proceso=incorporacion` |
| POST | `documentos-proceso/colaborador/{id}/generar` `{clave, proceso: "incorporacion", regenerar?, motivo?}` |

Permiso: `documentos_laborales.generar` + alcance organizacional sobre la persona.

## Pendientes para confirmar con RH

- «No. de folio» de la identificación: hoy se captura aparte (no se toma la
  clave de elector). ¿Es el folio/CIC de la INE o la clave de elector?
- ¿Se imprime la enfermedad crónica («Sí: …») o solo Sí/No?
- La casilla «Adobe» de servicios (Estudio, hoja 3) se usa como **Gas**;
  «Drenaje» no trae recuadro (la X se dibuja junto a la etiqueta).
- La solicitud entregada es la «para Gestor 2025»: se usa para todos los
  puestos hasta recibir variantes.
- Mínimo de referencias personales: 2 (el formato trae 4 renglones).
- Croquis: se dibuja a mano (Solicitud: 6 recuadros; Estudio: cuadrícula).
