# Roles y permisos RH

Extiende `database/seeders/RolesYPermisosSeeder.php` con el catálogo de permisos RH. No se eliminó ningún rol ni permiso de capacitación existente: `administrador_capacitacion`, `instructor` y todos los permisos `cursos.*`, `multimedia.*`, etc. siguen intactos.

## Catálogo de permisos nuevos

| Módulo | Permisos |
|---|---|
| Empresas | `empresas.ver`, `empresas.crear`, `empresas.editar`, `empresas.eliminar` |
| Expedientes | `expedientes.ver`, `expedientes.ver_todos`, `expedientes.ver_sucursal`, `expedientes.crear`, `expedientes.editar`, `expedientes.revisar`, `expedientes.eliminar` |
| Documentos | `documentos.ver`, `documentos.subir`, `documentos.descargar`, `documentos.revisar`, `documentos.aprobar`, `documentos.rechazar`, `documentos.versiones` |
| Altas (catálogo, módulo aún no implementado) | `altas.ver`, `altas.crear`, `altas.enviar`, `altas.revisar`, `altas.aprobar`, `altas.cancelar` |
| Vacaciones (catálogo, módulo aún no implementado) | `vacaciones.ver`, `vacaciones.solicitar`, `vacaciones.aprobar`, `vacaciones.rechazar`, `vacaciones.ajustar`, `vacaciones.reportes` |
| Solicitudes RH (catálogo, módulo aún no implementado) | `solicitudes.ver`, `solicitudes.crear`, `solicitudes.revisar`, `solicitudes.aprobar`, `solicitudes.rechazar` (el permiso `solicitudes.cerrar` se retiró el 2026-10-02: no existe estado «cerrada») |
| Reportes RH (catálogo, módulo aún no implementado) | `reportes_rh.ver`, `reportes_rh.exportar`, `reportes_rh.globales`, `reportes_rh.sucursal` |

Los permisos de altas/vacaciones/solicitudes/reportes_rh ya están sembrados y asignados a roles (siguiendo el mismo patrón que ya usaba el proyecto: crear el catálogo completo desde ahora aunque el módulo llegue en un checkpoint posterior), pero **todavía no hay ninguna ruta ni pantalla que los use** — se activarán cuando se construyan esos módulos.

## Roles nuevos

- **`rh_admin`**: administra expedientes, documentos, altas, vacaciones, solicitudes y reportes RH para **toda la organización**. También gestiona empresas (ver/crear/editar, no eliminar), sucursales, departamentos, puestos y colaboradores. No administra roles/permisos ni configuración del sistema (reservado a `super_admin`).
- **`rh_auxiliar`**: apoyo operativo con permisos limitados — puede ver, subir y revisar, pero no aprobar/rechazar decisiones finales (documentos, altas, solicitudes).
- **`jefe_directo`**: ve y aprueba vacaciones/solicitudes/expedientes únicamente de sus **subordinados directos** (`users.jefe_id`), un alcance más estrecho que `gerente_sucursal` (que ve toda la sucursal).

## Roles ampliados (jerarquía comercial/regional)

Añadidos en el checkpoint de Solicitudes internas / API móvil. Todos reutilizan el mecanismo de alcance ya existente en `AlcanceOrganizacionalService` — ninguno necesitó lógica nueva, solo entrar en la lista correcta:

- **`director_comercial`**: alcance global (`ROLES_ALCANCE_GLOBAL`), **solo lectura** de reportes y reclutamiento (`reportes_rh.*`, `vacantes.ver_todos`, `candidatos.ver_todos`). No administra expedientes, documentos ni aprueba vacaciones/solicitudes — eso sigue siendo de RH y de la línea de gerencia.
- **`gerente_regional`**: alcance de sucursal (`ROLES_ALCANCE_SUCURSAL`), pero pensado para **varias sucursales**: se le asignan varias sucursales adicionales (`sucursal_user`, igual que a `gerente_sucursal`) y ve/aprueba en todas ellas. Mismo catálogo funcional que `gerente`.
- **`gerente`**: alcance de una sola sucursal, catálogo equivalente a `gerente_sucursal` (que se conserva intacto, no se elimina ni se renombra).
- **`subgerente`**: mismo alcance y catálogo que `gerente`, para poder **cubrirlo** según la jerarquía de puestos (`docs/JERARQUIA_PUESTOS.md`) — no tiene permisos exclusivos propios.
- **`coordinadora_regional`** y **`coordinadora`**: desde el 2026-10-15 usan PEOPLE como **colaboradoras** (mismo catálogo que `colaborador`, sin alcance ni bandeja RH) — decisión del negocio; ver `docs/ROLES_Y_NAVEGACION.md` § «Coordinadoras». Antes eran apoyo administrativo con alcance de sucursal/región.

