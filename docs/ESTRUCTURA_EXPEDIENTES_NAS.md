# Estructura legible de expedientes en el NAS

Cómo se organizan físicamente en el disco `nas` (Synology) los documentos de expediente, y cómo migrar lo que quedó con la estructura legacy. Ver también `docs/SYNOLOGY_STORAGE.md` (modelo de datos) y `docs/CONFIGURACION_NAS.md` (configuración del disco).

## El problema que resuelve esto

Antes, cada documento se guardaba como `expedientes/{user_id}/{uuid}.ext` — técnicamente correcto (la base de datos siempre sabe qué es cada archivo), pero si alguien entra directamente a File Station en Synology no tiene forma humana de saber de qué empresa, sucursal o colaborador es un archivo, ni qué documento es.

## Estructura actual

```
expedientes/
    {EMPRESA}/
        {SUCURSAL}/
            {NUMERO_EMPLEADO} - {NOMBRE COMPLETO}/
                {TIPO DE DOCUMENTO} - v{version}.{extension}
                foto/
                    Foto de perfil.{extension}
```

Ejemplo real (datos demo de este repo):

```
expedientes/
    Mr. Lana/
        Ixtlahuaca/
            EMP-0001 - Ana Martinez Ruiz/
                Identificacion oficial (INE) - v1.pdf
                CURP - v1.pdf
                Constancia de situacion fiscal (RFC) - v1.pdf
                Acta de nacimiento - v1.pdf
                Contrato laboral - v1.pdf
        Cuernavaca/
            EMP-0007 - Daniela Flores Nava/
                Comprobante de domicilio - v1.pdf
                Comprobante de domicilio - v2.pdf
```

## Cómo se genera cada segmento (`App\Services\Expedientes\DocumentoStorageService`)

| Segmento | Método | Regla |
|---|---|---|
| Empresa | `rutaBaseColaborador()` | `$colaborador->sucursalPrincipal->empresa->nombre`, o `SIN EMPRESA` (con `Log::warning`) si falta |
| Sucursal | `rutaBaseColaborador()` | `$colaborador->sucursalPrincipal->nombre`, o `SIN SUCURSAL` (con `Log::warning`) si falta |
| Carpeta del colaborador | `carpetaColaborador()` | `"{numero_empleado} - {nombre completo}"`, o `"SIN-NUMERO-{id}"` si no tiene número de empleado — el número (o el id) resuelve homónimos |
| Nombre del archivo | `nombreDocumento()` | `"{DocumentType->nombre} - v{version}.{extension}"` — **nunca** el nombre que subió el usuario ni un UUID |
| Foto de perfil | `rutaFoto()` / `nombreFoto()` | `{carpeta del colaborador}/foto/Foto de perfil.{extension}` |

