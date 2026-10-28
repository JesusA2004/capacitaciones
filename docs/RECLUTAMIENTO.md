# Reclutamiento (prospectos y candidatos)

Módulo `/rh/reclutamiento` (resumen), `/rh/candidatos` (tablero) y
`/rh/candidatos/{candidato}` (ficha). Tablas `candidatos` y `seguimientos_candidato` —
ver migraciones `2026_09_01_100001_create_candidatos_table` y
`2026_09_01_100002_create_seguimientos_candidato_table`.

## Nombres de tabla: nota de traducción

La sección de reclutamiento del encargo original nombra la tabla de seguimiento como
`candidate_followups`. El resto del proyecto usa nombres de tabla en español de forma
consistente (`docs/ARQUITECTURA.md`), así que se implementó como
**`seguimientos_candidato`**, con las mismas columnas solicitadas
(`candidato_id, tipo, nota, estado_anterior, estado_nuevo, fecha, registrado_por`).

## Candidato

Campos: `empresa_id`, `sucursal_id`, `departamento_id`, `puesto_objetivo_id`,
`vacante_id` (todos nullable), `nombre`, `apellidos`, `telefono`, `correo`, `fuente`
(texto libre, no catálogo cerrado — las fuentes de reclutamiento varían demasiado entre
empresas para forzar un enum), `cv_disk`/`cv_path`/`cv_original_name`/`cv_mime`/`cv_size`
(metadatos del CV; el archivo vive en el disco `nas`, nunca en la base de datos —
`App\Services\Reclutamiento\CvStorageService`, espejo de
`DocumentoStorageService`/`MediaStorageService`), `observaciones`,
`documentos_solicitados` (nota libre de qué se pidió, previo a expediente formal),
`responsable_rh_id`, `gerente_involucrado_id`, `estado`, `fecha_entrevista`,
`resultado_entrevista`, `creado_por`. Soft deletes.

El modelo `Candidato` oculta `cv_disk`/`cv_path` en la serialización (`$hidden`) y
expone en su lugar `tiene_cv` (boolean) y `cv_original_name`; la descarga real pasa por
`GET rh/candidatos/{candidato}/cv/descargar`, protegida por policy, igual que los
documentos de expediente — nunca se expone la ruta física del NAS al frontend.

## Alta del candidato: origen, requisitos y motivo de salida

- **Origen obligatorio** (`StoreCandidatoRequest`): «¿De qué campaña viene?»
  (`campana_reclutamiento_id`, campañas de su vacante + campañas generales sin vacante
  vigentes o terminadas hace ≤ 60 días) **o**, si no llegó por una campaña, el medio
  (`fuente`). Con campaña, la fuente se fuerza al canal de la campaña
  (`CanalReclutamiento::fuenteCandidato()`), así nunca se contradicen.
- **Conteo automático por campaña**: `CostoReclutamientoService::porCampana()` cuenta los
  candidatos ligados; el campo manual `candidatos_generados` ya no se captura ni se usa
  en el embudo.
- **Requisitos indispensables** (`preguntas_filtro_candidato`, administrables en
  Configuración → Parámetros de RH): preguntas Sí/No por puesto (p. ej. «¿Sabe manejar
  moto?» para los puestos del grupo Gestores). Se contestan al registrar
  (`candidato_respuestas_filtro`, con copia del texto). Si una eliminatoria no se
  cumple, el candidato **se registra igual** (cuenta para su campaña) y se cierra al
  momento como «No viable» con el motivo de la pregunta (por defecto
  `no_cumple_requisito`). Ver `PreguntasFiltroCandidatoService`.
- **Motivos clasificados**: `motivos_rechazo_candidato.categoria`
  (`CategoriaMotivoRechazo`: requisito / empresa / candidato / sin contacto). Una
  salida sin motivo elegido se clasifica sola según el paso en que se quedó
  (`CandidatoWorkflowService::claveMotivoAutomatico()`); las salidas anteriores a esta
  regla aparecen como «Sin clasificar» en el reporte (no se reescribe el histórico).

