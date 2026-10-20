<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    FileWarning,
    Loader2,
    ServerCrash,
    ShieldAlert,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import CamposDatosFaltantes from '@/components/documentos/CamposDatosFaltantes.vue';
import SeccionDocumentosProceso from '@/components/documentos/SeccionDocumentosProceso.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import {
    ErrorDocumento,
    useDocumentosProceso,
} from '@/composables/useDocumentosProceso';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import { columnaDatoFaltante } from '@/lib/datosFaltantes';
import { testigos as buscarTestigosDocumento } from '@/routes/rh/documentos-proceso';
import type {
    AccionDocumento,
    ColaboradorBusqueda,
    DatoFaltante,
    ItemDocumentoProceso,
    SeccionDocumentosProceso as Seccion,
    TipoRegistroDocumental,
} from '@/types';

/** Testigo capturado: elegido del buscador (colaborador_id) o externo a mano. */
type TestigoCaptura = {
    colaborador_id: number | null;
    nombre: string;
    puesto: string;
    externo: boolean;
};

/**
 * "Documentos del proceso" en contexto: ficha del colaborador (tipo
 * "colaborador"), cierre, solicitud de permiso, préstamo, evaluación o
 * entrega de activo. Nunca manda al usuario a "Documentos maestros": aquí
 * se genera, se ve el PDF, se descarga el Word, se imprime y se registra el
 * flujo físico. Todo error esperable del motor tiene su propio aviso.
 */
const props = defineProps<{
    tipo: TipoRegistroDocumental | 'colaborador';
    id: number;
    proceso?: string;
}>();

/** Cambió algún documento (generado, firmado, archivado…): la página puede refrescar su propio estado. */
const emit = defineEmits<{
    actualizado: [];
    /** Tarjeta «Finiquito»: la pantalla abre su desglose (ver / editar). */
    verFiniquito: [];
    /** Tarjeta «Finiquito» sin calcular: la pantalla abre «Calcular finiquito». */
    calcularFiniquito: [];
}>();

const api = useDocumentosProceso();
const secciones = ref<Seccion[]>([]);
const cargando = ref(true);
/** Acción en curso ("clave:accion" o "seccion:accion"): un solo click a la vez. */
const ocupado = ref<string | null>(null);
const error = ref<string | null>(null);

// ───────── Selector de testigos (negativa + firma física) ─────────
// El testigo se elige del buscador de personas activas del alcance; el
// backend congela su nombre/puesto/sucursal reales. Nunca se captura un
// testigo interno a mano (solo externos, fuera de este componente).
function urlTestigos(
    colaboradorId: number | null | undefined,
    excluir: number | null,
) {
    return (texto: string) =>
        buscarTestigosDocumento.url({
            query: {
                ...(colaboradorId ? { colaborador: colaboradorId } : {}),
                ...(excluir ? { excluir } : {}),
                q: texto,
            },
        });
}

/** Snapshot guardado → forma que entiende BuscadorColaborador (chip elegido). */
function testigoModelo(t: TestigoCaptura): ColaboradorBusqueda | null {
    return t.colaborador_id
        ? {
              id: t.colaborador_id,
              nombre: t.nombre,
              numero_empleado: null,
              puesto: t.puesto || null,
              sucursal: null,
          }
        : null;
}

function elegirTestigo(
    lista: TestigoCaptura[],
    i: number,
    c: ColaboradorBusqueda | null,
) {
    lista[i] = c
        ? {
              colaborador_id: c.id,
              nombre: c.nombre,
              puesto: c.puesto ?? '',
              externo: false,
          }
        : { colaborador_id: null, nombre: '', puesto: '', externo: false };
}

/** El otro testigo ya elegido (para no ofrecerlo de nuevo en el buscador). */
function otroTestigoId(lista: TestigoCaptura[], i: number): number | null {
    for (let j = 0; j < lista.length; j++) {
        if (j !== i && lista[j]?.colaborador_id) {
            return lista[j].colaborador_id;
        }
    }

    return null;
}