Todos los segmentos pasan por `sanitizarSegmento()`: quita separadores de ruta (`/`, `\`), rompe cualquier secuencia `..`, quita caracteres de control y los inválidos en Windows (`<>:"|?*`), colapsa espacios y transcribe acentos a ASCII (`México` → `Mexico`) para evitar problemas de codificación entre Windows/Linux/SMB. Nunca genera slugs (`mr-lana`) — mantiene mayúsculas y espacios para que siga siendo legible.

## Por qué esto no depende de que el nombre sea "secreto"

La seguridad del expediente **nunca** dependió de que el archivo tuviera un nombre ilegible:

- El disco NAS nunca se expone al frontend — el navegador solo conoce el `id` de `EmployeeDocument`.
- `disk`/`path` siguen ocultos (`$hidden` en el modelo).
- La descarga/preview pasa siempre por un endpoint protegido por policy (`GET /rh/documentos/{documento}/descargar`), nunca por una URL directa al NAS.

Por eso el nombre físico puede (y debe) ser legible.

## Versionado

Cada nueva versión de un documento es una fila nueva de `EmployeeDocument` (ver `docs/SYNOLOGY_STORAGE.md`) **y** un archivo físico nuevo (`... - v2.pdf`, `... - v3.pdf`, ...). La versión anterior nunca se sobrescribe ni se borra — sigue en disco y en BD (marcada `status=archivado`), así que el historial completo de un tipo de documento sigue siendo:

```php
EmployeeDocument::where('user_id', $id)->where('document_type_id', $tipoId)->orderByDesc('version')->get();
```

## Nombre físico inmutable

Una vez creado un `EmployeeDocument` y guardado su archivo, **nada renombra ese archivo automáticamente**:

- Cambiar el apellido de un colaborador **no** mueve sus documentos existentes — la carpeta creada en su momento queda como identidad documental histórica, y el `id`/número de empleado sigue identificándolo sin ambigüedad. Solo un nuevo documento subido *después* del cambio de nombre usaría el nombre actualizado.
- Cambiar de sucursal/empresa a un colaborador **no** relocaliza automáticamente sus documentos ya subidos. Si se necesita mover manualmente la carpeta completa de un colaborador a su nueva ubicación, hay que construir un `ExpedienteRelocationService` dedicado (copiar → verificar hash → actualizar BD → borrar origen, igual que `ExpedienteNasOrganizacionService`) — no se generó automáticamente en este checkpoint porque mover en cada cambio de puesto/sucursal no es obligatorio y el riesgo de hacerlo "por si acaso" en cada actualización es mayor que el beneficio. Mientras tanto, la carpeta existente sigue enlazada correctamente vía `employee_documents.path`; no hay ningún caso en que el archivo físico y la fila de BD queden desincronizados por esto.

## Migrar lo que quedó con la ruta legacy

`php artisan expedientes:organizar-nas` (ver `App\Services\Expedientes\ExpedienteNasOrganizacionService`):

```bash
# 1. Dry run — solo imprime el plan, no toca nada.
php artisan expedientes:organizar-nas

# 2. Aplicar de verdad (pide confirmación interactiva, o pasa --confirm en CI/scripts).
php artisan expedientes:organizar-nas --apply --confirm

# 3. Si algo salió mal, revertir con el manifiesto que --apply generó:
php artisan expedientes:organizar-nas --rollback=storage/app/expedientes-migrations/2026-01-01-120000-organizacion.json
```

Reglas de seguridad del comando (no negociables):

- **Nunca sobrescribe** un archivo destino existente. Si el destino ya existe, compara SHA-256: mismo hash → lo marca `duplicado` y no toca nada; hash distinto → lo marca `conflicto` y requiere revisión manual.
- **Nunca borra el origen antes de verificar el destino**: copia → verifica que el archivo copiado tenga el mismo SHA-256 que el origen → actualiza `path`/`stored_name` en BD → **solo entonces** borra el origen. Si algo falla a mitad de camino, el origen se conserva y el error se reporta (`error_copia`, `error_verificacion_final`).
- **Nunca borra archivos huérfanos** (que existen en el NAS pero no tienen fila en BD) ni filas sin archivo (`faltante`) — solo los reporta.
- **Lock de ejecución** (`Cache::lock('expedientes:organizar-nas', ...)`): no permite dos migraciones `--apply` simultáneas.
- **Manifiesto**: cada `--apply` escribe `storage/app/expedientes-migrations/{fecha}-organizacion.json` con `document_id`/`user_id`, `old_path`, `new_path`, hash y resultado de cada fila — es el registro de auditoría y la fuente para `--rollback`.
- Las carpetas legacy numéricas (`expedientes/{id}/`) solo se reportan como candidatas a borrar si quedaron **completamente vacías** tras migrar (`carpetasLegacyVacias()`); el comando nunca las borra automáticamente, solo las lista.

`php artisan expedientes:verificar-storage [--hash]`: verificación de solo lectura (nunca modifica nada) — compara cada `EmployeeDocument`/foto de perfil contra el disco y reporta faltantes, `stored_name` que no coincide con el archivo real, o (con `--hash`, más lento) SHA-256 que ya no coincide.

`php artisan people:diagnostico` incluye una sección "Estructura de expedientes en el NAS" que cuenta cuántos documentos siguen en ruta legacy, sin escanear el disco completo (por costo) — para el escaneo completo de huérfanos usa `expedientes:organizar-nas` (dry run).

## Consultar desde Synology, pero no administrar desde ahí

Los archivos pueden **consultarse** directamente desde File Station — ese es justamente el propósito de esta estructura. Pero **no deben renombrarse, moverse ni eliminarse manualmente ahí**: `employee_documents.path` es la referencia oficial, y un cambio manual en el NAS deja esa fila apuntando a un archivo que ya no existe (o a la ruta equivocada) sin que el sistema se entere. Si se necesita mover algo, usar `expedientes:organizar-nas` u otro comando/servicio del sistema, nunca File Station directamente.

## Performance

Ni la subida normal ni el listado de expedientes calculan hash de archivos ya existentes — el SHA-256 solo se calcula al subir un archivo nuevo, y al migrar/verificar cuando se pide explícitamente (`expedientes:verificar-storage --hash`). No hay ningún escaneo de disco en el camino caliente de la aplicación (ver expediente/documentos), solo en estos comandos de mantenimiento.
