<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Capturas y clips necesarios

Lista exacta de pantallas, en orden. Los PNG reales están en [capturas/](capturas/) y los produce `node scripts/guia/grabar-capturas.mjs` (solo local, datos ficticios de DemoSeeder). Si se graban clips en movimiento para una versión editada, se nombran igual con `.mp4` y siguen la acción indicada. Las escenas del colaborador se toman del portal web real en tamaño celular; para grabar la app nativa se usa un celular o emulador con la cuenta demo.

> **Privacidad:** nunca grabar contraseñas, tokens, correos reales, CURP/RFC/NSS reales, teléfonos, domicilios ni rutas del NAS. Solo el escenario de [DATOS_DEMO.md](../DATOS_DEMO.md), en local o testing.

| # | Archivo | Pantalla | Ruta | Qué debe verse |
|---|---|---|---|---|
| 01 | `00-portada.png` → `00-portada.mp4` | Portada | — | Portada con el logotipo y el título del video sobre fondo crema. |
| 02 | `01-login.png` → `01-login.mp4` | Inicio de sesión | `/login` | Escribir el usuario (nombre y primer apellido) y entrar. La contraseña se escribe fuera de cuadro. |
| 03 | `02-dashboard-rh.png` → `02-dashboard-rh.mp4` | Inicio (tablero de RH) | `/dashboard` | Recorrer los indicadores y el menú lateral agrupado por proceso. |
| 04 | `03-plantilla-sucursal.png` → `03-plantilla-sucursal.mp4` | Administración → Sucursales → detalle | `/administracion/sucursales/:sucursal` | Abrir la sucursal del gerente demo y mostrar la plantilla autorizada contra la ocupada del puesto Gestor. |
| 05 | `04-vacantes.png` → `04-vacantes.mp4` | Reclutamiento → Vacantes | `/rh/vacantes` | Mostrar el resumen por puesto y una fila «Gestor» con plaza disponible. |
| 06 | `05-campana-nueva.png` → `05-campana-nueva.mp4` | Reclutamiento → Campañas → Nueva campaña | `/rh/campanas` | Abrir «Nueva campaña», elegir una vacante de Gestor y llenar canal, fechas, gasto, sueldo publicado, copy y liga. |
| 07 | `06-campana-metricas.png` → `06-campana-metricas.mp4` | Campañas (tarjeta con embudo) | `/rh/campanas` | Mostrar la tarjeta de la campaña con su embudo y costos; abrir un adjunto PDF. |
| 08 | `07-candidato-nuevo.png` → `07-candidato-nuevo.mp4` | Reclutamiento → Candidatos → Nuevo candidato | `/rh/candidatos` | Capturar a «Juan Pérez Demo» (sin guardar): nombre, teléfono ficticio, vacante, campaña y fuente. |
| 09 | `08-candidatos-tablero.png` → `08-candidatos-tablero.mp4` | Candidatos (tablero) | `/rh/candidatos` | Mostrar las columnas del tablero y una tarjeta demo («Demo Perfil Ciclo») en Filtro RH. |
| 10 | `09-candidato-ficha.png` → `09-candidato-ficha.mp4` | Ficha del candidato | `/rh/candidatos/:candidato` | Mostrar los pasos, el responsable actual y el botón «Revisar perfil»; registrarlo como viable. |
| 11 | `10-entrevista.png` → `10-entrevista.mp4` | Ficha del candidato (vista del gerente) | `/rh/candidatos/:candidato` | Como gerente de sucursal, «Registrar entrevista»: fecha, observaciones y resultado viable. |
| 12 | `11-psicometricos.png` → `11-psicometricos.mp4` | Ficha → Registrar resultados | `/rh/candidatos/:candidato` | Registrar el resumen y subir un PDF ficticio; abrirlo en el visor; el gerente lo revisa en perfil. |
| 13 | `12-socioeconomico.png` → `12-socioeconomico.mp4` | Ficha → Registrar estudio socioeconómico | `/rh/candidatos/:candidato` | Capturar la visita: fecha, dirección ficticia, checklist, riesgos y una foto; reproducir un video de evidencia. |
| 14 | `13-referencias-preautorizacion.png` → `13-referencias-preautorizacion.mp4` | Ficha → Referencias y autorización de RH | `/rh/candidatos/:candidato` | Registrar una referencia, concluir, y mostrar «Autorizar contratación» de RH. |
| 15 | `14-intervencion.png` → `14-intervencion.mp4` | Ficha → Intervención | `/rh/candidatos/:candidato` | Mostrar un candidato rechazado por RH con la sección «Intervención sobre el rechazo de RH». |
| 16 | `15-generar-qr.png` → `15-generar-qr.mp4` | Ficha → Generar QR de contratación | `/rh/candidatos/:candidato` | Con el candidato autorizado, capturar sueldo, fecha de ingreso y modalidad; confirmar. |
| 17 | `16-invitacion-qr.png` → `16-invitacion-qr.mp4` | Invitación QR (detalle) | `/rh/incorporacion/invitaciones/:invitacion` | Mostrar el QR, su vencimiento, copiar liga y descargar PNG. |
| 18 | `17-app-registro.png` → `17-app-registro.mp4` | App: escanear QR y registro | `/incorporacion/qr/:token` | En el celular, escanear el QR, confirmar nombre, apellidos, teléfono y crear la contraseña. |
| 19 | `18-app-documentos.png` → `18-app-documentos.mp4` | App: Mi incorporación | — | Mostrar la lista de documentos del alta y subir uno; mostrar «Completa tu información». |
| 20 | `19-expediente.png` → `19-expediente.mp4` | Expedientes → expediente | `/rh/expedientes/:colaborador` | Mostrar la cabecera, el botón «Ciclo laboral» y las pestañas del expediente. |
| 21 | `20-expediente-faltantes.png` → `20-expediente-faltantes.mp4` | Expediente → datos faltantes | `/rh/expedientes/:colaborador` | Mostrar el semáforo por secciones y pulsar «Avisar al colaborador». |
| 22 | `21-contrato-inicial.png` → `21-contrato-inicial.mp4` | Ciclo laboral → documentos de contratación | `/rh/colaboradores/:colaborador/ciclo` | Pulsar «Generar paquete de contratación» y previsualizar el contrato. |
| 23 | `22-firma-original.png` → `22-firma-original.mp4` | Documentos del proceso → ruta del original | `/rh/colaboradores/:colaborador/ciclo` | Mostrar imprimir, registrar firma, enviar a corporativo, recepción, subir firmado y archivar. |
| 24 | `23-onboarding-config.png` → `23-onboarding-config.mp4` | Desarrollo → Onboarding | `/rh/onboarding/configuracion` | Mostrar las lecciones (institucional y por puesto) y el equipo de trabajo. |
| 25 | `24-onboarding-cierre.png` → `24-onboarding-cierre.mp4` | Ciclo laboral → onboarding | `/rh/colaboradores/:colaborador/ciclo` | Mostrar el avance de lecciones, la entrega de activos y «Cerrar onboarding». |
| 26 | `25-evaluaciones.png` → `25-evaluaciones.mp4` | Desarrollo → Evaluación de capacitación inicial | `/rh/evaluaciones` | Mostrar la lista con fechas límite; abrir «Evaluar periodo de prueba» y calificar los criterios. |
| 27 | `26-contrato-indeterminado.png` → `26-contrato-indeterminado.mp4` | Ciclo laboral → contrato indeterminado | `/rh/colaboradores/:colaborador/ciclo` | Mostrar «Renovación autorizada» y el contrato por tiempo indeterminado listo para previsualizar e imprimir. |
| 28 | `27-solicitudes.png` → `27-solicitudes.mp4` | Personal → Solicitudes | `/rh/solicitudes` | Mostrar los tipos, abrir el tablero de permisos y una solicitud con su cadena de autorización. |
| 29 | `28-nomina-lotes.png` → `28-nomina-lotes.mp4` | Personal → Recibos de nómina | `/rh/nomina/lotes` | Preparar un lote quincenal; abrirlo y recorrer recibos con Anterior y Siguiente. |
| 30 | `29-nomina-emitir.png` → `29-nomina-emitir.mp4` | Lote de nómina → Emitir | `/rh/nomina/lotes/:lote` | Pulsar «Emitir N recibos» e imprimir el PDF. |
| 31 | `30-avisos.png` → `30-avisos.mp4` | Personal → Avisos | `/rh/avisos` | Redactar un aviso para toda la empresa con imagen; mostrar Celebraciones. |
| 32 | `31-baja.png` → `31-baja.mp4` | Ciclo laboral → Iniciar baja | `/rh/colaboradores/:colaborador/ciclo` | Abrir «Iniciar baja», elegir la causa, el motivo y la fecha efectiva. |
| 33 | `32-finiquito.png` → `32-finiquito.mp4` | Solicitud de baja → Finiquito | `/rh/solicitudes/:solicitud` | Mostrar los conceptos del finiquito, ajustar uno con motivo y ver la vista previa. |
| 34 | `33-reingresos.png` → `33-reingresos.mp4` | Desarrollo → Reingresos | `/rh/reingresos` | Buscar a una persona dada de baja y mostrar su causa de salida. |
| 35 | `34-guia.png` → `34-guia.mp4` | Ayuda → Guía del sistema | `/ayuda` | Mostrar el centro de Guía: buscador, recorrido completo, procesos y roles. |
| 36 | `35-cierre.png` → `35-cierre.mp4` | Portada final | — | Portada final con el mensaje de cierre. |