## Bandeja de CVs (descargas de WhatsApp Web → NAS)

Quien registra desde WhatsApp Web (una persona o Claude en Chrome) puede **descargar**
el CV, pero el navegador no deja que una página o un agente elijan un archivo del disco
en «Seleccionar archivo». Por eso el CV no se sube: se toma de una carpeta del NAS.

1. **NAS**: carpeta `RECLUTAMIENTO_BANDEJA_CARPETA` (por defecto
   `reclutamiento/bandeja-cv`, relativa a la raíz del disco `nas` que usa la app).
2. **Computadora de reclutamiento**: Synology Drive Client sincroniza esa carpeta del
   NAS con una carpeta local, p. ej. `C:\Users\<usuario>\SynologyDrive\bandeja-cv`.
3. **Chrome sin preguntar dónde guardar** (una sola vez, sin administrador):
   `scripts/reclutamiento/configurar-chrome-bandeja-cv.ps1 -Carpeta "<carpeta local>"`.
   Pone las políticas `PromptForDownloadLocation=0`, `DownloadDirectory=<carpeta>` y
   permite descargas múltiples en `https://web.whatsapp.com`. Reiniciar Chrome y revisar
   `chrome://policy`. Se deshace con `-Quitar`.
4. **En el sistema**: en «Nuevo candidato» y en el expediente del candidato aparece el
   selector de la bandeja (más recientes primero, con vista previa). Al guardar, el
   archivo se **mueve** a `candidatos/{Nombre Apellidos} - {id}/cv/` (sale de la bandeja; un candado evita
   asignarlo dos veces). Descargas a medias (`.crdownload`) y tipos no permitidos no
   se listan; nunca se aceptan rutas, solo nombres de la propia carpeta
   (`BandejaCvService`). El CV puede ser PDF, Word o foto (JPG/PNG/WEBP).

Instrucción sugerida para Claude en Chrome: «Descarga el CV del chat, luego en Nuevo
candidato (o en su expediente) pulsa actualizar en la bandeja de CVs y elige el archivo
con ese nombre; uno por uno, descarga y asigna antes de pasar al siguiente candidato».

## Reporte PDF de campañas (Chrome / Browsershot)

`GET rh/campanas/reporte-pdf?mes&anio&…filtros` (todas las campañas del periodo, con
comparativo) y `GET rh/campanas/{campana}/reporte-pdf` (una). Lo arma
`ReporteCampanasService` con la vista `pdf/reporte-campanas`: cuántos llegaron, embudo
por hito (`etapa_maxima`), en qué fase se detuvieron, por qué (motivo agrupado por
categoría), cuántos siguen en proceso, costo por candidato, costo por contratado (misma
cifra que la pantalla) y cuánto se gastó en los que no continuaron. La lista nominal de
candidatos solo se incluye si el usuario puede ver candidatos.

## Mini expediente del candidato (antes de la contratación)

`App\Services\Reclutamiento\ExpedienteCandidatoService` reúne lo que el candidato
presentó en el proceso para que RH, Dirección Comercial y administrativos le den
seguimiento (sección «Expediente del candidato» en la ficha). Todo vive en el NAS
(Synology), una carpeta por candidato con su nombre, aparte de `expedientes/`
(el expediente normal del colaborador se arma hasta que se contrata). El id
resuelve homónimos; la carpeta se fija con el primer archivo (`candidatos.carpeta_nas`)
y no cambia si después se corrige el nombre. Las carpetas antiguas por número
(`candidatos/{id}/`) se pasan con `php artisan candidatos:carpetas-con-nombre`
(`--simular` para ver antes qué movería).