async function cargar() {
    cargando.value = true;
    error.value = null;

    try {
        secciones.value =
            props.tipo === 'colaborador'
                ? await api.delColaborador(props.id)
                : [await api.seccion(props.tipo, props.id, props.proceso)];
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudieron cargar los documentos.';
        secciones.value = [];
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(() => [props.tipo, props.id], cargar);

function reemplazar(nueva: Seccion) {
    const i = secciones.value.findIndex(
        (s) =>
            s.registro.tipo === nueva.registro.tipo &&
            s.registro.id === nueva.registro.id &&
            s.proceso === nueva.proceso,
    );

    if (i >= 0) {
        secciones.value[i] = nueva;
    } else {
        void cargar();
    }

    emit('actualizado');
}

// ───────── Avisos de errores esperables del motor ─────────
type Aviso = {
    titulo: string;
    mensaje: string;
    detalles: string[];
    enlace: string | null;
    tono: 'error' | 'infra' | 'aviso';
};
const aviso = ref<Aviso | null>(null);

function mostrarError(e: unknown) {
    if (!(e instanceof ErrorDocumento) || e.codigo === null) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo completar la acción.',
        );

        return;
    }

    const detalle = e.detalle as {
        campos?: { etiqueta: string; razon: string }[];
        razon?: string;
        cobertura_url?: string;
    };

    switch (e.codigo) {
        case 'DOCUMENT_TEMPLATE_MISSING':
            aviso.value = {
                titulo: 'Falta el formato oficial',
                mensaje: e.message,
                detalles: [],
                enlace: '/rh/documentos-maestros/cobertura',
                tono: 'error',
            };
            break;
        case 'DOCUMENT_CONVERTER_UNAVAILABLE':
            aviso.value = {
                titulo: 'Motor de conversión no disponible',
                mensaje:
                    'No hay un motor de conversión fiel disponible para generar este documento oficial. Es un tema de infraestructura: avisa a Sistemas. No se generó un documento aproximado.',
                detalles: detalle.razon ? [detalle.razon] : [],
                enlace: null,
                tono: 'infra',
            };
            break;
        case 'DOCUMENT_VISUAL_VALIDATION_FAILED':
            aviso.value = {
                titulo: 'Formato sin validar',
                mensaje:
                    'La versión del formato no está validada para generar documentos oficiales. RH debe validar su diseño en Documentos maestros.',
                detalles: detalle.razon ? [detalle.razon] : [],
                enlace: null,
                tono: 'aviso',
            };
            break;
        case 'DOCUMENT_FIELD_OVERFLOW':
            aviso.value = {
                titulo: 'Un dato no cabe en el formato',
                mensaje: e.message,
                detalles: [
                    ...(detalle.campos ?? []).map(
                        (c) => `${c.etiqueta}: ${c.razon}`,
                    ),
                    ...(detalle.razon && !(detalle.campos ?? []).length
                        ? [detalle.razon]
                        : []),
                ],
                enlace: null,
                tono: 'aviso',
            };
            break;
        default:
            toast.error(e.message);
    }
}

// ───────── Generación y datos faltantes ─────────
type Pendiente = {
    seccion: Seccion;
    clave: string | null;
    regenerar: boolean;
    revision?: { motivo: string };
};
const faltantes = ref<DatoFaltante[]>([]);
const pendiente = ref<Pendiente | null>(null);
const valores = ref<Record<string, string>>({});
const nombreDocumentoFaltantes = ref('');

const columnaDe = columnaDatoFaltante;

const editables = computed(() => faltantes.value.filter((f) => f.editable));
const completo = computed(() =>
    editables.value.every(
        (f) => (valores.value[columnaDe(f)] ?? '').trim() !== '',
    ),
);

/** Lo capturado en el modal: datos del acto (solo el documento) vs. de la ficha. */
function separarCapturados(completar: Record<string, string>) {
    const manuales: Record<string, string> = {};
    const datosColaborador: Record<string, string> = {};

    for (const [columna, valor] of Object.entries(completar)) {
        const dato = faltantes.value.find((f) => columnaDe(f) === columna);

        if (dato?.persistencia === 'documento' || dato?.fuente === 'manual') {
            manuales[dato.columna] = valor;
        } else {
            datosColaborador[columna] = valor;
        }
    }

    return { manuales, datosColaborador };
}