El "alcance de varias sucursales" de los roles regionales **no es un tercer mecanismo**: es el mismo `ROLES_ALCANCE_SUCURSAL` + `sucursalesAdicionales()` que ya usaba `gerente_sucursal`, solo que a un gerente regional se le asignan varias filas en `sucursal_user` en vez de una.

## Roles existentes, permisos ampliados

- **`gerente_sucursal`**: gana `expedientes.ver_sucursal`, `documentos.ver`, `vacaciones.*` (ver/solicitar/aprobar), `solicitudes.*` (ver/revisar/aprobar), `reportes_rh.ver`/`reportes_rh.sucursal`.
- **`colaborador`**: gana `expedientes.ver` (su propio expediente), `documentos.ver`/`subir`/`descargar`, `vacaciones.ver`/`solicitar`, `solicitudes.ver`/`crear`.
- **`auditor`**: gana acceso de solo lectura a empresas, expedientes (todos), documentos, vacaciones, solicitudes y reportes RH. Nunca escritura; sin recibos de nómina, préstamos ni actas (mínimo privilegio, migración `2026_10_15_180100_auditor_minimo_privilegio`).

## AlcanceOrganizacionalService: tercer nivel de alcance

Antes de este checkpoint, `AlcanceOrganizacionalService` solo distinguía dos niveles: alcance global (`ROLES_ALCANCE_GLOBAL`) y alcance de sucursal (`ROLES_ALCANCE_SUCURSAL`). Se agregó un tercer nivel para `jefe_directo`, directamente en `limitarUsuariosPorAlcance()` y `puedeVerUsuario()`:

```php
if ($usuario->hasRole('jefe_directo')) {
    return $query->where('jefe_id', $usuario->id); // o $objetivo->jefe_id === $usuario->id
}
```

Esto significa que si `jefe_directo` alguna vez obtiene el permiso `usuarios.ver` (Administración → Colaboradores), automáticamente vería solo a su equipo directo ahí también — el alcance está centralizado en un único lugar, no duplicado por pantalla, siguiendo el principio ya documentado en `docs/ARQUITECTURA.md`.

`rh_admin` y `rh_auxiliar` se agregaron a `ROLES_ALCANCE_GLOBAL`: el personal de RH opera sobre toda la organización, no solo su propia sucursal.

Se agregaron además `limitarExpedientesPorAlcance()` y `puedeVerExpediente()`, que reutilizan la misma lógica de alcance pero exigen además el permiso específico de expedientes (`expedientes.ver_todos`/`expedientes.ver_sucursal`), para que un rol con alcance global por otra razón (p. ej. `auditor` reconfigurado sin `expedientes.*`) no vea expedientes ajenos solo por tener alcance global en otro contexto.

## Aplicación real de permisos (no solo UI)

- `App\Policies\EmpresaPolicy` — CRUD de empresas.
- `App\Policies\EmployeeDocumentPolicy` — acciones de documentos (`verExpediente`, `subir`, `descargar`, `revisar`, `aprobar`, `rechazar`, `verVersiones`); no usa los verbos CRUD por defecto de Laravel porque "documentos laborales" no es un CRUD simétrico (un colaborador puede subir pero nunca aprobar su propio documento).
- `Rh\ExpedienteController` — autorización manual vía `AlcanceOrganizacionalService::puedeVerExpediente()` (no hay un modelo "Expediente" dedicado, así que no hay una Policy formal de Laravel; ver `docs/EXPEDIENTES_DIGITALES.md`).
- `Rh\EmployeeDocumentController` — cada acción llama a `$this->authorize()` contra `EmployeeDocumentPolicy`.

Cubierto por tests: `tests/Feature/Administracion/EmpresaTest.php`, `tests/Feature/Rh/ExpedienteTest.php`, `tests/Feature/Rh/EmployeeDocumentTest.php`.

## Roles y permisos del ciclo laboral (septiembre 2026)

Roles nuevos: `direccion` (autoriza evaluaciones y préstamos —las bajas no requieren autorización—; indicadores
globales), `juridico` (consulta documentación jurídica y administra plantillas del motor
documental) y `sistemas` (cuentas, roles, releases y auditoría; desde 2026-10-13 con lectura global para soporte y sin ninguna acción de decisión de RH — ver `docs/FLUJO_OPERATIVO_Y_PERMISOS.md` §4).
Los tres tienen alcance global en `AlcanceOrganizacionalService`, pero cada acción sigue
exigiendo su permiso específico.

