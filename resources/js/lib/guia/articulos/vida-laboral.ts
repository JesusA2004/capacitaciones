import type { ArticuloGuia } from '../tipos';

/**
 * Procesos 8–11 — Solicitudes, Nómina, Avisos y Vida laboral. Fuente real:
 *  - App\Enums\TipoSolicitudInterna::enCatalogo() (permiso, vacaciones, préstamo)
 *  - App\Services\Solicitudes\SolicitudesService::camposPermiso() / tiposConFormulario()
 *  - App\Services\Solicitudes\AprobacionJerarquicaService (gerente → regional → RH)
 *  - App\Services\Nomina\PrestamoAutorizacionService / PrestamoService
 *  - App\Services\Nomina\LoteNominaService (preparar → revisar → emitir)
 *  - App\Services\Avisos\AvisoService (toda la empresa / un colaborador)
 *  - routes/console.php (felicitaciones automáticas 08:00)
 *  - App\Enums\TipoMovimientoLaboral (historial)
 */
export const ARTICULOS_VIDA_LABORAL: ArticuloGuia[] = [
    {
        id: 'permisos',
        titulo: 'Pedir y autorizar un permiso',
        proceso: 'solicitudes',
        resumen:
            'Faltar, salir temprano o llegar tarde, con o sin goce o especial. Gerente y regional dan visto bueno; RH autoriza y el formato queda listo para imprimir.',
        queEs: 'Es la solicitud de permiso del colaborador. Usa el Formato de permiso oficial de MR. LANA, que PEOPLE llena cuando RH lo autoriza.',
        quien: ['colaborador', 'gerente', 'regional', 'rh'],
        antes: ['Tener cuenta en la app o en el portal.'],
        pasos: [
            {
                titulo: 'Elige el permiso',
                detalle:
                    'Mis solicitudes → «Nueva solicitud» → Permiso. Elige: permiso para faltar, para salir temprano o para llegar tarde.',
                quien: 'colaborador',
                canal: 'web_app',
            },
            {
                titulo: 'Llena el formulario',
                detalle:
                    'Fecha del permiso; días (si vas a faltar), hora de salida o de entrada; tipo: con goce de sueldo, sin goce de sueldo o permiso especial. Si es especial, la causal: paternidad, luto, lactancia, cumpleaños o productividad. Observaciones y evidencia (opcional).',
                quien: 'colaborador',
            },
            {
                titulo: 'Visto bueno del gerente',
                detalle:
                    'El gerente de tu sucursal (o tu jefe directo si no hay gerente).',
                quien: 'gerente',
            },
            {
                titulo: 'Visto bueno del regional',
                detalle: 'El regional de tu región.',
                quien: 'regional',
            },
            {
                titulo: 'RH autoriza',
                detalle:
                    'Con ambos vistos buenos la solicitud pasa sola a «Pendiente de autorizar». RH la aprueba o rechaza.',
                quien: 'rh',
            },
            {
                titulo: 'Documento listo',
                detalle:
                    'Ya autorizado, el formato de permiso se genera para imprimir y firmar (sección «Formato de permiso» de la solicitud).',
                quien: 'gerente',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'El formato de permiso solo se puede imprimir cuando RH ya lo autorizó. Antes de eso el gerente no puede imprimirlo.',
            },
            {
                tono: 'info',
                texto: 'Quien da un visto bueno no puede dar la autorización final. Un nivel sin persona asignada se salta (queda registrado) y, si no hay ninguno, la solicitud va directo a RH.',
            },
        ],
        campos: [
            {
                grupo: 'Permiso',
                campos: [
                    'Permiso solicitado (faltar / salir temprano / llegar tarde)',
                    'Fecha del permiso',
                    'Días (al faltar)',
                    'Hora de salida o de entrada',
                    'Tipo de permiso (con goce / sin goce / especial)',
                    'Causal del permiso especial',
                    'Observaciones',
                    'Evidencia (opcional)',
                ],
            },
        ],
        despues: [
            'El colaborador ve el estado en su app; el permiso queda en la pestaña Solicitudes de su expediente.',
        ],
        notificaciones: [
            'Cada cambio de estado → colaborador (notificación + push).',
            'Solicitud nueva → quien debe dar el visto bueno.',
        ],
        documentos: ['Formato de permiso MR. LANA (al autorizar).'],
        errores: [
            {
                problema: '¿Por qué no puedo imprimir un permiso?',
                solucion:
                    'Todavía no lo autoriza RH, o falta el formato oficial cargado en Documentos maestros.',
            },
            {
                problema: '«Mi solicitud sigue en Recibida.»',
                solucion:
                    'Falta el visto bueno del gerente o del regional; la «Cadena de autorización» del detalle dice cuál.',
            },
        ],
        registro: [
            'Historial de la solicitud: cada visto bueno, autorización, corrección o rechazo con fecha y quién.',
        ],
        relacionados: ['revisar-solicitudes', 'vacaciones'],
        accesos: [
            {
                etiqueta: 'Ir a Mis solicitudes',
                ruta: '/solicitudes',
                permisos: ['portal.solicitudes.ver', 'solicitudes.crear'],
                modo: 'colaborador',
            },
            {
                etiqueta: 'Ir a Solicitudes (RH)',
                ruta: '/rh/solicitudes',
                permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo genero un permiso?',
            '¿Por qué no puedo imprimir un permiso?',
            '¿Qué es un permiso especial?',
            '¿Cómo pido permiso para llegar tarde?',
        ],
        recorrido: 'autorizar-permiso',
    },
    {
        id: 'vacaciones',
        titulo: 'Vacaciones',
        proceso: 'solicitudes',
        resumen:
            'Eliges los días en el calendario; el domingo no cuenta y el sábado sí. Ves tu saldo antes y después de pedir.',
        queEs: 'Es la solicitud de días de vacaciones del saldo que te corresponde.',
        quien: ['colaborador', 'gerente', 'regional', 'rh'],
        antes: [
            'Tener saldo de vacaciones (lo ves en Mi portal o en la pestaña Vacaciones de la app).',
        ],
        pasos: [
            {
                titulo: 'Elige los días',
                detalle:
                    'Nueva solicitud → Vacaciones (o app → Vacaciones). Toca cada día en el calendario (hasta 60). El domingo no se puede elegir; el sábado sí cuenta.',
                quien: 'colaborador',
                canal: 'web_app',
            },
            {
                titulo: 'Revisa tu saldo',
                detalle:
                    'Arriba ves: saldo antes → días elegidos → saldo después («Te quedarían N días»).',
                quien: 'colaborador',
            },
            {
                titulo: 'Vistos buenos y autorización',
                detalle:
                    'Igual que un permiso: gerente → regional → RH autoriza.',
            },
        ],
        documentos: ['Formato de vacaciones (al autorizar, para firma).'],
        despues: ['Al autorizar se descuentan los días del saldo.'],
        errores: [
            {
                problema: '«No me deja elegir el domingo.»',
                solucion:
                    'Es correcto: el domingo no cuenta como día de vacaciones.',
            },
            {
                problema: '«El saldo después sale en rojo.»',
                solucion: 'Estás pidiendo más días de los que tienes.',
            },
        ],
        registro: ['Días solicitados y autorizados, y movimientos del saldo.'],
        relacionados: ['permisos', 'revisar-solicitudes'],
        accesos: [
            {
                etiqueta: 'Ir a Mis solicitudes',
                ruta: '/solicitudes',
                permisos: ['portal.solicitudes.ver', 'solicitudes.crear'],
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo pido vacaciones?',
            '¿Cuántos días de vacaciones tengo?',
            '¿El sábado cuenta como vacaciones?',
        ],
    },
    {
        id: 'prestamos',
        titulo: 'Préstamos personales',
        proceso: 'solicitudes',
        resumen:
            'El colaborador pide monto y motivo; RH autoriza monto y plazo; se generan contrato, pagaré y carta de consentimiento; RH confirma la entrega.',
        queEs: 'Es el préstamo interno al colaborador. PEOPLE lleva su saldo de forma administrativa; NO aplica descuentos en la nómina ni se conecta con el sistema de nómina.',
        quien: ['colaborador', 'gerente', 'regional', 'rh'],
        antes: ['Tener cuenta en la app o en el portal.'],
        pasos: [
            {
                titulo: 'Solicita',
                detalle:
                    'App → Préstamos → «Solicitar»: monto y motivo. (En el portal web también puedes proponer el plazo.)',
                quien: 'colaborador',
                canal: 'web_app',
            },
            { titulo: 'Vistos buenos', detalle: 'Gerente → regional.' },
            {
                titulo: 'RH autoriza',
                detalle:
                    'Monto autorizado, plazo, periodicidad y pago programado (o rechaza).',
                quien: 'rh',
            },
            {
                titulo: '«Generar documentos del préstamo»',
                detalle:
                    'Carta de consentimiento y retención, contrato de préstamo y pagaré, llenados con los datos del expediente. Se imprimen y firman.',
                quien: 'rh',
            },
            {
                titulo: 'Confirmar entrega',
                detalle:
                    'En el expediente → Préstamos: fecha de otorgamiento, fecha del primer descuento, periodicidad y pago programado. Desde ahí cuenta el saldo.',
                quien: 'rh',
            },
            {
                titulo: 'Registrar pagos',
                detalle:
                    'Cada pago se registra en el préstamo y el saldo se actualiza.',
                quien: 'rh',
            },
        ],
        documentos: [
            'Carta de consentimiento y retención',
            'Contrato de préstamo personal',
            'Pagaré',
        ],
        despues: [
            'El colaborador ve su préstamo, saldo y movimientos en la app (Préstamos).',
        ],
        errores: [
            {
                problema: '«El saldo no baja solo.»',
                solucion:
                    'PEOPLE no descuenta de nómina: RH registra cada pago.',
            },
        ],
        registro: [
            'Movimientos del préstamo (entrega, pagos) con quién y cuándo; nunca se edita el saldo a mano.',
        ],
        relacionados: ['revisar-solicitudes', 'expediente'],
        accesos: [
            {
                etiqueta: 'Ir a Mis solicitudes',
                ruta: '/solicitudes',
                permisos: ['portal.solicitudes.ver', 'solicitudes.crear'],
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo pido un préstamo?',
            '¿Qué documentos firma un préstamo?',
            '¿Cómo registro un pago de préstamo?',
        ],
    },
    {
        id: 'revisar-solicitudes',
        titulo: 'Revisar solicitudes (gerente, regional y RH)',
        proceso: 'solicitudes',
        resumen:
            'Gerente → regional dan visto bueno; RH autoriza, rechaza o pide corrección. El tablero por tipo muestra qué falta.',
        queEs: 'Es la bandeja donde se atienden las solicitudes de los colaboradores.',
        quien: ['gerente', 'regional', 'rh'],
        antes: [
            'Tener permiso de revisar o aprobar solicitudes y alcance sobre la persona.',
        ],
        pasos: [
            {
                titulo: 'Elige el tipo',
                detalle:
                    'Personal → Solicitudes: una tarjeta por tipo (préstamos, permisos, vacaciones…) con recibidas, por autorizar y en corrección. «Ver todas» abre el tablero completo.',
                canal: 'web',
            },
            {
                titulo: 'Abre la solicitud',
                detalle:
                    'Revisa motivo, evidencias y la «Cadena de autorización» (quién ya dio visto bueno).',
            },
            {
                titulo: 'Gerente / regional',
                detalle:
                    '«Visto bueno» (web) o desde la app (Equipo → pendientes).',
                quien: 'gerente',
                canal: 'web_app',
            },
            {
                titulo: 'RH',
                detalle:
                    'Aprobar, rechazar o requerir corrección (las dos últimas exigen comentario).',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Columnas: Solicitudes recibidas (esperan vistos buenos) → Pendiente de autorizar → Requiere corrección → Aprobadas / No aprobadas. Una solicitud aprobada ya terminó: lo que sigue (documentos, pago) vive en su propio proceso.',
            },
        ],
        despues: [
            'El colaborador recibe cada cambio de estado; lo autorizado queda en su expediente.',
        ],
        errores: [
            {
                problema: '«No puedo autorizar.»',
                solucion:
                    'Faltan vistos buenos, o tú diste uno (quien da visto bueno no autoriza).',
            },
            {
                problema: '«No puedo rechazar.»',
                solucion: 'Escribe un comentario: es obligatorio.',
            },
        ],
        registro: ['Historial completo de la solicitud.'],
        relacionados: [
            'permisos',
            'vacaciones',
            'prestamos',
            'completar-datos',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Solicitudes',
                ruta: '/rh/solicitudes',
                permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo apruebo una solicitud?',
            '¿Cómo autorizo un permiso?',
            '¿Quién da el visto bueno?',
        ],
        recorrido: 'autorizar-permiso',
    },
    {
        id: 'nomina-lotes',
        titulo: 'Recibos de nómina: preparar, revisar y emitir',
        proceso: 'nomina',
        resumen:
            'Recibos simples, no CFDI. PEOPLE prepara el lote como borrador; RH lo revisa uno por uno y solo al «Emitir» se publica y se avisa.',
        queEs: 'Es el recibo interno que recibe cada colaborador por periodo. PEOPLE NO timbra (no es CFDI) ni calcula ISR/IMSS de nómina.',
        quien: ['rh'],
        antes: [
            'Cada colaborador debe tener sueldo capturado; quien no lo tiene se reporta como error y no recibe recibo.',
        ],
        pasos: [
            {
                titulo: 'Elige la periodicidad',
                detalle:
                    'Personal → Recibos de nómina. Semanal: lunes a domingo. Quincenal: del 1 al 15 y del 16 al último día real del mes (octubre 16–31, abril 16–30, febrero 16–28 o 29).',
                canal: 'web',
            },
            {
                titulo: '«Preparar lote»',
                detalle:
                    'Desde el sueldo de cada persona o importando el archivo de RH. TODOS quedan como borrador: no se publican, no se notifican y el trabajador no los ve.',
            },
            {
                titulo: '«Revisar lote»',
                detalle:
                    'Resumen: esperados, preparados, errores, advertencias, percepciones, deducciones y neto. Filtra correctos, advertencias o errores.',
            },
            {
                titulo: 'Revisa persona por persona',
                detalle:
                    'Vista previa del PDF real con el formato oficial, con «Anterior» y «Siguiente». Corrige conceptos si hace falta.',
            },
            {
                titulo: '«Emitir N recibos»',
                detalle:
                    'Solo entonces cada recibo se marca emitido, se congela su PDF en el expediente, se publica en web y app y se avisa al colaborador (notificación + push).',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Preparar ≠ publicar. La separación existe para que nadie reciba un recibo con errores: RH revisa todo antes. La emisión automática viene APAGADA; PEOPLE solo prepara el lote quincenal unos días antes del pago y RH lo emite.',
            },
        ],
        despues: [
            'Un lote emitido no se emite dos veces (si se cortó a mitad, volver a emitir publica solo los pendientes).',
            '«Cancelar lote» (con motivo) cancela sus recibos y permite volver a preparar el periodo. Nunca se borra nada.',
        ],
        notificaciones: [
            'Al emitir: «Tu recibo de nómina ya está disponible» (notificación + push) a cada colaborador.',
        ],
        documentos: [
            'Recibo de nómina en PDF (dice «RECIBO DE NÓMINA») archivado en el expediente.',
        ],
        errores: [
            {
                problema: '«Un colaborador sale en Errores.»',
                solucion:
                    'Normalmente no tiene sueldo capturado: captúralo en Datos laborales y vuelve a preparar.',
            },
            {
                problema: '¿Por qué el colaborador no ve su recibo?',
                solucion:
                    'El lote no se ha emitido. Los borradores nunca son visibles.',
            },
        ],
        registro: [
            'Lote con quién lo preparó, revisó y emitió; snapshot del PDF de cada recibo.',
        ],
        relacionados: ['mis-recibos'],
        accesos: [
            {
                etiqueta: 'Ir a Recibos de nómina',
                ruta: '/rh/nomina/lotes',
                permisos: ['nomina.recibos.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo emito nómina?',
            '¿Cómo preparo los recibos?',
            '¿Por qué no veo el recibo?',
            '¿PEOPLE timbra la nómina?',
        ],
        recorrido: 'emitir-nomina',
    },
    {
        id: 'mis-recibos',
        titulo: 'Mis recibos de nómina',
        proceso: 'nomina',
        resumen:
            'El colaborador recibe el aviso, toca la notificación y abre su recibo (también sin conexión).',
        queEs: 'Es donde el colaborador consulta sus recibos emitidos.',
        quien: ['colaborador'],
        antes: ['RH emitió el lote del periodo.'],
        pasos: [
            {
                titulo: 'Recibe el aviso',
                detalle: '«Tu recibo de nómina ya está disponible.»',
                canal: 'app',
            },
            {
                titulo: 'Toca la notificación',
                detalle: 'Abre directo el recibo.',
                canal: 'app',
            },
            {
                titulo: 'O entra a Recibos',
                detalle:
                    'App → Recibos, o web → Mis recibos de nómina. Descarga el PDF.',
                canal: 'web_app',
            },
        ],
        despues: ['El recibo queda guardado en tu expediente.'],
        errores: [
            {
                problema: '«No veo mi recibo.»',
                solucion:
                    'RH todavía no lo emite; los recibos preparados no son visibles.',
            },
        ],
        relacionados: ['nomina-lotes', 'app-colaborador'],
        accesos: [
            {
                etiqueta: 'Ir a Mis recibos',
                ruta: '/mis-recibos',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Dónde veo mi recibo de nómina?',
            '¿Por qué no veo mi recibo?',
        ],
    },
    {
        id: 'avisos',
        titulo: 'Avisos de Recursos Humanos',
        proceso: 'avisos',
        resumen:
            'RH envía un aviso (título, mensaje e imagen) a toda la empresa o a una persona; llega por notificación y push.',
        queEs: 'Es el canal oficial de comunicados de RH.',
        quien: ['rh', 'colaborador'],
        antes: ['Tener el permiso de enviar avisos.'],
        pasos: [
            {
                titulo: 'Abre Avisos',
                detalle: 'Personal → Avisos.',
                quien: 'rh',
                canal: 'web',
            },
            {
                titulo: 'Redacta',
                detalle:
                    'A quién le llega (toda la empresa o un colaborador), título, mensaje e imagen opcional.',
                quien: 'rh',
            },
            { titulo: 'Envía', detalle: 'Se publica al momento.', quien: 'rh' },
            {
                titulo: 'El colaborador lo lee',
                detalle:
                    'Le llega un push; lo abre en Avisos (app o web) y queda marcado como leído.',
                quien: 'colaborador',
                canal: 'web_app',
            },
        ],
        despues: [
            'Los avisos personales quedan en el expediente de la persona (pestaña Avisos / Comunicaciones).',
        ],
        notificaciones: ['Push + bandeja de avisos a cada destinatario.'],
        errores: [
            {
                problema: '«No encuentro al colaborador.»',
                solucion:
                    'Escribe al menos parte de su nombre; solo aparecen personas de tu alcance.',
            },
        ],
        registro: ['Aviso, destinatarios y lecturas.'],
        relacionados: ['app-colaborador'],
        accesos: [
            {
                etiqueta: 'Ir a Avisos (RH)',
                ruta: '/rh/avisos',
                permisos: ['avisos.enviar'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a mis Avisos',
                ruta: '/avisos',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo envío un aviso?',
            '¿Cómo mando un aviso a una sola persona?',
        ],
    },
    {
        id: 'app-colaborador',
        titulo: 'Cómo usar la app MR. LANA PEOPLE',
        proceso: 'vida-laboral',
        resumen:
            'Iniciar sesión, cambiar tu contraseña, tu foto, tus datos, lecciones, solicitudes, vacaciones, préstamos, avisos y recibos.',
        queEs: 'Es la guía rápida para el colaborador. Lo mismo está en el portal web («Mi espacio»).',
        quien: ['colaborador'],
        antes: ['Tener la app instalada (Descargar app) y tu usuario.'],
        pasos: [
            {
                titulo: 'Iniciar sesión',
                detalle:
                    'Usuario = tu primer nombre + tu primer apellido (por ejemplo «Juan Perez»), sin acentos; y tu contraseña.',
                canal: 'app',
            },
            {
                titulo: 'Cambiar la contraseña temporal',
                detalle:
                    'Si RH te dio una contraseña temporal, la app te pide cambiarla al entrar; no puedes usar nada más hasta hacerlo.',
                canal: 'app',
            },
            {
                titulo: 'Inicio',
                detalle:
                    'Lo que necesitas hacer (documentos, firmas, lecciones), «Completa tu información» si te falta algo, y accesos rápidos.',
                canal: 'app',
            },
            {
                titulo: 'Perfil y foto',
                detalle:
                    'Tu primera foto queda al instante. Si ya tienes foto oficial, la nueva queda como propuesta hasta que RH la apruebe.',
                canal: 'app',
            },
            {
                titulo: 'Expediente',
                detalle: 'Tus documentos y su estado; sube lo que te pidan.',
                canal: 'app',
            },
            {
                titulo: 'Solicitudes y Vacaciones',
                detalle: 'Permisos, vacaciones y préstamos, con su estado.',
                canal: 'app',
            },
            {
                titulo: 'Lecciones de bienvenida',
                detalle: 'Si eres nuevo, respóndelas desde Inicio.',
                canal: 'app',
            },
            {
                titulo: 'Avisos y notificaciones',
                detalle:
                    'La campana muestra tus notificaciones; Avisos, los comunicados de RH.',
                canal: 'app',
            },
            {
                titulo: 'Recibos',
                detalle: 'Tus recibos emitidos (se abren sin conexión).',
                canal: 'app',
            },
            {
                titulo: 'Celebraciones',
                detalle:
                    'Felicitaciones de cumpleaños y aniversario, y el muro de felicitaciones.',
                canal: 'app',
            },
        ],
        despues: [
            'Si olvidaste tu contraseña y no tienes correo registrado, pide a RH una contraseña temporal.',
        ],
        errores: [
            {
                problema: '«No puedo entrar.»',
                solucion:
                    'Revisa tu usuario (nombre + primer apellido). Si sigue fallando, pide a RH que genere credenciales nuevas.',
            },
            {
                problema: '«No puedo cambiar mis datos.»',
                solucion:
                    'Usa «Completa tu información» o «Solicitar corrección»: RH los autoriza.',
            },
        ],
        relacionados: [
            'completar-datos',
            'permisos',
            'mis-recibos',
            'onboarding',
        ],
        accesos: [
            { etiqueta: 'Descargar la app', ruta: '/app' },
            {
                etiqueta: 'Ir a Mi portal',
                ruta: '/mi-portal',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cuál es mi usuario?',
            '¿Cómo cambio mi contraseña?',
            '¿Cómo cambio mi foto?',
            '¿Cómo uso la app?',
            '¿Cómo completo datos?',
        ],
    },
    {
        id: 'celebraciones',
        titulo: 'Cumpleaños y aniversarios',
        proceso: 'vida-laboral',
        resumen:
            'PEOPLE felicita solo a las 8:00 (cumpleaños y, si está activado, aniversarios). RH ve el calendario y puede avisar a todos.',
        queEs: 'Son las celebraciones del equipo: el cumpleaños (fecha de nacimiento) y el aniversario laboral (fecha de ingreso).',
        quien: ['rh', 'colaborador'],
        antes: [
            'La persona debe tener fecha de nacimiento y fecha de ingreso capturadas.',
        ],
        pasos: [
            {
                titulo: 'Felicitación automática',
                detalle:
                    'A las 8:00 (hora de México) el cumpleañero recibe su felicitación en el portal y por push. RH recibe un recordatorio a las 7:30.',
                canal: 'automatico',
            },
            {
                titulo: 'Qué hace RH',
                detalle:
                    'Personal → Celebraciones: cumpleañeros de hoy, calendario y próximos. «Ver tarjeta», «Descargar», «Copiar», «Enviar al colaborador» y «Avisar a todos».',
                quien: 'rh',
            },
            {
                titulo: 'Aniversarios',
                detalle:
                    'Misma pantalla, pestaña Aniversarios. En su configuración: activar, mensaje con {anios}, envío automático y fondo de la tarjeta.',
                quien: 'rh',
            },
            {
                titulo: 'Qué ve el colaborador',
                detalle:
                    'Su tarjeta y el Muro de felicitaciones, donde el equipo deja mensajes.',
                quien: 'colaborador',
                canal: 'web_app',
            },
        ],
        despues: [
            'Cada botón muestra si ya se envió y cuándo, para no felicitar dos veces.',
        ],
        errores: [
            {
                problema: '«Alguien no aparece.»',
                solucion:
                    'Le falta fecha de nacimiento o de ingreso en su expediente.',
            },
        ],
        registro: ['Envíos con fecha y quién; mensajes del muro.'],
        relacionados: ['app-colaborador'],
        accesos: [
            {
                etiqueta: 'Ir a Celebraciones',
                ruta: '/rh/cumpleanos',
                permisos: ['rh.cumpleanos.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir al Muro de felicitaciones',
                ruta: '/celebraciones',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo felicito un cumpleaños?',
            '¿Se envían solas las felicitaciones?',
            '¿Qué es un aniversario laboral?',
        ],
    },
    {
        id: 'actividad-laboral',
        titulo: 'Actividad laboral (historial de movimientos)',
        proceso: 'vida-laboral',
        resumen:
            'Cada alta, cambio de puesto, de sucursal, de sueldo, ascenso o baja se agrega al historial; nunca lo reemplaza.',
        queEs: 'Es la línea de tiempo laboral de la persona. Cada movimiento queda con su fecha, quién lo hizo y los valores antes y después.',
        quien: ['rh'],
        antes: ['Abrir el expediente de la persona.'],
        pasos: [
            {
                titulo: 'Haz el cambio',
                detalle:
                    'Expediente → Datos laborales (puesto, sucursal, departamento, sueldo…).',
                canal: 'web',
            },
            {
                titulo: 'PEOPLE lo registra',
                detalle:
                    'Se crea el movimiento: alta, baja, promoción, cambio de puesto, sucursal, departamento, jefe, empresa o sueldo, cobertura temporal o reingreso.',
                canal: 'automatico',
            },
            {
                titulo: 'Consulta',
                detalle: 'Pestaña «Historial RH» del expediente.',
            },
        ],
        despues: [
            'Si cambia de puesto o de sucursal, la vacante del lugar que deja se abre sola.',
            'El jefe directo se recalcula desde el organigrama.',
        ],
        errores: [
            {
                problema: '«Quiero borrar un movimiento.»',
                solucion:
                    'No se puede: el historial es permanente. Registra el movimiento correcto encima.',
            },
        ],
        registro: ['Movimiento laboral con antes → después.'],
        relacionados: ['expediente', 'plantilla-y-vacantes'],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo registro un cambio de puesto?',
            '¿Dónde veo el historial laboral?',
            '¿Cómo registro un ascenso?',
        ],
    },
];
