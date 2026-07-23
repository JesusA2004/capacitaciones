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
| Solicitudes RH (catálogo, módulo aún no implementado) | `solicitudes.ver`, `solicitudes.crear`, `solicitudes.revisar`, `solicitudes.aprobar`, `solicitudes.rechazar`, `solicitudes.cerrar` |
| Reportes RH (catálogo, módulo aún no implementado) | `reportes_rh.ver`, `reportes_rh.exportar`, `reportes_rh.globales`, `reportes_rh.sucursal` |

Los permisos de altas/vacaciones/solicitudes/reportes_rh ya están sembrados y asignados a roles (siguiendo el mismo patrón que ya usaba el proyecto: crear el catálogo completo desde ahora aunque el módulo llegue en un checkpoint posterior), pero **todavía no hay ninguna ruta ni pantalla que los use** — se activarán cuando se construyan esos módulos.

## Roles nuevos

- **`rh_admin`**: administra expedientes, documentos, altas, vacaciones, solicitudes y reportes RH para **toda la organización**. También gestiona empresas (ver/crear/editar, no eliminar), sucursales, departamentos, puestos y colaboradores. No administra roles/permisos ni configuración del sistema (reservado a `super_admin`).
- **`rh_auxiliar`**: apoyo operativo con permisos limitados — puede ver, subir y revisar, pero no aprobar/rechazar decisiones finales (documentos, altas, solicitudes).
- **`jefe_directo`**: ve y aprueba vacaciones/solicitudes/expedientes únicamente de sus **subordinados directos** (`users.jefe_id`), un alcance más estrecho que `gerente_sucursal` (que ve toda la sucursal).

## Roles existentes, permisos ampliados

- **`gerente_sucursal`**: gana `expedientes.ver_sucursal`, `documentos.ver`, `vacaciones.*` (ver/solicitar/aprobar), `solicitudes.*` (ver/revisar/aprobar), `reportes_rh.ver`/`reportes_rh.sucursal`.
- **`colaborador`**: gana `expedientes.ver` (su propio expediente), `documentos.ver`/`subir`/`descargar`, `vacaciones.ver`/`solicitar`, `solicitudes.ver`/`crear`.
- **`auditor`**: gana acceso de solo lectura a empresas, expedientes (todos), documentos, vacaciones, solicitudes y reportes RH.

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
