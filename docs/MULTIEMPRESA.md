# Multiempresa

Base de la estructura `Empresa → Sucursal → Departamento/Puesto → Colaborador` del Portal RH.

## Modelo de datos

```
empresas
  id, nombre, razon_social (nullable), rfc (nullable), logo_path (nullable), activo, timestamps, soft delete

sucursales
  ...columnas existentes...
  empresa_id  (nullable, FK -> empresas.id, nullOnDelete)
```

`empresa_id` en `sucursales` es **nullable a nivel de base de datos** a propósito: el proyecto no tiene `doctrine/dbal` instalado, así que no es seguro forzar `NOT NULL` con `->change()` después del backfill sin ese paquete. La obligatoriedad se aplica en la capa de aplicación:

- `StoreSucursalRequest`/`UpdateSucursalRequest` exigen `empresa_id` como `required|exists:empresas,id`.
- El formulario de sucursal (`SucursalFormDialog.vue`) siempre pide seleccionar una empresa.

### Colaborador → Empresa

`users` **no tiene** columna `empresa_id` propia. La empresa de un colaborador se resuelve de forma indirecta a través de su sucursal principal: `User::empresa(): ?Empresa` devuelve `$this->sucursalPrincipal?->empresa`. No es una relación Eloquent (no existe "belongsTo a través de belongsTo" nativo), es un helper de lectura. Para evitar N+1 al listar varios colaboradores, se debe eager-cargar `sucursalPrincipal.empresa` antes de usarlo en bucle (así lo hacen `ExpedienteController` y `MetricasRhDashboardService`).

Esta decisión (indirecta, no columna propia) se tomó porque el encargo permitía explícitamente "directa o indirecta por sucursal", y evita mantener dos fuentes de verdad sincronizadas (columna `users.empresa_id` vs. `sucursales.empresa_id`) para el mismo dato.

## Migraciones

- `2026_07_22_222903_create_empresas_table.php`
- `2026_07_22_222904_add_empresa_id_to_sucursales_table.php` — agrega la columna **y** hace el backfill: si ya existían sucursales sin empresa, crea (o reutiliza) la empresa "Mr. Lana" y se las asigna. En una instalación nueva (sin sucursales todavía) no hace nada; `EmpresaSeeder`/`SucursalSeeder` se encargan de crear la empresa por defecto y asignarla a las sucursales de demostración.

## Backend

- `App\Models\Empresa` — `sucursales(): HasMany<Sucursal>`, accessor `logo_url` (URL pública del logo, disco `public`; los logos de empresa son imágenes ligeras de marca, **no** van al NAS reservado para documentos laborales pesados).
- `App\Http\Controllers\Administracion\EmpresaController` — CRUD (`index`/`store`/`update`/`destroy`), mismo patrón que `SucursalController` (sin `create`/`edit`/`show`, formularios en diálogo).
- `App\Policies\EmpresaPolicy` — `empresas.ver` / `empresas.crear` / `empresas.editar` / `empresas.eliminar`.

## Rutas

```
GET    /administracion/empresas              administracion.empresas.index
POST   /administracion/empresas              administracion.empresas.store
POST   /administracion/empresas/{empresa}     administracion.empresas.update   (POST, no PUT: sube archivo "logo")
DELETE /administracion/empresas/{empresa}     administracion.empresas.destroy
```

## Frontend

- `resources/js/pages/Administracion/Empresas/Index.vue` + `resources/js/components/Administracion/EmpresaFormDialog.vue`.
- `Sucursales/Index.vue` y `SucursalFormDialog.vue` ahora muestran/filtran/editan `empresa_id`.
- Sidebar: "Empresas" dentro de Administración, visible con el permiso `empresas.ver`.

## Pendiente / no incluido en este checkpoint

- No se creó multiempresa a nivel de `departamentos`/`puestos` (siguen siendo catálogos globales, no por empresa). Si se necesita aislar departamentos/puestos por empresa en el futuro, requerirá una migración adicional y ajustar `AlcanceOrganizacionalService`.
- No hay validación de formato real de RFC (solo longitud máxima).
