# Fotografía para expediente (= foto de perfil)

La «Fotografía para expediente» que pide la incorporación **es la misma foto de
perfil** del colaborador: `colaboradores.foto_path`, administrada solo por
`App\Services\Colaboradores\FotoColaboradorService`. No existe otra fuente ni
otra columna. Toda foto, venga de la app, del portal web, de RH o del alta
pública, pasa por `normalizar()`: MIME real verificado al decodificar (JPG, PNG
o WEBP), orientación EXIF corregida, recorte cuadrado centrado y JPEG 800×800.
Se guarda en el NAS con nombre propio (nunca se sobrescribe la anterior) y
nunca se expone `foto_path`.

## Reglas de negocio

| Situación | Resultado |
| --- | --- |
| Sin foto | La primera que sube la persona queda **oficial** al instante. |
| Registro de app abierto (`RegistroAppService::aplica`) y ya tiene foto | **Reemplazo directo** (auditoría `foto_perfil_actualizada`, `origen: registro_app_reemplazo`). Es para que el nuevo ingreso corrija su foto antes de terminar. |
| Registro cerrado / colaborador activo con foto | La nueva queda como **propuesta pendiente** de RH (una a la vez). |
| RH desde el expediente | Cambio directo. |
| Alta pública (liga web) | Se normaliza al subirla (aviso inmediato si la imagen no sirve) y al convertir el alta se fija con `fijarDesdeContenido()` (auditoría `origen: alta_publica`). |

**Obligatoria para cerrar el registro de app**: `RegistroAppService::finalizar()`
responde 422 con `errors.foto = "Agrega tu fotografía para expediente."` si no
hay foto (regla de servicio, no solo de la app). `puede_finalizar` es `false`
mientras falte. El flag `config('incorporacion.registro_app.foto_obligatoria')`
(`REGISTRO_APP_FOTO_OBLIGATORIA`, por defecto `true`) existe **solo** para
apagarla si una versión de la app sin el paso «foto» sigue en uso: esa versión
no tiene cómo subirla dentro del asistente y la persona quedaría atorada. Hasta
publicar la app con el paso nuevo, valorar ponerlo en `false` en producción.

**Alta pública**: `AltaPublicaController::enviar()` exige la foto
(`errors.foto = "Agrega tu fotografía para expediente antes de enviar."`) y la
vista `AltaPublica/Wizard.vue` la presenta como «Fotografía para expediente
(obligatoria)» con la explicación (clara, formal, de frente, fondo claro) y
bloquea el botón de envío sin foto.

### Documento «2 fotografías tamaño infantil» (decisión)

El tipo documental requerido `fotografia` (DocumentTypeSeeder, `aplica_alta`)
**no se elimina**. En el registro de app se considera **cubierto en digital por
la foto de perfil**: no aparece en `documentos.lista`, no cuenta como faltante,
no se puede (ni hace falta) marcar «No tengo este documento» y no entra en la
carta compromiso del registro. Su lugar lo ocupa el bloque `foto`
(`foto.cubre_documento` dice cuál). Si RH pide las 2 fotografías físicas en
papel, siguen siendo entrega física. La clave se configura en
`incorporacion.registro_app.documento_cubierto_por_foto`. Con la foto
obligatoria apagada, el documento solo se da por cubierto cuando la foto ya
existe. Fuera del registro (checklists de RH, expediente) el tipo sigue igual;
`ChecklistAdministrativoService` ya marca «Fotografía cargada» con
`foto_path || alta.foto_path`.

## Quién ve la foto de otra persona

`FotoColaboradorService::puedeVerFoto($viewer, $colaborador)`:

- la propia;
- quien alcanza su expediente (alcance del módulo **Expedientes** en Roles y
  permisos: Todos, Región, Sucursal, Equipo);
- su jerarquía directa: su jefe directo y su gerente (o el jefe del jefe), y
  quienes le reportan (`jefe_id` / `gerente_id`).

Un compañero sin esa relación **no** la ve (en el directorio le llegan
iniciales, igual que en la web). La ruta vuelve a evaluar la regla en cada
petición: cambiar el id en la URL da 403 (sin IDOR). El contexto del visor se
calcula una vez por petición, así que los listados arman `foto_url` sin
consultas por fila (las filas deben traer `foto_path`, `sucursal_principal_id`,
`jefe_id`, `gerente_id`).

## Contrato API (Bearer Sanctum)

### Foto propia

| Método | Ruta | Uso |
| --- | --- | --- |
| `GET` | `/api/v1/colaborador/foto?v=…` | Binario JPEG de la foto propia. |
| `POST` | `/api/v1/colaborador/foto` (multipart `foto`) | Subir/tomar. 200 `resultado: oficial` o 202 `resultado: pendiente`. 422 `errors.foto` si no es imagen o ya hay propuesta. |
| `GET` | `/api/v1/colaborador/foto/estado` | `estado` (sin_foto, oficial, cambio_pendiente), `puede_subir_directo` (true sin foto **o con el registro abierto**), `pendiente`, `ultimo_cambio`. |
| `GET` | `/api/v1/colaborador/foto/propuesta` | Binario de la propuesta pendiente. |

Las cuatro están permitidas durante el registro (`ExigirRegistroApp`).

### Estado del registro: `GET /api/v1/colaborador/registro`

Bloque nuevo `data.foto`:

