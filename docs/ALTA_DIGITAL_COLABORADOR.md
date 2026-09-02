# Alta digital de colaborador

Tablas `altas_digitales` y `alta_digital_documentos` — ver migraciones
`2026_09_02_090000_create_altas_digitales_table` y
`2026_09_02_090001_create_alta_digital_documentos_table`.

## Flujo

1. RH genera un alta digital desde un candidato en `aprobado_rh` (botón "Generar alta
   digital" en `/rh/candidatos/{candidato}`) o crea un preregistro manual desde
   `/rh/altas`.
2. RH copia la liga (`/rh/altas/{alta}`) y la envía al candidato (botón
   "Enviar/Reenviar liga"). Esto genera/renueva el `token` y su `token_expira_en`
   (`config('altas.token_dias_vigencia')`, 7 días por defecto).
3. El candidato abre `GET /alta/{token}` (**pública, sin sesión**) y completa el wizard
   de 5 pasos: datos personales, datos laborales precargados (solo lectura), documentos
   requeridos (`document_types` con `aplica_alta = true`), aviso de privacidad y
   consentimiento (con firma simple en canvas), y envío final.
4. RH revisa (`en_revision_rh` / `requiere_correccion` con comentarios) y aprueba o
   rechaza.
5. Al aprobar, `App\Services\AltaDigital\ConversionColaboradorService::convertir()`:
   crea el `User` (colaborador), copia los documentos capturados a `EmployeeDocument`
   (expediente), marca el candidato de origen como `contratado`, marca la vacante de
   origen como `cubierta` (si no estaba ya cerrada), y envía el correo de
   establecimiento de contraseña (mismo mecanismo que
   `Administracion\UsuarioController::store`).

## Seguridad de la liga pública

- El token es una cadena aleatoria de 48 caracteres (`Str::random(48)`), nunca el ID
  del registro.
- La ruta pública usa binding explícito `{alta:token}` (no el ID autoincremental).
- Con token expirado: `410 Gone`. Con alta ya cerrada (aprobada/rechazada/cancelada) y
  fuera de las ventanas de captura: `403`.
- Ningún dato de otro candidato es alcanzable desde una liga: cada token resuelve
  exactamente a un registro.
- El candidato **no tiene cuenta ni contraseña** en esta fase; toda su interacción es a
  través de la liga.

## Archivos: foto, firma y documentos

Igual criterio que expedientes y CVs de reclutamiento: la base de datos solo guarda
metadatos (`disk`, `path`, nombre original); el archivo vive en el disco `nas`
(`config('altas.disk')`), gestionado exclusivamente por
`App\Services\AltaDigital\AltaDigitalStorageService`. `AltaDigital` oculta
`foto_disk/foto_path/firma_disk/firma_path` en su serialización y expone en su lugar
`tiene_foto`/`tiene_firma`; la descarga pasa por rutas protegidas
(`rh.altas.foto`, `rh.altas.firma`, `rh.altas.documentos.descargar`), nunca por URL
directa al NAS.

La firma simple se captura con un `<canvas>` en el navegador (sin librería externa) y
se envía como PNG en base64; el backend la decodifica y la guarda como archivo — no se
persiste base64 en la base de datos.

## Estados

`creada, enviada, en_captura, enviada_por_candidato, en_revision_rh,
requiere_correccion, aprobada, rechazada, convertida_a_colaborador, cancelada`
(enum `App\Enums\EstadoAltaDigital`). `permiteCaptura()` determina si la liga pública
sigue aceptando datos (`enviada`, `en_captura`, `requiere_correccion`).

## Permisos

`altas.ver`, `altas.crear`, `altas.enviar`, `altas.revisar`, `altas.aprobar`,
`altas.cancelar` (ya sembrados desde el Portal RH original). Solo `rh_admin` los tiene
todos; `rh_auxiliar` solo `ver`/`crear`/`enviar` — la aprobación final siempre la hace
un rol con `altas.aprobar`.

## Relación con otros módulos

- **Candidatos** (`docs/RECLUTAMIENTO.md`): el botón "Generar alta digital" solo
  aparece cuando `candidato.estado === 'aprobado_rh'`.
- **Vacantes** (`docs/VACANTES.md`): si el alta tiene `vacante_id`, aprobarla marca la
  vacante como `cubierta`.
- **Expedientes** (`docs/EXPEDIENTES_DIGITALES.md`): los documentos capturados en la
  liga se convierten en `EmployeeDocument` del colaborador nuevo al aprobar.
- **Onboarding administrativo** (`docs/ONBOARDING_ADMINISTRATIVO.md`): el checklist de
  incorporación se calcula, entre otras cosas, a partir de si el alta está aprobada y
  si el expediente resultante está completo.