| Carpeta | Contenido | Cuándo se sube |
|---|---|---|
| `candidatos/{Nombre Apellidos} - {id}/cv/` | CV (PDF/Word, 10 MB) | Al registrar al candidato o después |
| `candidatos/{Nombre Apellidos} - {id}/psicometricos/` | Resultados psicométricos (PDF, imagen, Word, Excel) | Con «Registrar resultados» o después |
| `candidatos/{Nombre Apellidos} - {id}/socioeconomico/videos/` | Uno o varios videos (MP4, MOV, M4V, 3GP, WEBM; 250 MB c/u) | Con el estudio socioeconómico o después |
| `candidatos/{Nombre Apellidos} - {id}/socioeconomico/fotos/` y `…/documentos/` | Fotografías y PDF del estudio | Igual |
| `candidatos/{Nombre Apellidos} - {id}/referencias/` | Soporte de la referencia (constancia, carta, captura) | Al registrar la referencia o después |

- Agregar archivos después: `POST rh/candidatos/{candidato}/evidencias` (web) y
  `POST api/v1/rh/candidatos/{candidato}/evidencias` (app), con `etapa`
  (`socioeconomico`, `psicometricas`, `referencias`) y `archivos[]`. No cambia la
  fase del candidato; deja nota en su seguimiento y en la auditoría.
- Quién sube: `CandidatoPolicy::adjuntar` (Reclutamiento o el gerente que evalúa,
  dentro de su alcance). Quién consulta: `CandidatoPolicy::view` (`candidatos.ver_todos`
  para RH, Dirección Comercial y administrativos; gerentes solo su alcance).
- Límites en `config/reclutamiento.php` (`evidencias.*`, variables
  `RECLUTAMIENTO_VIDEO_MAX_MB` y `RECLUTAMIENTO_EVIDENCIA_MAX_MB`). El servidor debe
  aceptar el tamaño del video (php.ini y Nginx, ver `docs/DEPLOY.md`).
- Archivos subidos antes de este cambio siguen en `candidatos/{Nombre Apellidos} - {id}/evidencias/` y se
  muestran igual (la ruta se guarda en BD); no se mueven automáticamente.

## Estados

> **Vigente (2026-10):** el pipeline real está en `App\Enums\EstadoCandidato` y en
> `App\Services\Reclutamiento\CandidatoWorkflowService`: Filtro RH → Entrevista →
> Psicométricas → Socioeconómico → Referencias → Preautorización del gerente →
> Autorización final de RH → En contratación (QR) → Contratado, con salidas
> No viable / No seleccionado / Rechazado por RH / No respondió / Desistió. El tablero
> agrupa esos estados en las columnas de `EstadoCandidato::fases()` (ver abajo). Los
> avances se hacen con acciones del workflow (nunca arrastrando). Guía de uso:
> `docs/guias/FLUJO_COMPLETO.md`. La lista histórica más abajo describe la
> primera versión del módulo y se conserva como historial.

### Columnas del tablero (vigente desde 2026-10-28)

`filtro_rh → entrevista → psicometricos → socioeconomico → referencias → contratacion
→ induccion → evaluacion_induccion → capacitacion_inicial →
evaluacion_capacitacion_inicial → contrato_indeterminado`, más `rechazado` y `desistido`.

Hasta **Contratación** la columna sale del estado del workflow
(`EstadoCandidato::faseCanonica()`). Un candidato `contratado` avanza después por
**hechos reales de su colaborador**, calculados en lote por
`App\Services\Reclutamiento\FaseContratadoService` (nunca por una casilla manual ni
por `colaboradores.activado_en`):

| Columna | Criterio exacto |
|---|---|
| Inducción | Contratado y RH todavía no le presenta la inducción. |
| Evaluación de inducción | Asistió a una sesión de inducción que RH marcó **presentada** (`sesion_induccion_colaboradores` + `sesiones_induccion.estado = presentada`) o ya tiene su evaluación de inducción abierta, y esa evaluación (FO-RH-EI, `evaluaciones_induccion`) **no** está aprobada (incluye «requiere reforzamiento»). Una sesión en curso no cuenta. |
| Capacitación inicial | Su evaluación de inducción está **aprobada**. |
| Evaluación de capacitación inicial | Ya tiene asignada su evaluación de capacitación inicial (FO-RH-EPP-001, `evaluaciones_periodo_prueba`), en cualquier estado. |
| Contrato indeterminado | Su contrato indeterminado **ya se generó** (`generated_documents.clave_plantilla = contrato_indeterminado`, `estado_flujo` distinto de `cancelado`). |

