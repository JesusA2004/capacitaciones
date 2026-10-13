# Auditoría final de formatos (cierre Fase 1, 2026-10-13)

Todos los documentos maestros **activos** (28) auditados con el motor real de
este servidor: Microsoft Word por COM para los Word y el overlay vectorial
para los PDF; rasterizado nativo de Windows para medir el marcatexto verde.
Cada vista previa se generó para una colaboradora de prueba con todos sus
datos (Adriana Cortés Beltrán, beneficiaria MARÍA DEL CARMEN BELTRÁN
HERNÁNDEZ, MADRE), creada y borrada por el script de auditoría.

- **Zonas**: espacios que el documento realmente tiene (líneas `____`,
  líneas de espacios/tabuladores subrayados, celdas vacías, casillas, fechas
  o datos de ejemplo), clasificadas por `CoberturaZonasService`.
- **Al generar**: datos del acto que se capturan al generar (testigos, hora
  y lugar del acta).
- **En blanco a propósito**: blancos con razón documentada (ver abajo).
- **Verde orig → gen**: pixeles de marcatexto verde en el PDF del original y
  en el generado.
- **Páginas**: los indeterminados con el diseño de página «Contrato
  indeterminado MR. LANA» (fondo + márgenes) ocupan una hoja más que el Word
  original sin fondo; la referencia es el original **con ese diseño**
  (`layout_qa`), y el generado coincide.

| Formato | v | Zonas | Con dato | Al generar | Firmas | En blanco a propósito | Sin clasificar | Resaltados retirados | Páginas orig → gen | Verde orig → gen (px) | Beneficiario | QA | Estado |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| acta_negativa_firma.general | 1 | 25 | 12 | 11 | 2 | 0 | 0 | 0 | 3 → 3 | 0 → 0 | n/a | aprobado | OK |
| aviso_terminacion.general | 1 | 7 | 7 | 0 | 0 | 0 | 0 | 0 | 2 → 2 | 0 → 0 | n/a | aprobado | OK |
| carta_renuncia.general | 1 | 10 | 9 | 0 | 1 | 0 | 0 | 0 | 1 → 1 | 0 → 0 | n/a | aprobado | OK |
| contrato_capacitacion.administrativo | 1 | 24 | 23 | 0 | 0 | 1 | 0 | 9 | 9 → 9 | 66,352 → 0 | escrito | aprobado | OK |
| contrato_capacitacion.coordinadora | 1 | 23 | 23 | 0 | 0 | 0 | 0 | 9 | 9 → 9 | 66,641 → 0 | escrito | aprobado | OK |
| contrato_capacitacion.gerente | 2 | 24 | 23 | 0 | 1 | 0 | 0 | 8 | 9 → 9 | 57,330 → 0 | escrito | aprobado | OK |
| contrato_capacitacion.gestor | 1 | 24 | 23 | 0 | 1 | 0 | 0 | 9 | 9 → 9 | 65,826 → 0 | escrito | aprobado | OK |
| contrato_capacitacion.regional | 1 | 23 | 22 | 0 | 1 | 0 | 0 | 0 | 7 → 7 | 0 → 0 | escrito | aprobado | OK |
| contrato_capacitacion.subgerente | 1 | 24 | 23 | 0 | 1 | 0 | 0 | 10 | 9 → 9 | 65,994 → 0 | escrito | aprobado | OK |
| contrato_confidencialidad.coordinadora | 1 | 22 | 20 | 0 | 0 | 2 | 0 | 26 | 11 → 11 | 246,241 → 0 | sin cláusula | aprobado | OK |
| contrato_confidencialidad.general | 2 | 18 | 18 | 0 | 0 | 0 | 0 | 26 | 11 → 11 | 246,199 → 0 | sin cláusula | aprobado | OK |
| contrato_confidencialidad.gerente | 1 | 22 | 20 | 0 | 0 | 2 | 0 | 26 | 11 → 11 | 246,062 → 0 | sin cláusula | aprobado | OK |
| contrato_confidencialidad.gestor | 1 | 19 | 19 | 0 | 0 | 0 | 0 | 26 | 11 → 11 | 246,092 → 0 | sin cláusula | aprobado | OK |
| contrato_confidencialidad.subgerente | 1 | 22 | 20 | 0 | 0 | 2 | 0 | 26 | 11 → 11 | 246,061 → 0 | sin cláusula | aprobado | OK |
| contrato_indeterminado.administrativo_confianza | 1 | 24 | 23 | 0 | 0 | 1 | 0 | 14 | 9 → 10 (diseño con fondo: 10) | 84,746 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.administrativo_no_confianza | 1 | 24 | 23 | 0 | 0 | 1 | 0 | 16 | 9 → 10 (diseño con fondo: 10) | 114,215 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.coordinadora | 1 | 24 | 24 | 0 | 0 | 0 | 0 | 16 | 10 → 11 (diseño con fondo: 11) | 112,794 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.gerente | 1 | 24 | 24 | 0 | 0 | 0 | 0 | 14 | 10 → 10 | 84,987 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.gestor | 1 | 24 | 24 | 0 | 0 | 0 | 0 | 16 | 10 → 10 | 113,168 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.regional | 1 | 22 | 22 | 0 | 0 | 0 | 0 | 0 | 7 → 7 | 0 → 0 | escrito | aprobado | OK |
| contrato_indeterminado.subgerente | 1 | 23 | 23 | 0 | 0 | 0 | 0 | 16 | 10 → 11 (diseño con fondo: 11) | 113,823 → 0 | escrito | aprobado | OK |
| contrato_no_competencia.gerente | 1 | 17 | 17 | 0 | 0 | 0 | 0 | 0 | 5 → 5 | 0 → 0 | sin cláusula | aprobado | OK |
| contrato_no_competencia.gestor | 1 | 16 | 16 | 0 | 0 | 0 | 0 | 0 | 5 → 5 | 0 → 0 | sin cláusula | aprobado | OK |
| evaluacion_capacitacion.general | 1 | 36 | 33 | 0 | 3 | 0 | 0 | 0 | 3 → 3 | 0 → 0 | n/a | aprobado | OK |
| formato_permiso.general | 1 | 12 | 10 | 0 | 0 | 2 | 0 | 0 | 1 → 1 | 0 → 0 | n/a | aprobado | OK |
| prestamo_consentimiento_retencion.general | 1 | 9 | 9 | 0 | 0 | 0 | 0 | 0 | 1 → 1 | 0 → 0 | n/a | aprobado | OK |
| prestamo_contrato.general | 1 | 24 | 24 | 0 | 0 | 0 | 0 | 0 | 2 → 2 | 0 → 0 | n/a | aprobado | OK |
| prestamo_pagare.general | 1 | 29 | 29 | 0 | 0 | 0 | 0 | 0 | 1 → 1 | 0 → 0 | n/a | aprobado | OK |

