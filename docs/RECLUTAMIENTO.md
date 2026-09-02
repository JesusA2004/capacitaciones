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

## Estados

`nuevo, contactado, respondio, no_respondio, viable, no_viable,
entrevista_programada, entrevistado, documentacion_solicitada, en_revision,
aprobado_gerencia, aprobado_rh, rechazado, descartado, contratado`
(enum `App\Enums\EstadoCandidato`).

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