Siempre gana el hito **más avanzado** que exista (un expediente histórico con contrato
indeterminado generado está en esa columna aunque no tenga registros de inducción).
Activar al colaborador (`activado_en`) **no** mueve de columna: alguien activado sin
contrato indeterminado generado sigue en la columna que marquen sus hechos. El chip de
la tarjeta/ficha (`CadenaContratacionService`), el paso a paso de la ficha
(`CicloLaboralService`) y `etiquetaEstado()` usan la misma columna. En los reportes de
campañas todas estas columnas se filtran juntas como «Contratados».

Primera versión (histórica): `nuevo, contactado, respondio, no_respondio, viable, no_viable,
entrevista_programada, entrevistado, documentacion_solicitada, en_revision,
aprobado_gerencia, aprobado_rh, rechazado, descartado, contratado`.

Cada cambio de estado (`PUT rh/candidatos/{candidato}/estado`) crea automáticamente un
`SeguimientoCandidato` de tipo `cambio_estado` con el estado anterior/nuevo, para que el
timeline de la ficha del candidato sea siempre la fuente completa de auditoría del
proceso — no hace falta consultar `activity_log` para reconstruir el historial.

### Transiciones de alto impacto

`CandidatoPolicy::cambiarEstado()` distingue tres niveles de permiso según el estado
destino:

- Transiciones rutinarias (contactado, respondió, viable, entrevista programada...):
  requieren `candidatos.editar`.
- `aprobado_gerencia`, `aprobado_rh`, `contratado`: requieren `candidatos.aprobar`.
- `rechazado`, `descartado`: requieren `candidatos.rechazar`.

Esto permite que `rh_auxiliar` mueva el proceso día a día sin poder aprobar ni
rechazar (coherente con "opera pero no aprueba" de `docs/ROLES_PERMISOS_RH.md`),
mientras que `rh_admin` y `gerente_sucursal` sí tienen ambos permisos — la gerencia
aprueba/rechaza candidatos de su propia sucursal, RH aprueba/rechaza de forma global.

## Timeline de seguimiento

`Candidato::seguimientos()` — historial ordenado por fecha descendente. Tipos
(`App\Enums\TipoSeguimientoCandidato`): `llamada, correo, entrevista, cambio_estado,
nota, documento, otro`. Se crea uno automático al registrar el candidato
("Candidato registrado") y uno por cada cambio de estado; el resto los agrega RH
manualmente desde la ficha del candidato.

## Alcance y permisos

Permisos: `candidatos.ver`, `candidatos.ver_todos`, `candidatos.ver_sucursal`,
`candidatos.crear`, `candidatos.editar`, `candidatos.aprobar`, `candidatos.rechazar`,
`candidatos.eliminar`. Mismo criterio de alcance por sucursal que Vacantes
(`AlcanceOrganizacionalService::limitarPorSucursal()`).

- RH ve todos los candidatos.
- Gerente ve y opina/aprueba/rechaza solo candidatos de su sucursal.
- Director comercial (rol a definir en `docs/ROLES_PERMISOS.md`, Fase 1 ampliada) tiene
  vista global vía alcance global.
- Auditor consulta sin poder crear/editar/aprobar/rechazar.
- El candidato en sí **no tiene cuenta de usuario** en esta fase: su interacción es
  únicamente a través de la liga segura de alta digital una vez aprobado (ver
  `docs/ALTA_DIGITAL_COLABORADOR.md`), no a través de este módulo de reclutamiento.

## Conversión a alta digital

Cuando un candidato llega a `aprobado_rh`, RH genera desde su ficha una alta digital
(módulo `docs/ALTA_DIGITAL_COLABORADOR.md`), que es lo que finalmente crea al
colaborador (`User`) y su expediente. El candidato en estado `contratado` queda como
registro histórico del proceso de reclutamiento, enlazado al colaborador resultante.