**Totales**: 28 activos · 28 OK · 0 bloqueados · 595 zonas · 0 sin
clasificar · 267 resaltados retirados de 16 contratos (0 pixeles verdes en
todos los generados).

## Corregido en esta ronda

1. **Beneficiario y parentesco no se pedían** (contratos de capacitación e
   indeterminados de sucursal): las dos reglas de la cláusula de
   beneficiarios eran `opcional`; sin dato se restauraba la línea vacía y la
   vista previa no lo reportaba. Ahora son obligatorias (registro + migración
   `2026_10_13_120000`).
2. **Indeterminados de administrativos (confianza y no confianza)**: su
   cláusula DÉCIMA SEXTA de beneficiarios sí existe, pero sus líneas son
   **espacios subrayados** (sin `_`); el detector solo reconocía `____` y el
   registro decía «no trae cláusula de beneficiarios con blanco». Ahora el
   detector reconoce blancos subrayados (`ParrafoWord::blancosSubrayados()`)
   y las dos líneas se llenan (registro + migración `2026_10_13_130000`).
3. **Overlays PDF**: casi todos los campos de préstamo eran opcionales (solo
   6 «requeridos»): un dato faltante se imprimía como línea vacía. Ahora todo
   campo es obligatorio salvo casillas y los blancos intencionales con razón.
4. **Marcatextos verdes** de Jurídico: retirados de la copia normalizada de
   16 contratos (el original queda intacto); QA visual repetido y aprobado.
5. **Jurisdicción** de convenios contada como «firma»: ahora se clasifica
   como blanco intencional (no es firma).

## Blancos intencionales (con razón)

| Formato | Blanco | Razón |
|---|---|---|
| Convenios de confidencialidad coordinadora / gerente / subgerente | «las leyes del estado de ____ … tribunales de la ciudad de ____» | Jurídico entregó la jurisdicción en blanco; no es dato del colaborador. **Decisión pendiente de Jurídico**: si debe llevar Morelos / Cuernavaca, se asigna en el editor sin código. |
| Capacitación e indeterminados de administrativos | Domicilio «Subida al Club 114…» y «CORPORATIVO» sobre la línea del lugar de trabajo | Jurídico los dejó impresos (personal administrativo trabaja en el corporativo). |
| Formato de permiso | Hora de salida / hora de entrada | Solo aplican al permiso para salir antes / llegar tarde. |

## Datos de desarrollo que la auditoría pidió (no son defectos del formato)

- **Domicilio fiscal de la empresa** (11 contratos) y **Representante legal
  de la empresa** (acta, aviso, contrato de crédito): la empresa de la BD
  local no los tiene capturados → la vista previa los marca y el definitivo
  se bloquea hasta capturarlos en Administración → Empresas.
- Préstamo, permiso, evaluación y acta piden los datos de su proceso
  (préstamo autorizado, solicitud, evaluación, cierre): la auditoría probó
  el formato sin ese proceso; en operación vienen del registro.

## Bloqueados conocidos (dependencia externa, no bloquea la Fase 1)

- `permiso_extraordinario.maternidad` (**inactivo**): usa la fuente **Aptos**,
  no instalada en este servidor; sin ella el PDF cambiaría de tipografía.
  Formato inactivo hasta disponer de Aptos o recibir versión del documento
  con una fuente disponible. Después: `php artisan people:validar-documentos-maestros`.
  No se instala ni descarga ninguna fuente propietaria automáticamente.

## Cómo repetir

```bash
php artisan documentos:normalizar-masters   # re-prepara activos, limpia marcatextos, repite QA
```

Pruebas automáticas: `tests/Feature/DocumentosMaestros/CoberturaZonasYMarcasTest.php`
(incluye la auditoría de TODOS los originales reales: 0 zonas sin clasificar
y 0 marcatextos en los masters).
