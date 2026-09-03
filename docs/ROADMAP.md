# Roadmap

## Fase 1 — Reclutamiento y Administración de Personal (en construcción)

Orden de implementación seguido en esta fase:

1. Identidad MR. LANA PEOPLE (branding, textos).
2. Feature flags (`rh_portal`, `capacitacion`, `desempeno`, `nine_box`).
3. Carpeta `claude/` para formatos oficiales.
4. Documentación base.
5. Multiempresa (ya existente, confirmada).
6. Estructura organizacional (empresas/sucursales/departamentos/puestos, ya existente).
7. Jerarquía de puestos.
8. Perfiles de puesto.
9. Vacantes.
10. Reclutamiento / candidatos.
11. Seguimientos de candidatos.
12. Alta digital.
13. Onboarding administrativo.
14. Expedientes tipo carpetas (extensión de lo existente).
15. Documentos en NAS (extensión de lo existente).
16. Plantillas y formatos precargados (DOCX).
17. Estatus laboral, IMSS, periodo de prueba.
18. Vacaciones.
19. Permisos/incapacidades/solicitudes internas — **completo**, ver `docs/SOLICITUDES_INTERNAS.md`.
20. Dashboard RH (actualización).
21. Reportes RH — **completo**, ver `docs/REPORTES_RH.md`.
22. Portal colaborador limitado — **completo**, ver `docs/PORTAL_COLABORADOR.md`.
23. Roles ampliados (director_comercial, gerente_regional, gerente, subgerente, coordinadora_regional, coordinadora) — **completo**, ver `docs/ROLES_PERMISOS_RH.md`.
24. API móvil v1 (Sanctum) — **completo**, ver `docs/API_MOVIL.md`.
25. Tests.
26. Pulido visual (filtros + exportación Excel/PDF en el resto de listados, "carpetas" visuales en Expedientes, animaciones/hovers en todo el sistema).
27. Build final.

Ver `docs/PORTAL_RH.md` para el historial de checkpoints previos a este roadmap
(Fase 1 original "Portal RH base" y Fase 2 "Documentos y alta digital" de ese
documento, que se retoman y completan aquí).

## Fase 2 — Desempeño / Nine Box (futura, oculta)

No se desarrolla en este proyecto. Bandera `desempeno` / `nine_box` en
`config/features.php`, ambas en `false`. Pantalla "Próximamente" genérica
(`resources/js/pages/Proximamente.vue`) si se intenta acceder.

## Fase 3 — Capacitación completa (futura, conservada oculta)

Ya construida en el proyecto original (cursos, biblioteca multimedia, cuestionarios,
actividades, sesiones en vivo, reportes de cumplimiento). Se conserva intacta —
modelos, migraciones, controladores, rutas, permisos y datos— detrás de la bandera
`capacitacion` (`false` por defecto). Pantalla dedicada `Capacitacion/Proximamente.vue`.
Ver `docs/CAPACITACION_PROXIMAMENTE.md`.