```json
{
  "obligatoria": true,
  "completo": false,
  "url": null,
  "pendiente_revision": false,
  "puede_reemplazar": true,
  "cubre_documento": { "id": 12, "nombre": "2 fotografías tamaño infantil B/N o Color" }
}
```

- `url`: la de `/api/v1/colaborador/foto?v=…` (cambia `v` con cada foto: sirve
  como clave de caché).
- `puede_finalizar` ya considera la foto; `pendientes` incluye
  `"fotografía para expediente"` cuando falta.
- `POST /api/v1/colaborador/registro/finalizar` sin foto → 422
  `errors.foto: ["Agrega tu fotografía para expediente."]` (si además faltan
  datos o documentos viene también `errors.registro`).

### Foto de otras personas

| Método | Ruta | Autorización |
| --- | --- | --- |
| `GET` | `/api/v1/colaboradores/{colaborador}/foto?v=…` (`api.v1.colaboradores.foto`) | `puedeVerFoto` (alcance de expedientes + jerarquía directa). 403 fuera; 404 sin foto. |
| `GET` | `/api/v1/rh/colaboradores/{colaborador}/foto?v=…` (`api.v1.rh.colaboradores.foto`) | Alcance de expediente (`puedeVerExpediente`). 403 fuera. Es la que usa `urlRhApi()` (revisión de cambios de foto, detalle RH). |

### `foto_url` en respuestas que listan personas

Siempre una URL absoluta que la app abre con su `Authorization: Bearer`, o
`null` (mostrar iniciales):

| Endpoint | Campo |
| --- | --- |
| `GET /api/v1/directorio` | `data[].foto_url` |
| `GET /api/v1/equipo` | `data[].foto_url` |
| `GET /api/v1/equipo/pendientes` | `solicitudes[].colaborador_foto_url`, `en_espera[].colaborador_foto_url`, `evaluaciones[].colaborador_foto_url` |
| `GET /api/v1/colaborador/jerarquia`, `GET /api/v1/rh/colaboradores/{id}/jerarquia` | `foto_url` en `colaborador`, `jefe_inmediato`, `gerente`, `subordinados_directos[]` |
| `GET /api/v1/rh/colaboradores` / `{id}` | `data[].foto_url` / `data.foto_url` |
| `GET /api/v1/rh/candidatos` / `{id}` | `data[].foto_url` / `data.candidato.foto_url` (foto del colaborador enlazado) |
| `GET /api/v1/rh/solicitudes` / `{id}` | `colaborador.foto_url` |
| `GET /api/v1/rh/bajas` / `{id}` | `data[].foto_url` / `data.foto_url` |
| `GET /api/v1/rh/incorporaciones` / `{id}` | `colaborador.foto_url` |
| `GET /api/v1/rh/colaboradores/{id}/ciclo` (ficha) | `data.foto_url` |

En la web, `auth.user.avatar` (HandleInertiaRequests) trae la URL de la foto
propia para el header/sidebar (`UserInfo.vue`).

## Plan de UI para la app (pendiente, la app está en pausa)

1. **Paso nuevo `foto` en `PASOS_REGISTRO`**, entre `emergencia` y
   `documentos`. Título «Fotografía para expediente»; texto: «Será tu foto de
   perfil. Tómala de frente, con el rostro descubierto, buena luz y fondo
   claro.» Mostrar `cubre_documento.nombre` como nota: «Con esta foto ya no
   necesitas subir las fotografías tamaño infantil.»
2. **Origen**: botón «Tomar foto» (cámara frontal por defecto,
   `expo-image-picker` `launchCameraAsync`) y «Elegir de la galería»
   (`launchImageLibraryAsync`).
3. **Recorte 1:1** en el picker (`allowsEditing: true, aspect: [1, 1]`) y vista
   previa circular antes de subir.
4. **Redimensionar a 1024 px** de lado (`expo-image-manipulator`, JPEG calidad
   ~0.85) antes de subir: el servidor normaliza a 800×800, así se ahorra datos.
5. **Subida** `POST /api/v1/colaborador/foto` multipart con la cabecera
   `X-People-Registro`. Al 200 refrescar `GET colaborador/registro` y mostrar
   la foto desde `foto.url` (con el Bearer). Barra de progreso y botón
   **Reintentar** ante error de red/timeout; ante 422 mostrar `errors.foto`.
6. **Reemplazo**: mientras `foto.puede_reemplazar`, ofrecer «Cambiar foto»
   (reemplazo directo). Después del registro, el cambio desde el perfil sigue
   el flujo de propuesta (202 → «Enviamos tu foto a RH»).
7. **Bloqueo**: el botón «Siguiente» del paso y «Terminar registro» se
   deshabilitan mientras `foto.completo` sea `false` (y `foto.obligatoria`); si
   el backend responde 422 `errors.foto` al finalizar, regresar al paso `foto`.
8. **Permisos**: pedir cámara y galería solo al tocar el botón; si se niegan,
   explicar y ofrecer «Abrir configuración» (`Linking.openSettings()`), y dejar
   la otra opción disponible. Declarar `NSCameraUsageDescription` /
   `NSPhotoLibraryUsageDescription` en español en `app.json`.
9. **Listados**: usar `foto_url` de cada respuesta con el header
   `Authorization` (caché por URL: `v` cambia con cada foto) y caer a iniciales
   si es `null` o si la imagen responde 403/404.
