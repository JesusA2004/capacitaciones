# Carpeta `claude/`

Esta carpeta es el área de trabajo local para los **formatos oficiales** que RH y Legal
entregan al proyecto (contratos, avisos de privacidad, cartas, formatos de vacaciones,
permisos, incapacidades, altas, bajas, resguardos, acuses, etc.).

No es parte del código de la aplicación: es el punto de entrada humano para que RH
suba las plantillas base, y para documentar cómo el sistema las usa.

## Contenido

- `formatos/` — plantillas oficiales (originales, ejemplos y documentación de
  placeholders). Ver `formatos/README.md`.
- `instrucciones/FORMATO_PLANTILLAS.md` — cómo preparar un documento Word para que el
  sistema pueda precargarlo automáticamente con datos del colaborador/candidato/solicitud.

## Relación con el módulo de plantillas del sistema

Los archivos que RH coloca en `formatos/originales/` son la fuente para registrar
plantillas en el módulo **Plantillas y formatos** (`/rh/plantillas`). Una vez subida a
ese módulo, la plantilla se guarda en el disco `nas` (Synology) — nunca en Git ni en la
base de datos — y queda disponible para generar documentos precargados.

Ver `docs/PLANTILLAS_FORMATOS.md` para el detalle técnico del módulo.