async function ejecutarGeneracion(
    p: Pendiente,
    completar: Record<string, string> = {},
) {
    ocupado.value =
        p.clave === null
            ? 'seccion:generar_paquete'
            : `${p.clave}:${p.revision ? 'nueva_revision' : p.regenerar ? 'regenerar' : 'generar'}`;
    const { registro, proceso } = p.seccion;
    const { manuales, datosColaborador } = separarCapturados(completar);

    try {
        const respuesta =
            p.clave === null
                ? await api.paquete(registro.tipo, registro.id, {
                      proceso,
                      completar: datosColaborador,
                  })
                : await api.generar(registro.tipo, registro.id, {
                      clave: p.clave,
                      proceso,
                      regenerar: p.regenerar,
                      completar: datosColaborador,
                      manuales,
                      ...(p.revision
                          ? { revision: true, motivo: p.revision.motivo }
                          : {}),
                  });
        reemplazar(respuesta.data);
        pendiente.value = null;
        faltantes.value = [];
        revision.value = null;
        toast.success(
            p.clave === null ? 'Paquete generado.' : 'Documento generado.',
        );
    } catch (e) {
        if (e instanceof ErrorDocumento && e.codigo === 'DATOS_FALTANTES') {
            pendiente.value = p;
            faltantes.value = e.faltantes;
            nombreDocumentoFaltantes.value =
                (e.detalle as { documento?: string }).documento ?? '';
            valores.value = Object.fromEntries(
                e.faltantes.map((f) => [columnaDe(f), '']),
            );
        } else {
            mostrarError(e);
        }
    } finally {
        ocupado.value = null;
    }
}

function capturados(): Record<string, string> {
    return Object.fromEntries(
        Object.entries(valores.value).filter(([, v]) => v.trim() !== ''),
    );
}

function completarYGenerar() {
    if (!pendiente.value || !completo.value) {
        return;
    }

    void ejecutarGeneracion(pendiente.value, capturados());
}

// ───────── Vista previa (PDF real, sin guardar nada) ─────────
// Regla: todo formato para un colaborador se puede VER antes de generarlo.
const visor = useVistaPreviaPdf(mostrarError);

/** Documentos de la sección que hoy se generarían (los que ofrecen «Vista previa» junto a «Generar»). */
function porGenerar(seccion: Seccion): ItemDocumentoProceso[] {
    return seccion.documentos.filter((d) =>
        d.acciones.some((a) => a.clave === 'generar'),
    );
}

/** Qué botón pidió la vista previa en curso (spinner solo en ese). */
const origenPrevia = ref<{ seccion: Seccion; clave: string } | null>(null);

function previaCargandoDe(seccion: Seccion): string | null {
    return visor.cargando.value && origenPrevia.value?.seccion === seccion
        ? origenPrevia.value.clave
        : null;
}

function verPrevias(
    seccion: Seccion,
    items: ItemDocumentoProceso[],
    completar: Record<string, string> = {},
    origen: string = items.length === 1 ? items[0].clave : 'paquete',
) {
    const { manuales, datosColaborador } = separarCapturados(completar);
    origenPrevia.value = { seccion, clave: origen };

    void visor.abrir(
        items.map((item) => ({
            clave: item.clave,
            nombre: item.nombre,
            url: api.urlVistaPrevia(
                seccion.registro.tipo,
                seccion.registro.id,
                {
                    clave: item.clave,
                    proceso: seccion.proceso,
                    completar: datosColaborador,
                    manuales,
                },
            ),
        })),
    );
}

/** «Previsualizar» del modal de datos faltantes: con lo capturado, sin guardarlo. */
function previsualizarCapturados() {
    const p = pendiente.value;

    if (!p) {
        return;
    }

    const items =
        p.clave === null
            ? porGenerar(p.seccion)
            : p.seccion.documentos.filter((d) => d.clave === p.clave);
    verPrevias(p.seccion, items, capturados(), 'faltantes');
}

// ───────── Nueva revisión de un documento firmado ─────────
const revision = ref<{
    seccion: Seccion;
    item: ItemDocumentoProceso;
    motivo: string;
} | null>(null);

function confirmarRevision() {
    if (!revision.value || revision.value.motivo.trim().length < 15) {
        return;
    }

    void ejecutarGeneracion({
        seccion: revision.value.seccion,
        clave: revision.value.item.clave,
        regenerar: false,
        revision: { motivo: revision.value.motivo.trim() },
    });
}

// ───────── Flujo físico ─────────
type Paso = { item: ItemDocumentoProceso; seccion: Seccion; accion: string };
const paso = ref<Paso | null>(null);
const formPaso = ref({
    observaciones: '',
    huella_registrada: false,
    fecha: '',
    paqueteria: '',
    numero_guia: '',
    testigos: [] as TestigoCaptura[],
});
const archivos = ref<File[]>([]);