Permisos nuevos: `colaboradores.alta`, `colaboradores.activar`, `candidatos.contratar`,
`plantillas_documentales.ver|administrar`, `documentos_laborales.ver|generar|operar_fisico|cancelar`,
`contratos.ver`, `evaluaciones.ver|capturar|autorizar`, `cierres.ver|gestionar|ejecutar_baja`,
`finiquitos.confirmar_pago`, `nomina.recibos.ver|crear|importar`,
`prestamos.ver|autorizar|resguardar`, `actas.ver|crear|gestionar`, `indicadores.ver`.
Detalle de flujos y Policies en `docs/backend-rh-completion.md`.

## Precisión por acción y alcance (2026-10-26)

### Recorrido del candidato: un permiso por paso

`candidatos.ver` (+ su alcance) deja **seguir todo el recorrido**; cada botón
aparece —y el backend lo acepta— solo a quien tiene el permiso de **ese paso**
(`CandidatoWorkflowService::accionesPermitidas()` y cada método del workflow,
web y API):

| Paso | Permiso |
|---|---|
| 1 · Revisar perfil | `candidatos.revisar_perfil` |
| 2 · Registrar / aprobar entrevista | `candidatos.aprobar_entrevista` |
| 3 · Link y resultados psicométricos | `candidatos.registrar_psicometricas` |
| 4 · Revisar psicométrico | `candidatos.revisar_psicometricas` |
| 5 · Cargar socioeconómico | `candidatos.registrar_socioeconomico` |
| 6 · Revisar socioeconómico | `candidatos.revisar_socioeconomico` |
| 7 · Referencias | `candidatos.validar_referencias` |
| 8 · Autorización final de RH | `ciclo.autorizar_rh` |
| 9 · QR de contratación | `candidatos.contratar` |
| Cerrar el proceso | `candidatos.cerrar_proceso` |
| Solicitar / decidir intervención | `candidatos.solicitar_intervencion` / `candidatos.intervencion_decidir` |
| Mini expediente | `candidatos.adjuntar` |

`candidatos.editar` ya solo edita datos del candidato; `candidatos.evaluar` y
`candidatos.rechazar` quedaron **retirados** (`config/permisos_modulos.php` →
`retirados`): se conservan en la base pero no habilitan nada. Quien los tenía
recibió los pasos que cubrían (`herencia`, que ahora acepta varios permisos
padre). Mientras un permiso nuevo no exista en la base (antes de
`people:sincronizar-permisos`), `PermisoAccionService` responde con sus
padres: el deploy no quita ni da nada.

Si el gerente responsable no tiene el permiso del paso, el pendiente y su aviso
van a quien sí lo tiene (por permiso y alcance) y la ficha muestra
«Reclutamiento» como responsable. Ejemplo: rol Gerente con solo «Ver» +
«Paso 2» → registra la entrevista y sigue viendo el recorrido sin botones.

### Directorio con alcance

El módulo «Directorio» tiene alcance (`directorio.ver_todos|ver_region|ver_sucursal`).
Sin alcance = **su sucursal + su jefe directo**. Gerente → sucursal; Regional →
región (matriz comercial); RH, Sistemas, Dirección, Auditoría, Jurídico,
Contraloría → todos. El personal de Corporativo ve a toda la empresa.

### Avisos según el alcance del módulo

`config/permisos_modulos.php` → `alcance_avisos`: el aviso a quien tiene un
permiso de acción (aprobar solicitudes/vacaciones, autorizar préstamos) se
acota con el alcance de su rol en ese módulo (`AlcanceModuloService::alcanzaParaAviso`,
usado por `WorkflowRoutingService`, `NotificadorRhService::responsablesDe` y
`TareaService::destinatarios`). Los avisos del organigrama (jefe directo,
aprobador del visto bueno) no cambian.

`notificaciones.silenciar_sucursales` (RH, Sistemas, Dirección Comercial,
Dirección, Regional, Jurídico, Contraloría, Auditoría): Configuración →
**Avisos por sucursal**. Silencia campana y push de esas sucursales; nunca la
propia confirmación, lo propio ni lo de su equipo directo; lo pendiente sigue
en «Mis pendientes» (`SucursalesSilenciadasService`, aplicado en
`NotificacionEventosService::entregar()`).
