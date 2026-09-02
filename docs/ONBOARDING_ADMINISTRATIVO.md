# Onboarding administrativo

Checklist administrativo de incorporación de un colaborador nuevo. **No es
capacitación** — no incluye cursos ni inducción de contenido, solo verifica que los
trámites administrativos de alta estén completos.

## Diseño: vista calculada, no tabla

Igual criterio que el expediente digital (`docs/EXPEDIENTES_DIGITALES.md`): no existe
una tabla `onboarding_checklists`. `App\Services\Onboarding\OnboardingService::checklist()`
calcula el estado a partir de datos que ya existen:

| Ítem | Se calcula a partir de |
|---|---|
| Datos personales capturados | `users.curp` y `users.domicilio` no nulos |
| Datos laborales capturados | `sucursal_principal_id`, `puesto_id` y `fecha_ingreso` no nulos |
| Fotografía cargada | `users.foto_path` no nulo |
| Documentos cargados | Existe al menos un `EmployeeDocument` del colaborador |
| Documentos aprobados | `ExpedienteService::resumenCompletitud()` — todos los requeridos aprobados |
| Contrato generado | Existe un `EmployeeDocument` con tipo `contrato` (cualquier estado cargado/en revisión/aprobado) |
| Contrato firmado cargado | Existe un `EmployeeDocument` de tipo `contrato` en estado `aprobado` |
| Aviso de privacidad aceptado | `AltaDigital.aviso_privacidad_aceptado` del alta que originó al colaborador |
| Consentimiento firmado | `AltaDigital.consentimiento_datos_aceptado` |
| Expediente completo | `ExpedienteService::resumenCompletitud()['porcentaje'] >= 100` |
| Alta aprobada por RH | Existe un `AltaDigital` con `user_id` = este colaborador y `estado = convertida_a_colaborador` |

Un colaborador dado de alta directamente desde **Administración → Colaboradores** (sin
pasar por alta digital) no tendrá `AltaDigital` asociada: los ítems de aviso/
consentimiento/alta aprobada aparecerán como pendientes, lo cual es correcto — ese
colaborador nunca pasó por el flujo de alta digital.

## Dónde se muestra

- Pestaña **Onboarding** dentro del expediente digital
  (`/rh/expedientes/{colaborador}` y `/mi-expediente`), junto a una pestaña **Avisos**
  ahora real (ya no "Próximamente") que muestra el estado del aviso de privacidad y el
  consentimiento de datos cuando existe un alta digital de origen.
- Dashboard RH y Reportes RH (secciones `docs/REPORTES_RH.md` /
  `docs/FASE_1_MR_LANA_PEOPLE.md`) reutilizan el mismo servicio para mostrar
  colaboradores con onboarding incompleto.

## Nota sobre "Contrato" en el expediente

La pestaña **Contrato** del expediente no es un módulo aparte: el contrato firmado se
sube como un documento más de tipo `contrato` en la pestaña **Documentos**. Ver
`docs/PLANTILLAS_FORMATOS.md` para cómo generar el contrato precargado antes de
imprimirlo y firmarlo.