// El flujo nuevo concluye al marcar la firma física: ya no se escanea el
// original firmado (se resguarda en papel). «envio»/«recepcion» subsisten solo
// para cerrar expedientes legacy; el backend ya no los ofrece como paso nuevo.
const accionApi: Record<string, string> = {
    marcar_impreso: 'imprimir',
    registrar_firma: 'firma-fisica',
    registrar_envio: 'envio',
    registrar_recepcion: 'recepcion',
    archivar: 'archivar',
};

const tituloPaso: Record<string, string> = {
    imprimir: 'Imprimir y marcar como impreso',
    'firma-fisica': 'Marcar como firmado físicamente',
    envio: 'Enviar original a corporativo',
    recepcion: 'Registrar recepción en corporativo',
    archivar: 'Archivar original',
};

async function alAccionar(
    seccion: Seccion,
    item: ItemDocumentoProceso,
    accion: AccionDocumento,
) {
    if (accion.clave === 'ver_finiquito') {
        emit('verFiniquito');

        return;
    }

    if (accion.clave === 'calcular_finiquito') {
        emit('calcularFiniquito');

        return;
    }

    if (accion.clave === 'vista_previa') {
        verPrevias(seccion, [item]);

        return;
    }

    if (accion.clave === 'generar' || accion.clave === 'regenerar') {
        await ejecutarGeneracion({
            seccion,
            clave: item.clave,
            regenerar: accion.clave === 'regenerar',
        });

        return;
    }

    if (accion.clave === 'nueva_revision') {
        revision.value = { seccion, item, motivo: '' };

        return;
    }

    if (accion.clave === 'descargar' && item.documento) {
        window.open(api.urlDescarga(item.documento.id), '_blank', 'noopener');

        return;
    }

    if (accion.clave === 'descargar_word' && item.documento) {
        window.open(api.urlWord(item.documento.id), '_blank', 'noopener');

        return;
    }

    const destino = accionApi[accion.clave];

    if (!destino || !item.documento) {
        return;
    }

    if (destino === 'imprimir') {
        // Se abre el PDF para imprimir con el diálogo del navegador.
        window.open(api.urlDescarga(item.documento.id), '_blank', 'noopener');
    }

    formPaso.value = {
        observaciones: '',
        huella_registrada: false,
        fecha: '',
        paqueteria: '',
        numero_guia: '',
        testigos: item.requiere.testigos
            ? Array.from(
                  { length: Math.max(1, item.requiere.cantidad_testigos) },
                  (): TestigoCaptura => ({
                      colaborador_id: null,
                      nombre: '',
                      puesto: '',
                      externo: false,
                  }),
              )
            : ([] as TestigoCaptura[]),
    };
    archivos.value = [];
    paso.value = { item, seccion, accion: destino };
}

const pasoValido = computed(() => {
    if (!paso.value) {
        return false;
    }

    if (paso.value.accion === 'envio') {
        return (
            formPaso.value.paqueteria.trim() !== '' &&
            formPaso.value.numero_guia.trim() !== ''
        );
    }

    return true;
});

async function confirmarPaso() {
    if (!paso.value?.item.documento || !pasoValido.value) {
        return;
    }

    const f = formPaso.value;
    const datos = new FormData();

    if (f.observaciones) {
        datos.append('observaciones', f.observaciones);
    }

    if (f.fecha) {
        datos.append('fecha', f.fecha);
    }

    if (paso.value.accion === 'firma-fisica') {
        datos.append('huella_registrada', f.huella_registrada ? '1' : '0');
        f.testigos
            .filter((t) => t.colaborador_id !== null || t.nombre.trim() !== '')
            .forEach((t, i) => {
                if (t.colaborador_id !== null) {
                    datos.append(
                        `testigos[${i}][colaborador_id]`,
                        String(t.colaborador_id),
                    );
                } else {
                    datos.append(`testigos[${i}][externo]`, '1');
                    datos.append(`testigos[${i}][nombre]`, t.nombre);
                    datos.append(`testigos[${i}][puesto]`, t.puesto);
                }
            });
    }

    if (paso.value.accion === 'envio') {
        datos.append('paqueteria', f.paqueteria);
        datos.append('numero_guia', f.numero_guia);

        if (archivos.value[0]) {
            datos.append('comprobante', archivos.value[0]);
        }
    }

    ocupado.value = `${paso.value.item.clave}:${paso.value.accion}`;

    try {
        const r = await api.operar(
            paso.value.item.documento.id,
            paso.value.accion,
            datos,
        );
        toast.success(r.message);
        paso.value = null;
        await cargar();
        // La página refresca lo que depende del documento (historial, bloqueos).
        emit('actualizado');
    } catch (e) {
        mostrarError(e);
    } finally {
        ocupado.value = null;
    }
}

