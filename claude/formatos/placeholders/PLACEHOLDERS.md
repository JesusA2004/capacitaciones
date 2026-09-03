# Placeholders disponibles para plantillas DOCX

El sistema reemplaza estos placeholders al generar un documento precargado a partir de
una plantilla (`document_templates`) y un colaborador, candidato o solicitud. Se
implementan con `PhpOffice\PhpWord\TemplateProcessor`, así que en el DOCX se escriben
literalmente entre llaves dobles, como texto normal (mismo tipo de letra, tamaño y
color que el resto del párrafo).

Fuente de datos: `App\Services\Plantillas\PlaceholderResolver` (`app/Services/Plantillas/PlaceholderResolver.php`).
Ese servicio es la única fuente de verdad de qué placeholder mapea a qué dato — si
agregas un placeholder nuevo, debe registrarse ahí primero.

## Catálogo

| Placeholder | Dato que precarga | Origen |
|---|---|---|
| `{{nombre_colaborador}}` | Nombre(s) | `users.name` / `candidatos.nombre` |
| `{{apellidos_colaborador}}` | Apellidos | `users.apellidos` / `candidatos.apellidos` |
| `{{nombre_completo}}` | Nombre completo | `nombre` + `apellidos` |
| `{{curp}}` | CURP | `users.curp` / `candidatos.curp` |
| `{{rfc}}` | RFC | `users.rfc` |
| `{{nss}}` | Número de Seguro Social | `users.nss` |
| `{{domicilio}}` | Domicilio | `users.domicilio` |
| `{{telefono}}` | Teléfono | `users.telefono` / `candidatos.telefono` |
| `{{correo}}` | Correo | `users.email` / `candidatos.correo` |
| `{{empresa}}` | Nombre de la empresa | `empresas.nombre` (vía sucursal principal) |
| `{{sucursal}}` | Nombre de la sucursal | `sucursales.nombre` |
| `{{departamento}}` | Nombre del departamento | `departamentos.nombre` |
| `{{puesto}}` | Nombre del puesto | `puestos.nombre` |
| `{{jefe_directo}}` | Nombre del jefe directo | `users.jefe_id` → `nombreCompleto()` |
| `{{fecha_ingreso}}` | Fecha de ingreso (dd/mm/aaaa) | `users.fecha_ingreso` |
| `{{fecha_actual}}` | Fecha en que se genera el documento | `now()` al momento de generar |
| `{{dias_vacaciones}}` | Días de vacaciones solicitados/disponibles | `vacation_requests` / cálculo de saldo |
| `{{fecha_inicio_permiso}}` | Fecha de inicio de la solicitud | `solicitudes_internas.fecha_inicio` |
| `{{fecha_fin_permiso}}` | Fecha de fin de la solicitud | `solicitudes_internas.fecha_fin` |
| `{{motivo_permiso}}` | Motivo capturado por el colaborador | `solicitudes_internas.motivo` |
| `{{tipo_solicitud}}` | Tipo de solicitud (legible) | `solicitudes_internas.tipo` |
| `{{folio_solicitud}}` | Folio único de la solicitud | `solicitudes_internas.folio` |
| `{{fecha_inicio_incapacidad}}` | Fecha de inicio de la incapacidad | `solicitudes_internas.fecha_inicio` (tipo `incapacidad`) |
| `{{fecha_fin_incapacidad}}` | Fecha de fin de la incapacidad | `solicitudes_internas.fecha_fin` (tipo `incapacidad`) |
| `{{motivo_solicitud}}` | Motivo capturado en la solicitud | `solicitudes_internas.motivo` |
| `{{observaciones}}` | Observaciones adicionales de RH o del colaborador | `solicitudes_internas.observaciones` |

Placeholders sin dato disponible para el contexto de generación (por ejemplo,
`{{dias_vacaciones}}` en un contrato de alta, donde no aplica) se dejan **en blanco**, no
se elimina el resto del documento.

## Ejemplo de uso en DOCX

Dentro de un párrafo del documento Word, se escribe tal cual:

```
El colaborador {{nombre_completo}}, con puesto {{puesto}} en la sucursal {{sucursal}}
de {{empresa}}, ingresó el {{fecha_ingreso}}.
```

Al generar el documento, el sistema produce un DOCX (o PDF si aplica) con esos valores
ya sustituidos, conservando el formato original del documento.

## Recomendaciones para RH al preparar plantillas

1. Escribe el placeholder completo, sin cortarlo entre líneas ni con formato mixto
   dentro de la misma llave (por ejemplo, evita que "nombre_completo" quede con una
   palabra en negrita y otra no — eso puede impedir que Word lo detecte como una sola
   cadena de texto).
2. No uses autocorrección de comillas/guiones de Word sobre las llaves `{{ }}`.
3. Verifica que el placeholder esté escrito exactamente igual al catálogo (minúsculas,
   con guion bajo, sin espacios dentro de las llaves).
4. Si necesitas un dato que no está en el catálogo, repórtalo antes de subir la
   plantilla — agregarlo requiere registrarlo en `PlaceholderResolver` primero.
5. Genera un documento de prueba con un colaborador de datos demo antes de usar la
   plantilla con datos reales.
6. Conserva el archivo original (sin placeholders) en un lugar seguro fuera del
   repositorio, por si se necesita revertir cambios de diseño.
