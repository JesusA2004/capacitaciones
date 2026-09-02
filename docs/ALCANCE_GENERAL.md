# Alcance general — MR. LANA PEOPLE

## Qué es

**MR. LANA PEOPLE** es el Portal Integral de Reclutamiento, Administración de Personal
y Recursos Humanos de las empresas del grupo. Nace sobre el proyecto previo de
capacitación (Laravel + Inertia + Vue), cuya infraestructura de organización
(empresas, sucursales, departamentos, puestos, colaboradores, roles/permisos,
expedientes, documentos en NAS) se conserva y se extiende.

Capacitación (cursos, videos, cuestionarios, sesiones en vivo) y Desempeño/Nine Box
**no se eliminan**: quedan ocultos detrás de feature flags como fases futuras. Ver
`docs/FEATURE_FLAGS.md` y `docs/ROADMAP.md`.

## Fases

| Fase | Contenido | Estado |
|---|---|---|
| **Fase 1** (este proyecto) | Reclutamiento y Administración de Personal | En construcción |
| **Fase 2** | Desempeño / Nine Box | Oculta, no desarrollada |
| **Fase 3** | Capacitación completa | Oculta, construida previamente, conservada |

## Módulos de la Fase 1

Ver el detalle completo en `docs/FASE_1_MR_LANA_PEOPLE.md`. En resumen:

1. Dashboard RH
2. Multiempresa
3. Empresas / Sucursales / Departamentos / Puestos
4. Jerarquía de puestos
5. Perfiles de puesto
6. Reclutamiento (prospectos, candidatos)
7. Vacantes
8. Alta digital de colaborador
9. Onboarding administrativo (checklist)
10. Expediente digital tipo carpetas
11. Documentos en Synology NAS
12. Formatos, contratos y plantillas precargables (DOCX)
13. Estatus laboral, IMSS y periodo de prueba
14. Vacaciones
15. Permisos, incapacidades y solicitudes internas
16. Reportes RH
17. Portal/app web del colaborador (vista limitada)
18. Roles y permisos

## Lo que NO cubre la Fase 1

Ver `docs/LIMITACIONES.md` para el detalle completo (nómina, timbrado CFDI, IMSS/IDSE,
firma electrónica avanzada, OCR/IA, integraciones automáticas externas, etc.).

## Público objetivo por rol

- **RH/Admin**: opera todo el sistema según su alcance organizacional.
- **Gerencia** (gerente, gerente regional, director comercial): participa en
  reclutamiento/vacantes y aprueba solicitudes de su alcance.
- **Colaborador**: portal limitado (sus datos, vacaciones, solicitudes).
- **Candidato**: acceso solo por liga segura de alta digital, sin cuenta de usuario.
- **Auditor**: consulta de solo lectura.