// ───────── Procedimiento de baja ─────────
const dialogoBaja = ref<{
    seccion: Seccion;
    tipo: 'negativa' | 'testigos' | 'etapa';
} | null>(null);
const formBaja = ref({
    documentos: [] as string[],
    observaciones: '',
    finiquito_a_disposicion: true,
    testigos: [
        { colaborador_id: null, nombre: '', puesto: '', externo: false },
        { colaborador_id: null, nombre: '', puesto: '', externo: false },
    ] as TestigoCaptura[],
    participantes: {
        rh_nombre: '',
        rh_cargo: '',
        jefe_nombre: '',
        jefe_cargo: '',
        lugar_acta: '',
        domicilio_acta: '',
        hora_acta: '',
    } as Record<string, string>,
    etapa: 'notificacion_electronica',
    medios: ['correo'] as string[],
});
const evidencias = ref<File[]>([]);

const etapas: Record<string, string> = {
    notificacion_electronica: 'Notificación complementaria (correo / WhatsApp)',
    baja_imss: 'Baja ante el IMSS',
    baja_asistencia: 'Baja en control de asistencia',
    accesos_cancelados: 'Cancelación de accesos (correo, sistemas, llaves)',
    aviso_interno: 'Aviso interno de baja al equipo',
    consignacion_preventiva: 'Consignación preventiva del finiquito',
};

function alAccionSeccion(seccion: Seccion, accion: AccionDocumento) {
    if (accion.clave === 'generar_paquete') {
        void ejecutarGeneracion({ seccion, clave: null, regenerar: false });

        return;
    }

    // Cada documento del paquete en la galería del visor.
    if (accion.clave === 'vista_previa_paquete') {
        verPrevias(seccion, porGenerar(seccion));

        return;
    }

    const tipo =
        accion.clave === 'registrar_negativa'
            ? 'negativa'
            : accion.clave === 'capturar_testigos'
              ? 'testigos'
              : 'etapa';
    const actual = seccion.negativa;
    formBaja.value.documentos = seccion.documentos
        .filter((d) => d.documento)
        .map((d) => d.clave);
    formBaja.value.testigos = [0, 1].map((i): TestigoCaptura => {
        const t = actual?.testigos[i];

        return {
            colaborador_id: t?.colaborador_id ?? null,
            nombre: t?.nombre ?? '',
            puesto: t?.cargo ?? '',
            externo: t?.externo ?? false,
        };
    });
    formBaja.value.participantes = {
        ...formBaja.value.participantes,
        ...(actual?.participantes ?? {}),
    };
    formBaja.value.observaciones = '';
    evidencias.value = [];
    dialogoBaja.value = { seccion, tipo };
}

const testigosCompletos = computed(() =>
    formBaja.value.testigos.every(
        (t) => t.colaborador_id !== null || t.nombre.trim() !== '',
    ),
);

