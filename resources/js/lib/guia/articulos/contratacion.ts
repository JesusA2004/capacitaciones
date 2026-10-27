import type { ArticuloGuia } from '../tipos';

/**
 * Procesos 2–5 — Contratación, Alta, Expediente y Contratos. Fuente real:
 *  - App\Services\Reclutamiento\ContratacionCandidatoService::iniciarContratacion()
 *  - App\Services\Incorporacion\IncorporacionInvitacionService (QR 1–24 h, un uso)
 *  - mr-lana-people-app: src/app/incorporacion/qr/[token].tsx, (app)/incorporacion.tsx, (app)/completar-datos.tsx
 *  - App\Services\Colaboradores\AltaColaboradorService (estado_alta, activar())
 *  - App\Services\Expedientes\DatosFaltantesService (secciones de completitud)
 *  - App\Services\Contratos\ContratoLaboralService (duración por puesto, sin respaldo global)
 *  - App\Services\DocumentosMaestros\DocumentoProcesoService (generar, flujo físico)
 */
export const ARTICULOS_CONTRATACION: ArticuloGuia[] = [
    {
        id: 'iniciar-contratacion',
        titulo: 'Iniciar la contratación (QR de contratación)',
        proceso: 'contratacion',
        resumen:
            'Con el candidato autorizado por RH, «Generar QR de contratación» crea a la persona en contratación, ocupa la plaza y entrega un QR de un solo uso.',
        queEs: 'Es el paso que convierte al candidato autorizado en una persona en contratación. Todo ocurre en un solo movimiento y sin duplicar a nadie.',
        quien: ['rh'],
        quienDetalle:
            'RH con permiso para contratar candidatos, dentro de su alcance.',
        antes: [
            'El candidato está «Autorizado por RH».',
            'Está vinculado a una vacante real (un espontáneo primero se vincula desde «Editar datos»).',
            'El puesto tiene configurada su duración de capacitación inicial (Configuración → Parámetros de RH).',
        ],
        pasos: [
            {
                titulo: 'Abre la ficha del candidato',
                detalle: 'Candidatos → candidato autorizado.',
                canal: 'web',
            },
            {
                titulo: '«Generar QR de contratación»',
                detalle:
                    'Captura sueldo mensual, fecha de ingreso, modalidad (capacitación inicial) y, si quieres, el fin del periodo. Vacío = según la duración del puesto.',
            },
            {
                titulo: 'Confirma',
                detalle:
                    'PEOPLE bloquea la vacante y revisa que la plaza siga libre ANTES de crear a la persona. Si dos personas intentan ocupar la última plaza al mismo tiempo, la segunda recibe «La vacante ya fue cubierta».',
            },
        ],
        despues: [
            'Se crea el colaborador con estatus «en incorporación», su número de empleado y su expediente, ligado al candidato (no son dos personas).',
            'Se crea su contrato de capacitación inicial con fecha de fin calculada por el puesto.',
            'La vacante cuenta la plaza como ocupada (si era la última, se cierra).',
            'El CV del candidato se copia a su expediente.',
            'Se genera el QR de registro: temporal (24 h por defecto, nunca más de 24 h), de un solo uso y revocable.',
            'El candidato pasa a «En contratación».',
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'La sucursal, el departamento y el puesto salen SIEMPRE de la vacante. Aunque alguien manipule el formulario, PEOPLE no contrata en otra plaza.',
            },
        ],
        errores: [
            {
                problema:
                    '«Este candidato es espontáneo: vincúlalo a una vacante disponible antes de iniciar su contratación.»',
                solucion:
                    'Abre «Editar datos», elige la vacante real y vuelve a intentarlo.',
            },
            {
                problema:
                    '«Configura la duración de capacitación inicial para este puesto…»',
                solucion:
                    'Gerencia de RH entra a Administración → Configuración → Parámetros de RH y captura los meses del puesto. No existe una duración genérica.',
            },
            {
                problema: '«La vacante ya fue cubierta.»',
                solucion:
                    'Alguien ocupó la última plaza antes. Vincula al candidato a otra vacante disponible.',
            },
            {
                problema:
                    '«Ya existe una cuenta con ese correo: si es un reingreso, búscalo en Reingresos.»',
                solucion:
                    'Es alguien que ya trabajó en MR. LANA: usa Reingresos para no duplicar a la persona.',
            },
        ],
        registro: [
            'Historial del candidato: «Inició la contratación: colaborador N creado y QR de registro generado».',
            'Bitácora: contratación iniciada con candidato, colaborador e invitación.',
        ],
        relacionados: [
            'qr-incorporacion',
            'registro-en-app',
            'candidato-a-colaborador',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Candidatos',
                ruta: '/rh/candidatos',
                permisos: ['candidatos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo contrato un candidato?',
            '¿Por qué no puedo contratar al candidato?',
            '¿Qué pasa si dos personas quieren la última plaza?',
        ],
        recorrido: 'contratar-candidato',
    },
    {
        id: 'qr-incorporacion',
        titulo: 'El QR de incorporación',
        proceso: 'contratacion',
        resumen:
            'No es solo un formulario: es la puerta de entrada de la persona. Al escanearlo crea su cuenta sobre el mismo colaborador que preparó RH.',
        queEs: 'Es un código temporal y de un solo uso, ligado al candidato y a su colaborador. Nadie puede registrarse en la app sin una invitación activa.',
        quien: ['rh', 'colaborador'],
        antes: ['La contratación ya se inició desde la ficha del candidato.'],
        pasos: [
            {
                titulo: 'Qué ve RH',
                detalle:
                    'En la ficha del candidato, el bloque «QR de contratación» con su estado y vencimiento, y «Ver / regenerar QR». En el detalle: copiar liga, descargar PNG, regenerar o revocar.',
                quien: 'rh',
            },
            {
                titulo: 'Qué recibe la persona',
                detalle:
                    'El QR impreso o la liga (por WhatsApp, por ejemplo). Al escanearlo, su celular abre la pantalla de incorporación de MR. LANA PEOPLE: si tiene la app, la abre; si no, ofrece «Descargar app».',
                quien: 'colaborador',
            },
            {
                titulo: 'Qué ve el gerente',
                detalle:
                    'El candidato en la fase «Contratación» del tablero y, más adelante, los contratos por imprimir en sus pendientes.',
                quien: 'gerente',
            },
            {
                titulo: 'Si venció o no se usó',
                detalle:
                    'Reclutamiento → Invitaciones QR → «Nueva invitación»: elige a la persona en contratación (solo aparecen quienes no tienen un QR vigente) y su vigencia de 1 a 24 horas.',
                quien: 'rh',
            },
        ],
        despues: [
            'Al usarse, la invitación queda «Usada» y no vuelve a funcionar.',
            'Un token inválido, vencido o revocado muestra un mensaje claro, nunca un error.',
        ],
        errores: [
            {
                problema: '«El QR ya no funciona.»',
                solucion:
                    'Venció (vive como máximo 24 h) o ya se usó. Regénéralo desde la invitación o crea una nueva.',
            },
            {
                problema: '«No encuentro a la persona en Nueva invitación.»',
                solucion:
                    'Solo aparecen personas en contratación sin un QR vigente y sin cuenta. Si su QR sigue vigente, compártelo de nuevo.',
            },
        ],
        registro: [
            'Cada invitación: quién la creó, vigencia, usos, quién la usó y cuándo. Nunca se guarda el código en claro.',
        ],
        relacionados: ['iniciar-contratacion', 'registro-en-app'],
        accesos: [
            {
                etiqueta: 'Ir a Invitaciones QR',
                ruta: '/rh/incorporacion/invitaciones',
                permisos: ['rh.incorporacion.invitaciones.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cuánto dura el QR?',
            '¿Cómo regenero un QR?',
            '¿Qué hago si el QR venció?',
        ],
    },
    {
        id: 'registro-en-app',
        titulo: 'Registro del colaborador en la app',
        proceso: 'alta',
        resumen:
            'La persona escanea el QR, confirma sus datos básicos y crea su contraseña. Después sube documentos y completa su información.',
        queEs: 'Es lo que hace la persona nueva desde su celular para crear su cuenta. El formulario es corto a propósito: lo laboral ya lo definió RH.',
        quien: ['colaborador'],
        antes: [
            'Tener el QR vigente y la app MR. LANA PEOPLE instalada (si no, la pantalla del QR ofrece descargarla).',
        ],
        pasos: [
            {
                titulo: 'Escanea el QR',
                detalle:
                    'Con la cámara del celular o desde «Escanear QR» en la pantalla de inicio de sesión de la app.',
                canal: 'app',
            },
            {
                titulo: 'Confirma tus datos',
                detalle:
                    'Nombre (obligatorio), apellidos, correo electrónico (opcional) y teléfono.',
                canal: 'app',
            },
            {
                titulo: 'Crea tu contraseña',
                detalle:
                    'Mínimo 8 caracteres y confírmala. Tu usuario se genera solo: primer nombre + primer apellido (por ejemplo «Juan Perez»).',
                canal: 'app',
            },
            {
                titulo: 'Sigue la hoja de ruta',
                detalle:
                    'La app muestra tres fases: Datos personales → Documentos → Revisión RH.',
                canal: 'app',
            },
        ],
        campos: [
            {
                grupo: 'Registro con el QR',
                campos: [
                    'Nombre',
                    'Apellidos',
                    'Correo electrónico (opcional)',
                    'Teléfono',
                    'Contraseña',
                    'Confirmar contraseña',
                ],
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'La persona NO captura empresa, sucursal, puesto, vacante, fecha de ingreso ni tipo de contrato: vienen del proceso de RH y de la vacante. Así no quedan a criterio del candidato.',
            },
        ],
        despues: [
            'Se crea su cuenta (rol colaborador) sobre el MISMO colaborador que preparó RH; la invitación queda usada.',
            'Su acceso es de incorporación: puede entrar a la app a subir documentos y completar datos, pero todavía no es colaborador activo.',
        ],
        errores: [
            {
                problema: '«El correo no coincide con la invitación.»',
                solucion: 'Usa el correo que registró RH o déjalo vacío.',
            },
            {
                problema: '«Ese correo ya está registrado.»',
                solucion:
                    'Pide a RH que revise: puede tratarse de un reingreso.',
            },
        ],
        registro: [
            'Invitación usada (quién y cuándo) y cuenta creada en la bitácora.',
        ],
        relacionados: [
            'documentos-de-alta',
            'completar-datos',
            'qr-incorporacion',
        ],
        accesos: [{ etiqueta: 'Descargar la app', ruta: '/app' }],
        preguntas: [
            '¿Cómo me registro con el QR?',
            '¿Cuál es mi usuario?',
            '¿Qué datos pide el registro?',
        ],
    },
    {
        id: 'documentos-de-alta',
        titulo: 'Documentos del alta y revisión de RH',
        proceso: 'alta',
        resumen:
            'La persona sube los 11 documentos obligatorios desde la app; RH los aprueba, rechaza o pide corrección.',
        queEs: 'Es la integración del expediente de la persona nueva. Solo se piden los documentos obligatorios del alta; los demás (contratos firmados, permisos…) nacen después.',
        quien: ['colaborador', 'rh'],
        antes: ['La persona ya creó su cuenta con el QR.'],
        pasos: [
            {
                titulo: 'Sube cada documento',
                detalle:
                    'App → «Mi incorporación»: toca cada documento pendiente y súbelo (foto o PDF).',
                quien: 'colaborador',
                canal: 'app',
            },
            {
                titulo: 'Revisa en el expediente',
                detalle:
                    'Expedientes → la persona → «Documentos»: abre cada archivo sin salir de PEOPLE y aprueba, rechaza (con motivo) o pide corrección.',
                quien: 'rh',
                canal: 'web_app',
            },
            {
                titulo: 'Aprovecha la lectura automática',
                detalle:
                    'De identificaciones como INE, CURP o constancia fiscal, PEOPLE puede proponer datos (nombre, CURP, RFC…). RH revisa y decide aplicarlos o ignorarlos.',
                quien: 'rh',
            },
        ],
        campos: [
            {
                grupo: 'Documentos obligatorios del alta',
                campos: [
                    'Solicitud de empleo (formato interno)',
                    '2 fotografías tamaño infantil',
                    'Acta de nacimiento',
                    'Identificación oficial',
                    'Número de Seguridad Social',
                    'CURP',
                    'Constancia de situación fiscal (RFC)',
                    'Comprobante de domicilio',
                    'Comprobante de estudios',
                    'Carta de recomendación 1',
                    'Carta de recomendación 2 (cada carta se sube como foto o PDF; si vienen juntas, un solo archivo marcando «trae también la otra carta»)',
                    'Datos bancarios (cuenta, tarjeta y CLABE)',
                ],
            },
        ],
        despues: [
            'Cada documento rechazado le llega a la persona con su motivo (notificación y push) para que lo vuelva a subir.',
            'Las dos cartas de recomendación se revisan por separado: puedes aprobar las dos, o aprobar una y rechazar la otra; solo la rechazada se vuelve a subir.',
            'Cuando el último obligatorio queda aprobado, RH recibe «Expediente completo» y el pendiente «Generar paquete de contratación».',
            'Un documento ya aprobado no se reemplaza directo: la persona solicita el cambio y RH lo autoriza.',
        ],
        errores: [
            {
                problema: '«El avance no sube.»',
                solucion:
                    'Solo cuentan los documentos APROBADOS; cargado o en revisión todavía no.',
            },
            {
                problema: '«No puedo volver a subir un documento aprobado.»',
                solucion:
                    'Toca «Solicitar cambio»: cuando RH lo autorice, podrás subir la nueva versión.',
            },
        ],
        notificaciones: [
            'Documento aprobado / rechazado → colaborador (notificación + push).',
            'Expediente completo → RH con alcance.',
        ],
        registro: [
            'Cada versión del documento con quién lo subió, quién lo revisó, cuándo y el motivo.',
        ],
        relacionados: ['completar-datos', 'contrato-inicial', 'expediente'],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué documentos pide el alta?',
            '¿Cómo apruebo un documento?',
            '¿Cómo rechazo un documento?',
            '¿Qué documentos faltan?',
        ],
    },
    {
        id: 'candidato-a-colaborador',
        titulo: 'Del candidato al colaborador (sin duplicar a nadie)',
        proceso: 'alta',
        resumen:
            'Candidato y colaborador son la misma persona: PEOPLE los liga desde la contratación y conserva todo el historial.',
        queEs: 'Es la regla con la que PEOPLE convierte a un candidato en colaborador. Nunca crea dos personas independientes.',
        quien: ['rh'],
        antes: ['La contratación se inició desde la ficha del candidato.'],
        pasos: [
            {
                titulo: 'Al generar el QR',
                detalle:
                    'Se crea el colaborador ligado al candidato: número de empleado, expediente, empresa, sucursal, puesto, contrato de capacitación inicial y ocupación de la plaza.',
            },
            {
                titulo: 'Al registrarse con el QR',
                detalle: 'Se crea su cuenta sobre ese mismo colaborador.',
            },
            {
                titulo: 'Al firmar los contratos',
                detalle:
                    'El candidato pasa a «Contratado» y empieza el onboarding.',
            },
            {
                titulo: 'Al cerrar el onboarding',
                detalle:
                    'El colaborador queda ACTIVO (alta real) y se avisa a RH y Sistemas.',
            },
        ],
        despues: [
            'La ficha del candidato y el expediente del colaborador apuntan uno al otro: el reclutamiento queda como antecedente consultable.',
            'Su actividad laboral empieza con el movimiento «Alta».',
        ],
        errores: [
            {
                problema: '«La CURP/RFC/NSS ya pertenece a otra persona.»',
                solucion:
                    'PEOPLE impide duplicados. Si esa persona ya trabajó aquí, usa Reingresos.',
            },
        ],
        registro: [
            'Ciclo laboral de la persona: cada etapa con fecha y responsable.',
        ],
        relacionados: ['alta-activa', 'reingreso'],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué se crea al contratar?',
            '¿El candidato y el colaborador son la misma persona?',
        ],
    },
    {
        id: 'alta-activa',
        titulo: 'Alta real: colaborador activo',
        proceso: 'alta',
        resumen:
            'La persona queda activa cuando su expediente está aprobado, sus contratos firmados y su onboarding cerrado. Entonces se avisa a RH y a Sistemas.',
        queEs: 'Es el momento en que la persona deja de estar «en incorporación» y empieza a operar. PEOPLE lo hace al cerrar el onboarding.',
        quien: ['rh', 'sistemas'],
        antes: [
            'Expediente con documentos obligatorios aprobados.',
            'Contratos de contratación firmados.',
            'Onboarding completado (lecciones aprobadas y equipo entregado).',
        ],
        pasos: [
            {
                titulo: 'RH cierra el onboarding',
                detalle:
                    'Ciclo laboral de la persona → «Cerrar onboarding». PEOPLE la activa en ese mismo paso.',
                quien: 'rh',
            },
            {
                titulo: 'Sistemas prepara accesos',
                detalle:
                    'Recibe «Alta: Nombre (Puesto, Sucursal). Prepara su cuenta, accesos y equipo.»',
                quien: 'sistemas',
            },
        ],
        despues: [
            'El colaborador recibe «¡Bienvenido a MR. LANA! Ya tienes acceso completo a tu portal.»',
            'Se recalcula la vacante de su sucursal y puesto.',
        ],
        notificaciones: [
            'RH: documentación y seguimiento.',
            'Sistemas: cuentas, accesos y equipo.',
            'Colaborador: bienvenida.',
        ],
        errores: [
            {
                problema: '«No se puede activar todavía: está en …»',
                solucion:
                    'Falta algo del checklist: documentos, firma de contratos u onboarding. El ciclo laboral muestra qué y a quién le toca.',
            },
        ],
        registro: ['Bitácora «colaborador activado» y actividad laboral.'],
        relacionados: ['onboarding', 'usuarios-y-roles'],
        accesos: [
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/rh/pendientes',
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cuándo queda activo un colaborador?',
            '¿A quién avisa PEOPLE de un alta?',
            '¿Por qué Sistemas recibe altas?',
        ],
    },
    {
        id: 'expediente',
        titulo: 'El expediente del colaborador',
        proceso: 'expediente',
        resumen:
            'La carpeta digital de la persona, organizada en pestañas. Nada se borra nunca.',
        queEs: 'Es todo lo que RH guarda de una persona: datos, documentos, cuenta, onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial.',
        quien: ['rh', 'gerente', 'colaborador'],
        quienDetalle:
            'RH ve todos los expedientes; gerencias, los de su sucursal; el colaborador (incluidas las coordinadoras), solo el suyo (Mi expediente).',
        antes: [
            'Tener permiso de expedientes (RH o gerencia) o ser el propio colaborador.',
        ],
        pasos: [
            {
                titulo: 'Busca a la persona',
                detalle: 'Expedientes → busca por nombre o número de empleado.',
                canal: 'web',
            },
            {
                titulo: 'Revisa las pestañas',
                detalle:
                    'Resumen · Datos personales · Datos laborales · Cuenta · Documentos · Onboarding · Avisos / Comunicaciones · Vacaciones · Recibos de nómina · Préstamos · Solicitudes · Historial RH.',
            },
            {
                titulo: 'Documentos del proceso',
                detalle:
                    'Debajo están los documentos generados por PEOPLE (contratos, permisos, préstamo, baja) con su estado de firma y del original físico.',
            },
        ],
        despues: [
            'El colaborador ve su propio expediente en Mi expediente (sin la pestaña Cuenta ni Checklist).',
        ],
        errores: [
            {
                problema: '«Quiero corregir mi propio expediente y no puedo.»',
                solucion:
                    'Nadie edita su propio expediente, ni RH. Usa «Solicitar corrección»: otra persona de RH lo autoriza.',
            },
            {
                problema: '«Di de baja a alguien y no desaparece.»',
                solucion:
                    'Es correcto: la baja bloquea el acceso y conserva todo el historial para un posible reingreso.',
            },
        ],
        registro: [
            'Historial RH: cada cambio con quién, cuándo y valores anteriores.',
        ],
        relacionados: [
            'completitud-expediente',
            'completar-datos',
            'actividad-laboral',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Mi expediente',
                ruta: '/mi-expediente',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Qué contiene el expediente?',
            '¿Cómo corrijo un expediente?',
            '¿Dónde veo los documentos de un colaborador?',
        ],
        recorrido: 'revisar-expediente',
    },
    {
        id: 'completitud-expediente',
        titulo: 'Qué le falta a un expediente (y cómo avisar)',
        proceso: 'expediente',
        resumen:
            'El expediente no muestra solo un porcentaje: dice qué sección está completa y cuál no, y RH puede avisarle al colaborador.',
        queEs: 'Es el semáforo por secciones del expediente: Identidad, Fiscal, Laboral, Domicilio, Contacto, Contacto de emergencia, Beneficiario y Documentos.',
        quien: ['rh', 'colaborador'],
        antes: ['Abrir el expediente de la persona.'],
        pasos: [
            {
                titulo: 'Lee el aviso de datos faltantes',
                detalle:
                    'Por ejemplo: Identidad ✓ · Fiscal ✓ · Laboral ✓ · Domicilio ⚠ (falta el código postal) · Contacto de emergencia ✗ · Documentos ✓.',
                quien: 'rh',
            },
            {
                titulo: '«Avisar al colaborador»',
                detalle:
                    'Le llega una notificación y un push con la lista de lo que falta. Se puede repetir una vez cada 24 horas.',
                quien: 'rh',
            },
            {
                titulo: 'El colaborador completa',
                detalle:
                    'App → Inicio → «Completa tu información» → envía los datos a RH.',
                quien: 'colaborador',
                canal: 'app',
            },
        ],
        campos: [
            {
                grupo: 'Identidad',
                campos: ['Sexo', 'Fecha de nacimiento', 'Estado civil', 'CURP'],
            },
            {
                grupo: 'Fiscal',
                campos: ['RFC', 'Número de seguridad social (NSS)'],
            },
            {
                grupo: 'Laboral (lo captura RH)',
                campos: [
                    'Puesto',
                    'Sucursal',
                    'Fecha de ingreso',
                    'Sueldo',
                    'Tipo de contrato',
                ],
            },
            { grupo: 'Domicilio', campos: ['Domicilio', 'Código postal'] },
            {
                grupo: 'Contacto',
                campos: ['Teléfono celular', 'Correo electrónico'],
            },
            {
                grupo: 'Contacto de emergencia',
                campos: ['Nombre', 'Parentesco', 'Teléfono'],
            },
            { grupo: 'Beneficiario', campos: ['Nombre', 'Parentesco'] },
        ],
        despues: [
            'Cuando RH autoriza los datos propuestos, el semáforo se recalcula solo.',
        ],
        errores: [
            {
                problema: '«El botón Avisar está deshabilitado.»',
                solucion:
                    'Ya se avisó en las últimas 24 horas, o la persona ya envió una solicitud que está en revisión.',
            },
        ],
        notificaciones: [
            'Aviso de datos faltantes → colaborador (notificación + push).',
        ],
        registro: [
            'Último aviso enviado (fecha y quién) en el propio expediente.',
        ],
        relacionados: ['completar-datos', 'contrato-inicial'],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué hago si falta un dato?',
            '¿Cómo le aviso a un colaborador que complete sus datos?',
            '¿Qué significa domicilio incompleto?',
        ],
        recorrido: 'revisar-expediente',
    },
    {
        id: 'completar-datos',
        titulo: 'Completar o corregir datos (colaborador)',
        proceso: 'expediente',
        resumen:
            'El colaborador propone sus datos; RH revisa y autoriza; el expediente se actualiza solo.',
        queEs: 'Es una solicitud de «Actualización de datos». El colaborador nunca edita su expediente directo: propone y RH decide.',
        quien: ['colaborador', 'rh'],
        antes: ['Tener cuenta en la app o en el portal.'],
        pasos: [
            {
                titulo: 'Abre «Completa tu información»',
                detalle:
                    'App → Inicio → «Completa tu información» (o en la web, Mi expediente → «Solicitar corrección»).',
                quien: 'colaborador',
                canal: 'web_app',
            },
            {
                titulo: 'Captura lo que falta',
                detalle:
                    'Solo aparecen los datos que tu contrato pide y no tienes.',
                quien: 'colaborador',
            },
            {
                titulo: '«Enviar a RH»',
                detalle: 'Verás «Enviado a Recursos Humanos».',
                quien: 'colaborador',
            },
            {
                titulo: 'RH revisa',
                detalle:
                    'Solicitudes → la solicitud muestra el dato, el valor actual y el valor propuesto. RH autoriza o rechaza.',
                quien: 'rh',
            },
        ],
        despues: [
            'Al autorizar, los datos pasan solos al expediente y la completitud se recalcula.',
        ],
        errores: [
            {
                problema: '«Ya envié mis datos y no cambian.»',
                solucion:
                    'Espera la autorización de RH; puedes seguirla en Mis solicitudes.',
            },
        ],
        registro: [
            'Solicitud con valores anteriores y nuevos, quién autorizó y cuándo.',
        ],
        relacionados: ['completitud-expediente', 'revisar-solicitudes'],
        accesos: [
            {
                etiqueta: 'Ir a Mi expediente',
                ruta: '/mi-expediente',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo completo mis datos?',
            '¿Cómo corrijo mi expediente?',
            '¿Por qué no puedo editar mis datos?',
        ],
    },
    {
        id: 'contrato-inicial',
        titulo: 'Contrato de capacitación inicial',
        proceso: 'contratos',
        resumen:
            'PEOPLE llena el contrato con los datos del expediente; la duración la define el puesto. Si falta un dato, no genera nada a medias.',
        queEs: 'Es el primer contrato de la persona (capacitación inicial = periodo de prueba), acompañado del contrato de confidencialidad y el de no competencia.',
        quien: ['rh'],
        antes: [
            'Expediente con los documentos obligatorios aprobados (RH recibe el pendiente «Generar paquete de contratación»).',
            'Los formatos de Jurídico cargados en Documentos maestros.',
            'La duración del puesto configurada (por ejemplo, Gestor 2 meses; Gerente y Regional 3 meses).',
        ],
        pasos: [
            {
                titulo: 'Abre la ficha del colaborador',
                detalle:
                    'Expedientes → la persona (o su Ciclo laboral) → sección de documentos de Contratación.',
                canal: 'web',
            },
            {
                titulo: '«Generar paquete de contratación»',
                detalle:
                    'PEOPLE llena cada formato con nombre, CURP, RFC, NSS, domicilio, empresa, sucursal, puesto, sueldo, fechas, contacto de emergencia, beneficiario y demás datos del expediente.',
            },
            {
                titulo: 'Revisa',
                detalle:
                    'Previsualiza el PDF. Si cambió un dato, «Regenerar con datos actuales». También puedes «Descargar Word».',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'No hay una duración genérica: si el puesto no tiene meses de capacitación configurados, PEOPLE bloquea la contratación con un mensaje claro. Se configura en Configuración → Parámetros de RH.',
            },
            {
                tono: 'aviso',
                texto: 'Si el formato pide un dato que el expediente no tiene, PEOPLE responde «Faltan N dato(s) requerido(s)» con la lista exacta y NO genera el documento.',
            },
        ],
        documentos: [
            'Contrato de capacitación inicial',
            'Contrato de confidencialidad',
            'Contrato de no competencia',
        ],
        despues: [
            'Los contratos quedan listos para imprimir y firmar; el gerente recibe el pendiente.',
        ],
        errores: [
            {
                problema: '«¿Por qué el contrato no se genera?»',
                solucion:
                    'Falta un dato del expediente (te dice cuál) o falta el formato oficial en Documentos maestros.',
            },
            {
                problema: '«Falta el formato oficial.»',
                solucion:
                    'Gerencia de RH carga el original de Jurídico en Documentos maestros y lo activa.',
            },
        ],
        notificaciones: ['«Contratos listos» a quien opera los documentos.'],
        registro: [
            'Documento generado con versión del formato, datos usados, quién y cuándo.',
        ],
        relacionados: [
            'firma-y-original',
            'documentos-maestros',
            'estructura-y-plantilla',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Por qué el contrato no se genera?',
            '¿Cuánto dura la capacitación inicial?',
            '¿Cómo genero el contrato?',
        ],
    },
    {
        id: 'firma-y-original',
        titulo: 'Firma de contratos y control del original',
        proceso: 'contratos',
        resumen:
            'Cada documento sigue su ruta: imprimir → firma (y huella) → envío a corporativo → recepción → subir firmado → archivar.',
        queEs: 'Es el control del papel. PEOPLE sabe en todo momento dónde está el original firmado de cada contrato.',
        quien: ['gerente', 'rh'],
        quienDetalle:
            'El gerente imprime, recaba firma y huella y envía; RH recibe, escanea y archiva.',
        antes: ['El documento está generado.'],
        pasos: [
            {
                titulo: '«Imprimir»',
                detalle: 'Se marca como impreso.',
                quien: 'gerente',
            },
            {
                titulo: '«Registrar firma»',
                detalle:
                    'Fecha de firma con huella y testigos cuando el formato lo pide.',
                quien: 'gerente',
            },
            {
                titulo: '«Enviar original a corporativo»',
                detalle: 'Paquetería, guía, fecha y comprobante.',
                quien: 'gerente',
            },
            {
                titulo: '«Registrar recepción en corporativo»',
                detalle: 'Fecha de recepción.',
                quien: 'rh',
            },
            {
                titulo: '«Subir documento firmado»',
                detalle: 'El escaneo queda en el expediente.',
                quien: 'rh',
            },
            {
                titulo: '«Archivar»',
                detalle: 'Cierra la ruta del original.',
                quien: 'rh',
            },
        ],
        despues: [
            'Con el paquete firmado, el candidato pasa a «Contratado» y empieza el onboarding.',
            'Las fechas futuras se rechazan: cada paso pide su dato real.',
        ],
        errores: [
            {
                problema: '«No me deja archivar.»',
                solucion: 'Primero sube el documento firmado (escaneo).',
            },
        ],
        registro: ['Cada paso del original con fecha, quién y evidencia.'],
        relacionados: ['contrato-inicial', 'onboarding'],
        accesos: [
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/rh/pendientes',
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo registro la firma del contrato?',
            '¿Dónde está el original del contrato?',
            '¿Qué documentos tengo que imprimir?',
        ],
    },
];
