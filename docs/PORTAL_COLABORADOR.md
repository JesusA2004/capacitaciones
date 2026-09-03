# Portal del colaborador

Portal web limitado, con diseño mobile-first (pensado para verse bien tanto en escritorio como en el navegador del teléfono, mismo criterio visual que tendrá la futura app móvil — ver `docs/API_MOVIL.md`).

## Rutas

```
GET /mi-portal    Portal/Index.vue — hub con accesos rápidos y resúmenes
GET /mi-perfil    Portal/Perfil.vue — datos básicos de solo lectura
```

`/mi-portal` y `/mi-perfil` reutilizan `App\Services\Colaboradores\ColaboradorPerfilService` (mismo servicio que la API móvil). **No se crearon rutas `/mis-vacaciones` ni `/mis-solicitudes` separadas**: esa función ya la cubren `/vacaciones` y `/solicitudes` (con toda su lógica de creación/cancelación/timeline) — el portal enlaza directamente a esas páginas en vez de duplicar rutas y controladores para lo mismo (ver `docs/ARQUITECTURA_SERVICES.md`, "no duplicar lógica").

## Qué ve el colaborador (Fase 1)

- Nombre, puesto, sucursal, empresa, jefe directo, fecha de ingreso, antigüedad.
- Vacaciones: días generados/usados/disponibles.
- Solicitudes recientes (folio, motivo, estado) con acceso a la lista completa.
- Notificaciones recientes con contador de no leídas.

## Qué NO ve (fuera de alcance de Fase 1)

Documentos internos completos, documentos confidenciales, expedientes de otros colaboradores, reportes RH, candidatos, vacantes internas, datos sensibles de otros colaboradores, sueldos, información administrativa restringida. `PortalController` nunca consulta nada fuera de `$request->user()` — no recibe ni acepta un ID de colaborador por parámetro.

## Diseño

Cards grandes con `rounded-2xl`/`rounded-3xl`, avatar con iniciales de respaldo si no hay foto, degradado de marca en el encabezado, hovers con `-translate-y-0.5` + sombra, badges de estado reutilizando `EstadoBadge.vue`, estados vacíos con mensaje amable (sin tablas ni CRUD "de escritorio"). Ancho máximo `max-w-2xl` centrado para que se sienta como una pantalla de app tanto en desktop como en móvil.

## Pendiente

- Navegación inferior fija (bottom nav) tipo app — hoy la navegación es la barra lateral estándar del layout, colapsable a iconos en pantallas pequeñas.
- Ver estado de permisos/incapacidades como tarjetas dedicadas (hoy se ven mezcladas dentro de "Solicitudes recientes", filtradas por tipo desde el propio listado).
