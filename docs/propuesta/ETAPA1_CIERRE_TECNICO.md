# MR. LANA PEOPLE — Etapa 1 · Cierre técnico

> Estado comprobado al 2026-10-30. **No está aprobado para producción:** ver [Bloqueantes](#bloqueantes).
> La aprobación final corresponde al responsable del proyecto y a RH.
> Estados usados: Pendiente, En desarrollo, Implementado sin validar, Probado y aprobado, Bloqueado por información externa.
> «Probado y aprobado» significa aquí prueba automatizada ejecutada y leída, con datos de prueba. La validación con datos reales es del piloto.

**SHA backend:** ver el último commit de `main` que incluye este documento. **SHA móvil:** `c411241`. La app requiere el backend `8cf8317` o posterior.

## Bloqueantes

1. **CI del backend sin confirmar en verde.** El paso `Tests` falló en todas las corridas terminadas hasta `37eda1b` y sus logs no son legibles con el token actual. Desde `95ba07c` se guarda el JUnit como artefacto.
2. **Suite completa de Pest sobre el SHA final sin confirmar.** La última corrida completa leída fue en `37eda1b`: 2,315 aprobadas, 2 fallidas y 3 omitidas de 2,320. Una falla era real (mapa de purga, corregida en `dc395a2`) y la otra del entorno de la copia.
3. **Regla de días pagados sin aprobar por RH** (`config/nomina_incidencias.php`, `validada_por_rh = false`). Hay aviso en pantalla, pero un recibo real no debe depender de ella hasta decidirla.
4. **Plazas autorizadas de Corporativo no entregadas.** Sin el catálogo no se pueden conciliar las cifras 151 / 134 / 17.
5. **Motor de tablas de producción sin comprobar.** Con MyISAM no hay transacciones ni llaves foráneas.
6. **Sin validación real de:** NAS Synology, push a dispositivo, Android físico, Chrome de Browsershot en el VPS y datos reales.

## Requisitos por página del PDF

| Pág. | Requisito | Estado | Pantalla o ruta | Pendiente |
|---|---|---|---|---|
| 2-4 | Plantilla única y Tablero | Implementado sin validar | `dashboard`, `HeadcountService::poblacion()` | Cifras reales; barras con datos solo en Pest |
| 5 | Directorio | Probado y aprobado | `/directorio` | Criterio de «Datos por completar» |
| 6 | Bandas en Solicitudes | Probado y aprobado | Permanencia → Solicitudes | — |
| 7-9 | Incapacidades | Implementado sin validar | `/rh/incapacidades` | NAS real; regla de días pagados |
| 10, 15 | Bajas y documentos de salida | Implementado sin validar | `/rh/bajas` | Textos para validación de Jurídico; días inhábiles |
| 11, 16 | Finiquitos | Probado y aprobado | `/rh/bajas/finiquitos` | Liquidaciones históricas (ver hallazgos) |
| 12-13, 17 | Rotación | Implementado sin validar | `/rh/bajas/rotacion` | Excel real y corte `mes_inicio_sistema` |
| 14, 16 | Catálogo de causales | Probado y aprobado | `/rh/bajas/causales` | Fracción legal de RE-02 y RE-05 |
| 18-19 | Administración de salarios | Implementado sin validar | `/organizacion/salarios` | Evaluación de desempeño inexistente |
| 20, 22 | Reportes | Implementado sin validar | `/rh/reportes` | Excel real de altas históricas |
| 20, 22 | Exportador NOI | Bloqueado por información externa | Reportes → Altas para NOI | Layout y versión de Aspel |
| 21 | Mis reportes | Probado y aprobado | `/rh/reportes/mis-reportes` | Chrome en el VPS; Excel en Microsoft Excel |
| 23, 25 | Agenda web | Implementado sin validar | `/reclutamiento/agenda` | Push real; registrar resultado en navegador |
| 24 | Agenda en la app | Implementado sin validar | `src/app/(app)/agenda/*` | Dispositivo real y backend vivo |
| 26 | Onboarding | Fuera de alcance | — | Etapa 2 |

## Matriz de aceptación (encargo §11.2)

Rutas de prueba relativas a `tests/Feature/`.

| # | Escenario | Estado | Evidencia |
|---|---|---|---|
| 1 | Plazas del catálogo real | Bloqueado por información externa | Mecanismo probado en `Headcount/PlazasAutorizadasImportTest`; falta el catálogo |
| 2 | Corporativo y sucursales concilian | Bloqueado por información externa | Lógica en `Headcount/PoblacionFuenteUnicaTest`; cifra real sin catálogo |
| 3 | Tablero, Directorio y Reportes coinciden | Probado y aprobado | `PoblacionFuenteUnicaTest` |
| 4 | Sin teléfono sigue en Directorio | Probado y aprobado | `Directorio/DirectorioEtapa1Test` |
| 5 | Gestores no desaparecen | Probado y aprobado | `Directorio/DirectorioTest` |
| 6 | Solicitudes no crea incapacidad | Probado y aprobado | `Incapacidades/IncapacidadesTest` (6, 7) |
| 7 | Histórico consultable | Probado y aprobado | `IncapacidadesTest` (6, 7) |
| 8 | Enfermedad general con certificado | Probado y aprobado | `IncapacidadesTest` (8) |
| 9 | Subsecuente ligada | Probado y aprobado | `IncapacidadesTest` (9) |
| 10 | Folio duplicado rechazado | Probado y aprobado | `IncapacidadesTest` (10) |
| 11 | 21/10 + 14 = 03/11 | Probado y aprobado | `IncapacidadesTest` (11) y navegador |
| 12 | ST-2 concluye el riesgo | Probado y aprobado | `IncapacidadesTest` (12) |
| 13 | Cruza dos quincenas | Probado y aprobado | `IncapacidadesTest` (13, 14) |
| 14 | 11 días naturales | Probado y aprobado | `IncapacidadesTest` (13, 14) |
| 15 | No duplica descuentos | Probado y aprobado | `IncapacidadesTest` (15) |
| 16 | Sin importes negativos | Probado y aprobado | `IncapacidadesTest` (16) |
| 17 | Cierre sin Jurídico | Probado y aprobado | `Bajas/DocumentosSalidaYFiniquitosTest` |
| 18 | Documentos obligatorios | Probado y aprobado | `DocumentosSalidaYFiniquitosTest` |
| 19 | RV genera carta | Probado y aprobado | `Bajas/BajaDirectaDocumentosTest` con maestro real |
| 20 | NR genera convenio | Probado y aprobado | `DocumentosSalidaYFiniquitosTest`; texto pendiente de Jurídico |
| 21 | RE con hechos | Probado y aprobado | `DocumentosSalidaYFiniquitosTest` y navegador |
| 22 | Negativa muestra plazo | Probado y aprobado | `DocumentosSalidaYFiniquitosTest`; días inhábiles del Tribunal pendientes |
| 23 | Firmado asociado a NAS | Implementado sin validar | Pest con disco falso; falta Synology |
| 24 | Finiquitos independiente | Probado y aprobado | `DocumentosSalidaYFiniquitosTest` y navegador |
| 25 | Mismo finiquito | Probado y aprobado | `DocumentosSalidaYFiniquitosTest`, `CorreccionYCatalogoBajasTest` |
| 26 | NR-01 propone liquidación | Probado y aprobado | `DocumentosSalidaYFiniquitosTest` |
| 27 | Ajustes con bitácora | Probado y aprobado | `DocumentosSalidaYFiniquitosTest`, `CorreccionYCatalogoBajasTest` |
| 28 | Baja en trámite cuenta | Probado y aprobado | `Bajas/RotacionUnificadaTest` |
| 29 | Excel y sistema sin duplicar | Probado y aprobado | `RotacionUnificadaTest`; Excel real pendiente |
| 30 | Global = segmentos | Probado y aprobado | `RotacionUnificadaTest` |
| 31 | Cinco vistas | Probado y aprobado | `RotacionUnificadaTest` y navegador |
| 32 | Exportaciones con filtros | Probado y aprobado | `RotacionUnificadaTest` |
| 33 | Tabulador no cambia sueldos | Probado y aprobado | `Salarios/AdministracionSalariosTest` |
| 34 | 94% de compa-ratio | Probado y aprobado | `AdministracionSalariosTest` |
| 35 | Sin permiso, sin salarios | Probado y aprobado | `AdministracionSalariosTest`, `Salarios/SueldoFueraDelModuloTest`, `Seguridad/SuperficieEtapa1Test` |
| 36 | Matriz no aplica aumentos | Probado y aprobado | `AdministracionSalariosTest` |
| 37 | Altas y bajas nominales | Probado y aprobado | `Reportes/ReportesEtapa1Test` |
| 38 | NOI detecta faltantes | Probado y aprobado | `ReportesEtapa1Test`; layout oficial bloqueado aparte |
| 39 | Incidencias con la misma fuente | Probado y aprobado | `ReportesEtapa1Test`, `IncapacidadesTest` |
| 40 | Aniversario por alta IMSS | Probado y aprobado | `ReportesEtapa1Test` |
| 41 | Reporte conserva configuración | Probado y aprobado | `ReportesEtapa1Test` y navegador |
| 42 | Regenera con datos actuales | Probado y aprobado | `ReportesEtapa1Test` |
| 43 | Evento llega a la agenda del gerente | Implementado sin validar | `Agenda/AgendaEntrevistasTest`; push y app reales pendientes |
| 44 | Subgerente visualiza | Implementado sin validar | `AgendaEntrevistasTest`; dispositivo pendiente |
| 45 | Sin avisos duplicados | Probado y aprobado | `AgendaEntrevistasTest` |
| 46 | Viable actualiza candidato | Probado y aprobado | `AgendaEntrevistasTest`; en navegador falta |
| 47 | No viable conserva motivo | Probado y aprobado | `AgendaEntrevistasTest` |
| 48 | No asistió afecta indicador | Probado y aprobado | `AgendaEntrevistasTest` |
| 49 | Socioeconómico con evidencia | Implementado sin validar | Pest con disco falso; falta Synology |
| 50 | Indicadores de eventos reales | Probado y aprobado | `AgendaEntrevistasTest` |
| 51 | Móvil respeta permisos | Implementado sin validar | Jest 745 de 745 con simulaciones; sin dispositivo ni backend vivo |
| 52 | Migraciones preservan registros | Probado y aprobado | Copia en MariaDB 11.4 con InnoDB (abajo) |
| 53 | NAS conserva originales | Implementado sin validar | Pest con disco falso; falta Synology |
| 54 | Backend supera CI | Pendiente | Bloqueante 1 |
| 55 | App supera CI | Probado y aprobado | Mobile CI verde en `c411241` |
| 56 | Web reproduce el PDF | Implementado sin validar | Capturas revisadas abajo; falta pág. 24 en dispositivo y datos reales |

**Conteo:** 46 Probado y aprobado, 7 Implementado sin validar, 2 Bloqueado por información externa, 1 Pendiente.

## Migraciones comprobadas

Ejecutadas el 2026-10-30 en MariaDB 11.4.9, puerto 3307, base aislada, con la copia de la base local convertida a InnoDB y forzando InnoDB en las nuevas (186 tablas). La base de prueba se borró al terminar.

- **Filas:** los únicos cambios en tablas existentes fueron `baja_bitacora` +1 (la conversión), `permissions` +4, `role_has_permissions` +4 y `migrations` +10. `bajas` (11), `colaboradores` (199) y `finiquito_calculos` (6) no cambiaron.
- **Transformación:** una baja puesta a propósito en `en_revision_juridico` pasó a `en_revision_rh` con su renglón `conversion_flujo`. `juridico_por` y `juridico_en` se conservan como columnas.
- **Catálogo:** NR-01, NR-03, NR-05 y NR-06 con `implica_indemnizacion`; OT-01 a OT-03 presentes; RE-06 no existe y no se creó.
- **Llaves e índices:** todas las tablas nuevas en InnoDB, con llaves foráneas creadas. Nombre de índice más largo: 54 caracteres.
- **Idempotencia:** segunda corrida de `120000`, `130000` y `2026_11_03_100000` sin cambios.
- **Rollback:** todos los `down()` corren y vuelven a subir. Se encontró y corrigió un error: `2026_11_01_100000` fallaba al soltar una llave foránea inexistente, el caso de una base aplicada en MyISAM. La clasificación por migración está en `ETAPA1_DESPLIEGUE.md`.

**Límite de esta prueba:** los datos son de desarrollo y las tablas previas no tienen las llaves que tendrían en una base InnoDB original, porque vienen de MyISAM.

## Tablas nuevas o modificadas

Nuevas: `baja_avisos_rescision`, `baja_documento_ediciones`, `causal_grupos`, `rotacion_metas`, `incapacidades`, `incapacidad_documentos`, `nomina_rectificaciones`, `tabuladores_salariales`, `matrices_merito`, `reportes_guardados`, `altas_historicas`, `agenda_eventos`, `agenda_evento_historial`.

Modificadas: `causales_rotacion` (`implica_indemnizacion`, `fundamento_legal`, `causal_grupo_id`, `documento_salida`), `baja_documentos` (`clasificacion`), `finiquito_calculos` (`tipo_documento`).

## Servicios reutilizados y nuevos

- **Reutilizados:** `HeadcountService`, `PlantillaAutorizadaService`, `BajaService`, `BajaDocumentosService`, `MotorDocumentalService`, `DocumentoProcesoService`, `FiniquitoService`, `TableroRotacionService`, `CompensacionService`, `NominaQuincenalService`, `ReciboNominaService`, `LoteNominaService`, `ReportesRhService`, `CandidatoWorkflowService`, `DestinoNotificacionService`, `AlcanceOrganizacionalService`.
- **Nuevos:** `BajaDocumentoSalidaService`, `FiniquitoConsultaService`, `RotacionMetaService`, `IncidenciasNominaService`, servicios de `app/Services/Incapacidades`, `app/Services/Salarios` y `AccesoSueldoService`, `ReportePropioService`, `CatalogoCamposReporte`, `AltasNoiService`, `AltasHistoricasImportService`, `ReportePdfService`, `ReporteExcelService`, `AgendaEventoService`, `AgendaConsultaService`.

## Endpoints móviles

Contrato completo en `docs/AGENDA_API_MOVIL.md`: agenda (listar, detalle, crear, reprogramar, cancelar, resultado, evidencia, opciones), `GET /rh/candidatos/{id}/cv` y `GET /rh/candidatos/{id}/evidencias/{evidencia}`.

## Permisos nuevos

`incapacidades.*`, `salarios.*` (incluye `salarios.ver_en_expediente`), `agenda.*`, `causales_rotacion.*`, Mis reportes y datos sensibles de reportes. Se crean con `people:sincronizar-permisos`, asignables en Roles y permisos.

**Retiro:** el rol Sistemas ya no tiene `contratos.ver_documento`, `contratos.descargar`, `nomina.recibos.ver`, `finiquitos.ver` ni `finiquitos.descargar`.

## Seguridad revisada

`Seguridad/SuperficieEtapa1Test` (8 de 8) recorre por URL directa finiquitos, documentos de salida, contrato, vista previa, API de bajas, incapacidades, expediente y rotación, con un gerente de la sucursal y otro de sucursal ajena.

| Tema | Resultado |
|---|---|
| PDF de contrato | Ya exigía `contratos.ver_documento` o `descargar`. Gerentes no lo tienen. Conservan: RH, Dirección, Jurídico, Auditoría |
| `bajas.finiquito.vista_previa` | Solo `rh_admin` y `super_admin`. Sin cambio |
| Exportaciones con importes | Reportes y NOI ya exigen `salarios.ver`; finiquitos y recibos, su permiso |
| Sistemas | Corregido: consultaba documentos con sueldos sin necesitarlos |
| IDOR entre sucursales | Sin hallazgos en lo probado: el documento se valida contra su baja y el filtro contra el alcance |

**Decisión pendiente:** Jurídico y Auditoría conservan acceso a contratos, y Jurídico a importes de finiquito, porque atienden demandas y revisiones. RH auxiliar conserva el sueldo del expediente porque lo captura.

## Comparación visual

Capturas de la copia aislada con datos de desarrollo, escritorio 1440 px y móvil 390 px, sin errores JS ni 5xx y sin scroll horizontal.

| Pág. | Resultado |
|---|---|
| 5 Directorio | Coincide en tarjetas, agrupación, filtros y «Ver los N» |
| 10 Bajas | Coincide. **Corregido:** con el generador abierto se recortaba la columna Finiquito y quedaban ocultas las acciones. Ahora el renglón abre el generador, como pide el PDF |
| 11 Finiquitos | Coincide en tarjetas, tabla, detalle y panel de liquidación |
| 14 Causales | Coincide; agrega subcatálogos, orden y edición por pedido del usuario |
| 18 Salarios | Coincide. **Corregido:** faltaba la ruta de navegación superior |
| 24 App | No comparada: las pantallas no se renderizaron ni se abrieron en un dispositivo |
| 3, 6-8, 12-13, 20-21, 23 | Revisadas por cada incremento; ver la trazabilidad |

### Hallazgo para RH

Un finiquito de rescisión en datos de desarrollo aparece como «Liquidación» por traer indemnización de un cálculo anterior. Es la regla acordada para registros previos sin tipo guardado: se deriva sin reescribirlos. En producción conviene revisar esos casos.

## Riesgos

1. Cambio de cálculo: la indemnización estimada solo aplica a causales marcadas. Afecta a finiquitos nuevos o recalculados.
2. Textos base de convenio, aviso y constancia sin validación jurídica; se imprimen con nota visible.
3. Reconstrucción de plantilla sin foto mensual usa la sucursal actual.
4. La suite tarda cerca de dos horas en CI, lo que retrasa cada confirmación.

## Decisiones pendientes

**RH:** regla de días pagados, subsidio, plazas de Corporativo, evaluación de desempeño, criterio de «Datos por completar», corte de rotación, permisos del subgerente en agenda, datos NOI que People no captura.

**Jurídico:** textos de salida, fracción de RE-02 y RE-05, días inhábiles, testigos mínimos, inicio del plazo, beneficiarios en OT-01.

**Sistemas o proveedor:** layout de Aspel NOI, motor de tablas de producción.

## Despliegue y reversión

Ver `docs/propuesta/ETAPA1_DESPLIEGUE.md`.
