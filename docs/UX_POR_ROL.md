# UX por rol

Regla: **WEB = administrar. APP = actuar.** El detalle de menús está en
`docs/ROLES_Y_NAVEGACION.md`; la autorización real vive en Policies y permisos.

**COLABORADOR** (`colaborador`)

- Autoservicio («Mi espacio»).
- Solicitudes, vacaciones y préstamos.
- Avisos.
- Inducción.
- Directorio.
- Perfil (corrige datos solo por solicitud).

**GERENTE** (`gerente_sucursal`, `gerente`, `subgerente`, `coordinadora`, `jefe_directo`)

- Mis pendientes.
- Entrevistas y socioeconómico de candidatos (sin preautorización: la contratación la autoriza solo RH).
- Solicitudes de su equipo.
- Evaluaciones (inducción y periodo de prueba).
- Su equipo.

**REGIONAL** (`gerente_regional`, `coordinadora_regional`)

- Escalaciones e intervenciones de su región.

**DIRECCIÓN COMERCIAL** (`director_comercial`)

- Escalaciones de nivel superior e intervenciones sobre rechazos de RH.
- Consulta de candidatos y su expediente previo a la contratación.

**RH** (`rh_admin`, `rh_auxiliar`)

- Operación completa en web (reclutamiento → contratación → expediente → baja).
- Acciones rápidas en la app.

**SISTEMAS** (`sistemas`)

- Altas y bajas de cuentas.
- Accesos y roles.
- Tareas técnicas: versiones de la app, dispositivos y auditoría.
