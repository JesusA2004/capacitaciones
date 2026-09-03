# Pruebas manuales — Fase 1, cierre

Guía para probar en navegador lo entregado en el cierre de Fase 1 de MR. LANA PEOPLE.
Requiere el proyecto corriendo (`composer dev`, ver `README.md`) y la base de datos
sembrada (`php artisan migrate:fresh --seed` o al menos `RolesYPermisosSeeder` +
`UsuarioDemoSeeder`).

Usuarios demo: ver tabla en `README.md`. Password de todos: `Capacitacion2026!`.

## 0. Antes de empezar

Si vienes de un checkout anterior a este cierre, corre una vez:

```bash
php artisan migrate
php artisan db:seed --class=RolesYPermisosSeeder   # agrega permisos nuevos sin duplicar
php artisan permission:cache-reset
php artisan wayfinder:generate --with-form
npm run build
```

Sin esto, un usuario que antes veía "Vacantes"/"Candidatos"/etc. en el menú puede
dejar de verlos (los permisos nuevos no estarían sembrados) — no es un bug del código,
es la base de datos local desactualizada.

## 1. Filtros y exportación (Prioridad 1)

Entra como `rh.admin@mrlana.test` y repite esto en cada uno de los 8 listados
(Vacantes, Candidatos, Altas digitales, Plantillas, Formatos, Solicitudes, Expedientes,
Vacaciones):

1. Abre el listado. Debe haber un buscador, selects de filtro visibles y (si el módulo
   tiene más de 4-5 filtros) un botón "Filtros" que abre un panel lateral.
2. Aplica al menos dos filtros (por ejemplo empresa + estado). La lista debe acotarse.
3. Click en "Excel" — debe descargar un `.xlsx` cuyo contenido refleja **solo** los
   registros filtrados (ábrelo y confirma que no aparecen registros fuera del filtro).
4. Click en "PDF" — mismo criterio, debe abrir/descargar un PDF con el mismo contenido
   que el Excel.
5. Click en "Limpiar filtros" — todos los filtros vuelven a su estado inicial y la lista
   se recarga sin filtrar.

## 2. Generar formato desde una solicitud (Prioridad 2)

1. Como colaborador (`colaborador1@mrlana.test`), entra a **Mis solicitudes** → crea una
   solicitud (por ejemplo "Permiso con goce", con fecha inicio/fin y motivo).
2. Sal y entra como `rh.admin@mrlana.test` → **Solicitudes (revisión)** → abre la
   solicitud recién creada.
3. Si no existe todavía, sube una plantilla DOCX de prueba en **Plantillas** con al
   menos un placeholder `{{nombre_completo}}` o `{{motivo_permiso}}` (catálogo completo
   en `claude/formatos/placeholders/PLACEHOLDERS.md`).
4. En la solicitud, click **"Generar formato"** → elige la plantilla → confirma. Debe
   aparecer en la lista "Formatos generados" de esa solicitud, con estado "Generado".
5. Click **"Descargar"** — el `.docx` debe abrir en Word/LibreOffice con los
   placeholders ya reemplazados por los datos reales de la solicitud (nombre del
   colaborador, folio, fechas, motivo). El estado cambia a "Entregado".
6. Click **"Subir firmado"** → elige un tipo de documento del catálogo de expediente →
   sube cualquier PDF/imagen de prueba → confirma. El estado del formato cambia a
   "Firmado".
7. Ve al **Expediente** de ese colaborador → pestaña **Documentos** → el archivo subido
   debe aparecer ahí, en estado "En revisión", como una nueva versión (o la primera) del
   tipo de documento elegido.

## 3. Expedientes (verificación visual)

1. Como `rh.admin@mrlana.test`, entra a **Expedientes**. Cada colaborador debe verse
   como una tarjeta tipo carpeta (pestaña arriba, "hojas" apiladas detrás, foto
   "clipeada" en la esquina con animación al pasar el mouse).
2. Si algún colaborador tiene foto cargada, debe verse la foto real, no el ícono de
   persona genérico.
3. Entra al detalle de un colaborador — encabezado con foto/avatar, badge de estado,
   barra de progreso del expediente, y pestañas (Resumen, Datos personales, Datos
   laborales, Documentos, Onboarding, Contrato, Avisos, Vacaciones, Solicitudes,
   Historial RH, Bitácora). Si no ves todas, la lista de pestañas hace scroll
   horizontal — no todas caben en pantallas angostas, es esperado.

Si el diseño de carpeta **no** se ve (se ve como una tabla plana o cards sin la pestaña
de carpeta), corre `npm run build` — es casi siempre un build desactualizado, no un
problema de código (ver `docs/ARQUITECTURA.md`).

## 4. Alcance organizacional

1. Entra como `gerente.sucursal@mrlana.test` (o cualquier rol de alcance por sucursal).
2. En Vacantes/Candidatos/Altas/Expedientes/Vacaciones/Solicitudes, confirma que **solo**
   ves registros de tu(s) sucursal(es) — nunca de otra empresa/sucursal.
3. Exporta a Excel/PDF desde esa sesión — el archivo debe traer únicamente esos mismos
   registros (el alcance también se respeta en la exportación, no solo en pantalla).

## 5. Regresión rápida

- Login/logout, cambio de tema claro/oscuro.
- Un CRUD cualquiera de Administración (Empresas, Sucursales, Departamentos) sigue
  funcionando igual que antes.
- Portal colaborador (`/mi-portal`, `/mi-perfil`) sigue mostrando el perfil, saldo de
  vacaciones y accesos rápidos sin errores en consola.