async function confirmarBaja() {
    if (!dialogoBaja.value) {
        return;
    }

    const { seccion, tipo } = dialogoBaja.value;
    const f = formBaja.value;
    const datos = new FormData();

    if (tipo === 'negativa') {
        f.documentos.forEach((d) => datos.append('documentos[]', d));
        datos.append(
            'finiquito_a_disposicion',
            f.finiquito_a_disposicion ? '1' : '0',
        );
    }

    if (tipo !== 'etapa') {
        f.testigos
            .filter((t) => t.colaborador_id !== null || t.nombre.trim() !== '')
            .forEach((t, i) => {
                if (t.colaborador_id !== null) {
                    datos.append(
                        `testigos[${i}][colaborador_id]`,
                        String(t.colaborador_id),
                    );
                } else {
                    datos.append(`testigos[${i}][externo]`, '1');
                    datos.append(`testigos[${i}][nombre]`, t.nombre);
                    datos.append(`testigos[${i}][cargo]`, t.puesto);
                }
            });
        Object.entries(f.participantes).forEach(
            ([k, v]) => v && datos.append(`participantes[${k}]`, v),
        );
    }

    if (tipo === 'etapa') {
        datos.append('etapa', f.etapa);
        f.medios.forEach((m) => datos.append('medios[]', m));
        evidencias.value.forEach((e) => datos.append('evidencias[]', e));
    }

    if (f.observaciones) {
        datos.append('observaciones', f.observaciones);
    }

    ocupado.value = `seccion:${tipo}`;

    try {
        const r = await api.procedimiento(seccion.registro.id, tipo, datos);
        reemplazar(r.data);
        toast.success(r.message);
        dialogoBaja.value = null;
    } catch (e) {
        mostrarError(e);
    } finally {
        ocupado.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-if="cargando" class="flex flex-col gap-3" aria-busy="true">
            <Skeleton class="h-6 w-56" />
            <Skeleton class="h-28 w-full" />
            <Skeleton class="h-28 w-full" />
        </div>
        <div
            v-else-if="error"
            class="flex items-center justify-between gap-3 rounded-2xl border border-destructive/30 bg-danger-soft/50 p-4 text-sm text-destructive"
        >
            <span>{{ error }}</span>
            <Button size="sm" variant="outline" @click="cargar"
                >Reintentar</Button
            >
        </div>

        <SeccionDocumentosProceso
            v-for="s in secciones"
            :key="`${s.proceso}-${s.registro.tipo}-${s.registro.id}`"
            :seccion="s"
            :ocupado="ocupado"
            :previa-cargando="previaCargandoDe(s)"
            :previa-ocupada="visor.cargando.value"
            @accion="(item, accion) => alAccionar(s, item, accion)"
            @accion-seccion="(accion) => alAccionSeccion(s, accion)"
        />
    </div>

    <!-- Avisos de errores esperables -->
    <Dialog
        :open="aviso !== null"
        @update:open="(v: boolean) => !v && (aviso = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <ServerCrash
                        v-if="aviso?.tono === 'infra'"
                        class="size-5 text-destructive"
                    />
                    <FileWarning
                        v-else-if="aviso?.tono === 'error'"
                        class="size-5 text-destructive"
                    />
                    <AlertTriangle v-else class="size-5 text-warning" />
                    {{ aviso?.titulo }}
                </DialogTitle>
                <DialogDescription>{{ aviso?.mensaje }}</DialogDescription>
            </DialogHeader>
            <ul v-if="aviso?.detalles.length" class="list-disc pl-5 text-sm">
                <li v-for="(d, i) in aviso.detalles" :key="i">{{ d }}</li>
            </ul>
            <DialogFooter>
                <Button v-if="aviso?.enlace" variant="outline" as-child>
                    <Link :href="aviso.enlace">Ver cobertura documental</Link>
                </Button>
                <Button @click="aviso = null">Entendido</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Datos faltantes -->
    <Dialog
        :open="pendiente !== null"
        @update:open="
            (v: boolean) => !v && ocupado === null && (pendiente = null)
        "
    >
        <DialogContent
            class="max-h-[100svh] overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle>
                    Faltan {{ faltantes.length }} dato{{
                        faltantes.length === 1 ? '' : 's'
                    }}
                    para generar
                    {{ nombreDocumentoFaltantes || 'el documento' }}
                </DialogTitle>
                <DialogDescription>
                    Captura solo lo que falta. Los datos de la persona se
                    guardan en su ficha (no se vuelven a pedir); los datos del
                    acto quedan solo en este documento.
                </DialogDescription>
            </DialogHeader>
            <CamposDatosFaltantes v-model="valores" :faltantes="faltantes" />
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="ocupado !== null"
                    @click="pendiente = null"
                    >Cancelar</Button
                >
                <!-- Con lo capturado, sin guardarlo en la ficha. -->
                <BotonVistaPrevia
                    texto="Previsualizar"
                    :cargando="visor.cargando.value"
                    :deshabilitado="ocupado !== null"
                    @click="previsualizarCapturados"
                />
                <Button
                    :disabled="
                        ocupado !== null || !completo || editables.length === 0
                    "
                    @click="completarYGenerar"
                >
                    <Loader2
                        v-if="ocupado !== null"
                        class="size-4 animate-spin"
                    />
                    Guardar y generar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Vista previa (uno o la galería del paquete) -->
    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        :cargando="visor.cargando.value"
        :titulo-carga="visor.tituloCarga.value"
        @update:open="visor.alCambiarAbierto"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />

    <!-- Nueva revisión de un documento firmado -->
    <Dialog
        :open="revision !== null"
        @update:open="
            (v: boolean) => !v && ocupado === null && (revision = null)
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"
                    ><ShieldAlert class="size-5 text-destructive" /> Nueva
                    revisión</DialogTitle
                >
                <DialogDescription>
                    {{ revision?.item.nombre }} ya está firmado. El documento
                    firmado se conserva intacto; se emite una nueva instancia
                    ligada a él, con motivo y auditoría.
                </DialogDescription>
            </DialogHeader>
            <div v-if="revision" class="flex flex-col gap-1">
                <Label for="motivo-revision">Motivo (obligatorio)</Label>
                <Textarea
                    id="motivo-revision"
                    v-model="revision.motivo"
                    rows="3"
                    maxlength="500"
                />
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Mínimo 15 caracteres.
                </p>
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="ocupado !== null"
                    @click="revision = null"
                    >Cancelar</Button
                >
                <Button
                    variant="destructive"
                    :disabled="
                        ocupado !== null ||
                        (revision?.motivo.trim().length ?? 0) < 15
                    "
                    @click="confirmarRevision"
                >
                    <Loader2
                        v-if="ocupado !== null"
                        class="size-4 animate-spin"
                    />
                    Emitir nueva revisión
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Paso del flujo físico -->
    <Dialog
        :open="paso !== null"
        @update:open="(v: boolean) => !v && ocupado === null && (paso = null)"
    >
        <DialogContent
            class="max-h-[100svh] overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle>{{
                    paso ? tituloPaso[paso.accion] : ''
                }}</DialogTitle>
                <DialogDescription>{{ paso?.item.nombre }}</DialogDescription>
            </DialogHeader>
            <div v-if="paso" class="flex flex-col gap-3">
                <template v-if="paso.accion === 'firma-fisica'">
                    <label
                        v-if="paso.item.requiere.huella"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Casilla v-model="formPaso.huella_registrada" /> Se
                        recabó la huella
                    </label>
                    <div
                        v-for="(t, i) in formPaso.testigos"
                        :key="i"
                        class="flex flex-col gap-1"
                    >
                        <Label class="text-xs text-[var(--mrl-texto-suave)]"
                            >Testigo {{ i + 1 }}</Label
                        >
                        <BuscadorColaborador
                            :model-value="testigoModelo(t)"
                            :buscar-url="
                                urlTestigos(
                                    paso?.item
                                        ? paso.seccion.colaborador.id
                                        : null,
                                    otroTestigoId(formPaso.testigos, i),
                                )
                            "
                            @update:model-value="
                                (c) => elegirTestigo(formPaso.testigos, i, c)
                            "
                        />
                    </div>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        El testigo se elige de las personas activas del centro
                        de trabajo; su nombre y puesto se toman de PEOPLE.
                    </p>
                </template>
                <template v-if="paso.accion === 'envio'">
                    <Input
                        v-model="formPaso.paqueteria"
                        placeholder="Paquetería"
                    />
                    <Input
                        v-model="formPaso.numero_guia"
                        placeholder="Número de guía"
                    />
                    <Label>Comprobante (opcional)</Label>
                    <PeopleFileDropzone
                        v-model="archivos"
                        accept=".pdf,.jpg,.jpeg,.png"
                        label="Arrastra el comprobante de envío"
                        @error="(m: string) => toast.error(m)"
                    />
                </template>
                <template v-if="paso.accion !== 'imprimir'">
                    <Label>Fecha real (opcional)</Label>
                    <DatePicker v-model="formPaso.fecha as string" />
                </template>
                <Textarea
                    v-model="formPaso.observaciones"
                    placeholder="Observaciones (opcional)"
                />
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="ocupado !== null"
                    @click="paso = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="ocupado !== null || !pasoValido"
                    @click="confirmarPaso"
                >
                    <Loader2
                        v-if="ocupado !== null"
                        class="size-4 animate-spin"
                    />
                    Confirmar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Procedimiento de baja -->
    <Dialog
        :open="dialogoBaja !== null"
        @update:open="
            (v: boolean) => !v && ocupado === null && (dialogoBaja = null)
        "
    >
        <DialogContent
            class="max-h-[100svh] overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        dialogoBaja?.tipo === 'negativa'
                            ? 'El colaborador se negó a firmar/recibir'
                            : dialogoBaja?.tipo === 'testigos'
                              ? 'Testigos y participantes del acta'
                              : 'Registrar etapa del procedimiento'
                    }}
                </DialogTitle>
                <DialogDescription v-if="dialogoBaja?.tipo === 'negativa'">
                    No se tratará como firmado. Se habilita el Acta
                    administrativa de negativa con dos testigos elegidos del
                    buscador (personas activas del centro de trabajo); las
                    firmas quedan en blanco para firmarse en papel.
                </DialogDescription>
            </DialogHeader>
            <div v-if="dialogoBaja" class="flex flex-col gap-3">
                <template v-if="dialogoBaja.tipo === 'negativa'">
                    <Label>Documentos que se intentaron entregar</Label>
                    <label
                        v-for="d in dialogoBaja.seccion.documentos"
                        :key="d.clave"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Casilla
                            v-model="formBaja.documentos"
                            :value="d.clave"
                        />
                        {{ d.nombre }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="formBaja.finiquito_a_disposicion" />
                        El finiquito queda a su disposición
                    </label>
                </template>
                <template v-if="dialogoBaja.tipo !== 'etapa'">
                    <Label>Testigos del acta</Label>
                    <div
                        v-for="(t, i) in formBaja.testigos"
                        :key="i"
                        class="flex flex-col gap-1"
                    >
                        <BuscadorColaborador
                            :model-value="testigoModelo(t)"
                            :buscar-url="
                                urlTestigos(
                                    dialogoBaja.seccion.colaborador.id,
                                    otroTestigoId(formBaja.testigos, i),
                                )
                            "
                            @update:model-value="
                                (c) => elegirTestigo(formBaja.testigos, i, c)
                            "
                        />
                    </div>
                    <p v-if="!testigosCompletos" class="text-xs text-warning">
                        El acta exige dos testigos elegidos del buscador
                        (activos del centro de trabajo){{
                            dialogoBaja.tipo === 'negativa'
                                ? ' (puedes capturarlos después, antes de generarla)'
                                : ''
                        }}.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <Input
                            v-model="formBaja.participantes.rh_nombre"
                            placeholder="RH: nombre"
                        />
                        <Input
                            v-model="formBaja.participantes.rh_cargo"
                            placeholder="RH: cargo"
                        />
                        <Input
                            v-model="formBaja.participantes.jefe_nombre"
                            placeholder="Jefe inmediato: nombre"
                        />
                        <Input
                            v-model="formBaja.participantes.jefe_cargo"
                            placeholder="Jefe inmediato: cargo"
                        />
                        <Input
                            v-model="formBaja.participantes.lugar_acta"
                            placeholder="Ciudad (p. ej. Cuernavaca, Morelos)"
                        />
                        <TimePicker
                            v-model="formBaja.participantes.hora_acta"
                            placeholder="Hora"
                        />
                    </div>
                    <Input
                        v-model="formBaja.participantes.domicilio_acta"
                        placeholder="Domicilio donde se levanta el acta"
                    />
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Lo que dejes vacío se propone desde PEOPLE (RH que
                        opera, jefe del colaborador, ciudad y domicilio de su
                        sucursal).
                    </p>
                </template>
                <template v-if="dialogoBaja.tipo === 'etapa'">
                    <Select v-model="formBaja.etapa">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(etiqueta, clave) in etapas"
                                :key="clave"
                                :value="String(clave)"
                                >{{ etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <div
                        v-if="formBaja.etapa === 'notificacion_electronica'"
                        class="flex gap-4 text-sm"
                    >
                        <label class="flex items-center gap-2"
                            ><Casilla
                                v-model="formBaja.medios"
                                value="correo"
                            />
                            Correo</label
                        >
                        <label class="flex items-center gap-2"
                            ><Casilla
                                v-model="formBaja.medios"
                                value="whatsapp"
                            />
                            WhatsApp corporativo</label
                        >
                    </div>
                    <Label>Evidencias (capturas, correos, acuses)</Label>
                    <PeopleFileDropzone
                        v-model="evidencias"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png"
                        label="Arrastra las evidencias"
                        @error="(m: string) => toast.error(m)"
                    />
                </template>
                <Textarea
                    v-model="formBaja.observaciones"
                    placeholder="Observaciones (opcional)"
                />
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="ocupado !== null"
                    @click="dialogoBaja = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="
                        ocupado !== null ||
                        (dialogoBaja?.tipo === 'testigos' && !testigosCompletos)
                    "
                    @click="confirmarBaja"
                >
                    <Loader2
                        v-if="ocupado !== null"
                        class="size-4 animate-spin"
                    />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
