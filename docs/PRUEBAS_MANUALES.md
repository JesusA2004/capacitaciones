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

## 5. Jerarquía de puestos, movimientos laborales y "Cubrir vacante"

Entra como `rh.admin@mrlana.test` (o `super_admin`).

1. **Árbol**: entra a **Administración → Jerarquía de puestos**. En escritorio debe
   verse un organigrama con líneas conectoras y botones de zoom (+/−/reset) arriba a
   la derecha del árbol; en una ventana angosta (< 768px, o DevTools en modo móvil)
   el árbol se reemplaza por una lista expandible (flechas ▶ para abrir cada rama).
2. **Filtros**: aplica un filtro de empresa o sucursal — el árbol debe acotarse a los
   puestos con al menos un colaborador ahí. "Limpiar filtros" debe restaurar todo.
3. **Panel lateral**: click en cualquier puesto → se abre un panel con pestañas
   Detalle/Vacantes/Historial. La pestaña Historial tarda un instante en cargar
   (fetch bajo demanda) y debe mostrar movimientos/vacantes/cambios si existen, o un
   estado vacío si no.
4. **Editar jerarquía**: botón "Editar jerarquía" → cambia el puesto superior a otro
   puesto que ya sea descendiente de este (por ejemplo, intenta poner a un Gerente
   como subordinado de su propio Subgerente) → debe rechazarlo con un error de
   validación, no debe romper el árbol.
5. **Crear vacante desde el árbol**: pestaña Vacantes del panel → "Crear vacante para
   este puesto" → debe abrir el formulario de Vacantes con el puesto ya seleccionado.
6. **Cubrir vacante**: en **RH → Vacantes**, pasa el mouse (o toca en móvil) sobre una
   tarjeta de vacante abierta → ícono de "Cubrir" (✓) junto al de eliminar → elige
   modo "Colaborador interno", selecciona un colaborador y confirma. La vacante debe
   pasar a la columna "Cubierta".
7. **Histórico en expediente**: entra al **Expediente** del colaborador que acabas de
   mover → pestaña **"Historial RH"** → debe mostrar una línea de tiempo real (no
   "Próximamente") con el movimiento que acabas de generar, con fecha, descripción en
   texto natural y quién lo registró.

## 6. Checklist de responsive

Repite en cada pantalla listada abajo, en estos anchos (usa DevTools → responsive
mode, o cambia el zoom/ventana): **390px**, **430px**, **768px**, **1024px**,
**1366px**, **1920px**.

Qué buscar en cada uno: sin scroll horizontal de la página completa (una tabla o el
árbol de jerarquía con su propio `overflow-x-auto` interno sí es válido), botones de
acción no amontonados ni cortados, filtros usables (en móvil deben caber en un Sheet
o apilarse, no desbordar), cards no cortadas, texto largo con `truncate`/
`break-words` en vez de desbordar su contenedor, y en escritorio grande (1920px) que
el contenido no se vea forzado a una columna angosta en el centro cuando el diseño es
de tipo listado/tabla (los paneles tipo "portal" con `max-w-2xl` centrado, como
`/mi-portal`, son la excepción intencional).

Pantallas: Login, Dashboard RH, Reclutamiento, Candidatos, Vacantes, Altas digitales,
Expedientes (índice y detalle), Jerarquía de puestos, Plantillas, Formatos,
Solicitudes, Vacaciones, Reportes RH, Portal colaborador, y Administración → Empresas
/ Sucursales / Departamentos / Puestos / Roles / Colaboradores.

## 6. Regresión rápida

- Login/logout, cambio de tema claro/oscuro.
- Un CRUD cualquiera de Administración (Empresas, Sucursales, Departamentos) sigue
  funcionando igual que antes.
- Portal colaborador (`/mi-portal`, `/mi-perfil`) sigue mostrando el perfil, saldo de
  vacaciones y accesos rápidos sin errores en consola.
