<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Casilla from '@/components/Common/Casilla.vue';
import SeccionDocumentosProceso from '@/components/documentos/SeccionDocumentosProceso.vue';
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
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import {
    ErrorDocumento,
    useDocumentosProceso,
} from '@/composables/useDocumentosProceso';
import type {
    AccionDocumento,
    DatoFaltante,
    ItemDocumentoProceso,
    SeccionDocumentosProceso as Seccion,
    TipoRegistroDocumental,
} from '@/types';

/**
 * "Documentos del proceso" en contexto: ficha del colaborador (tipo
 * "colaborador"), cierre, solicitud de permiso, préstamo, evaluación o
 * entrega de activo. Nunca manda al usuario a "Formatos/Plantillas": aquí
 * se genera, descarga, imprime y se registra el flujo físico.
 */
const props = defineProps<{
    tipo: TipoRegistroDocumental | 'colaborador';
    id: number;
    proceso?: string;
}>();

const api = useDocumentosProceso();
const secciones = ref<Seccion[]>([]);
const cargando = ref(true);
const ocupado = ref(false);
const error = ref<string | null>(null);

async function cargar() {
    cargando.value = true;
    error.value = null;

    try {
        secciones.value =
            props.tipo === 'colaborador'
                ? await api.delColaborador(props.id)
                : [await api.seccion(props.tipo, props.id, props.proceso)];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'No se pudieron cargar los documentos.';
        secciones.value = [];
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(() => [props.tipo, props.id], cargar);

// ───────── Datos faltantes ─────────
type Pendiente = {
    seccion: Seccion;
    clave: string | null;
    regenerar: boolean;
};
const faltantes = ref<DatoFaltante[]>([]);
const pendiente = ref<Pendiente | null>(null);
const valores = ref<Record<string, string>>({});
const mensajeFaltantes = ref('');

function columnaDe(f: DatoFaltante): string {
    return f.fuente === 'sucursal' ? `sucursal.${f.columna}` : f.columna;
}

async function ejecutarGeneracion(p: Pendiente, completar: Record<string, string> = {}) {
    ocupado.value = true;
    const { registro, proceso } = p.seccion;
    const manuales: Record<string, string> = {};
    const datosColaborador: Record<string, string> = {};

    for (const [columna, valor] of Object.entries(completar)) {
        const dato = faltantes.value.find((f) => columnaDe(f) === columna);

        if (dato?.fuente === 'manual') {
            manuales[dato.columna] = valor;
        } else {
            datosColaborador[columna] = valor;
        }
    }

    try {
        const respuesta =
            p.clave === null
                ? await api.paquete(registro.tipo, registro.id, { proceso, completar: datosColaborador })
                : await api.generar(registro.tipo, registro.id, {
                      clave: p.clave,
                      proceso,
                      regenerar: p.regenerar,
                      completar: datosColaborador,
                      manuales,
                  });
        reemplazar(respuesta.data);
        pendiente.value = null;
        faltantes.value = [];
        toast.success(p.clave === null ? 'Paquete generado.' : 'Documento generado.');
    } catch (e) {
        if (e instanceof ErrorDocumento && e.codigo === 'DATOS_FALTANTES') {
            pendiente.value = p;
            faltantes.value = e.faltantes;
            mensajeFaltantes.value = e.message;
            valores.value = Object.fromEntries(e.faltantes.map((f) => [columnaDe(f), '']));
        } else {
            toast.error(e instanceof Error ? e.message : 'No se pudo generar.');
        }
    } finally {
        ocupado.value = false;
    }
}

function completarYGenerar() {
    if (!pendiente.value) {
        return;
    }

    const completar = Object.fromEntries(
        Object.entries(valores.value).filter(([, v]) => v.trim() !== ''),
    );
    void ejecutarGeneracion(pendiente.value, completar);
}

function reemplazar(nueva: Seccion) {
    const i = secciones.value.findIndex(
        (s) => s.registro.tipo === nueva.registro.tipo && s.registro.id === nueva.registro.id && s.proceso === nueva.proceso,
    );

    if (i >= 0) {
        secciones.value[i] = nueva;
    } else {
        void cargar();
    }
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
    testigos: [] as { nombre: string; puesto: string }[],
});
const archivo = ref<File | null>(null);

const accionApi: Record<string, string> = {
    marcar_impreso: 'imprimir',
    registrar_firma: 'firma-fisica',
    registrar_envio: 'envio',
    registrar_recepcion: 'recepcion',
    subir_escaneo: 'escaneo',
    archivar: 'archivar',
};

const tituloPaso: Record<string, string> = {
    imprimir: 'Marcar como impreso',
    'firma-fisica': 'Registrar firma física',
    envio: 'Enviar original a corporativo',
    recepcion: 'Registrar recepción en corporativo',
    escaneo: 'Subir escaneo firmado',
    archivar: 'Archivar original',
};

async function alAccionar(seccion: Seccion, item: ItemDocumentoProceso, accion: AccionDocumento) {
    if (accion.clave === 'generar' || accion.clave === 'regenerar') {
        await ejecutarGeneracion({ seccion, clave: item.clave, regenerar: accion.clave === 'regenerar' });

        return;
    }

    if (accion.clave === 'descargar' && item.documento) {
        window.open(api.urlDescarga(item.documento.id), '_blank', 'noopener');

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
            ? Array.from({ length: Math.max(1, item.requiere.cantidad_testigos) }, () => ({ nombre: '', puesto: '' }))
            : [],
    };
    archivo.value = null;
    paso.value = { item, seccion, accion: destino };
}

async function confirmarPaso() {
    if (!paso.value?.item.documento) {
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
            .filter((t) => t.nombre.trim() !== '')
            .forEach((t, i) => {
                datos.append(`testigos[${i}][nombre]`, t.nombre);
                datos.append(`testigos[${i}][puesto]`, t.puesto);
            });
    }

    if (paso.value.accion === 'envio') {
        datos.append('paqueteria', f.paqueteria);
        datos.append('numero_guia', f.numero_guia);

        if (archivo.value) {
            datos.append('comprobante', archivo.value);
        }
    }

    if (paso.value.accion === 'escaneo' && archivo.value) {
        datos.append('archivo', archivo.value);
    }

    ocupado.value = true;

    try {
        const r = await api.operar(paso.value.item.documento.id, paso.value.accion, datos);
        toast.success(r.message);
        paso.value = null;
        await cargar();
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo registrar.');
    } finally {
        ocupado.value = false;
    }
}

// ───────── Procedimiento de baja ─────────
const dialogoBaja = ref<{ seccion: Seccion; tipo: 'negativa' | 'testigos' | 'etapa' } | null>(null);
const formBaja = ref({
    documentos: [] as string[],
    observaciones: '',
    finiquito_a_disposicion: true,
    testigos: [
        { nombre: '', cargo: '' },
        { nombre: '', cargo: '' },
    ],
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

    const tipo = accion.clave === 'registrar_negativa' ? 'negativa' : accion.clave === 'capturar_testigos' ? 'testigos' : 'etapa';
    const actual = seccion.negativa;
    formBaja.value.documentos = seccion.documentos.filter((d) => d.documento).map((d) => d.clave);
    formBaja.value.testigos = [0, 1].map((i) => ({
        nombre: actual?.testigos[i]?.nombre ?? '',
        cargo: actual?.testigos[i]?.cargo ?? '',
    }));
    formBaja.value.participantes = { ...formBaja.value.participantes, ...(actual?.participantes ?? {}) };
    evidencias.value = [];
    dialogoBaja.value = { seccion, tipo };
}

async function confirmarBaja() {
    if (!dialogoBaja.value) {
        return;
    }

    const { seccion, tipo } = dialogoBaja.value;
    const f = formBaja.value;
    const datos = new FormData();

    if (tipo === 'negativa') {
        f.documentos.forEach((d) => datos.append('documentos[]', d));
        datos.append('finiquito_a_disposicion', f.finiquito_a_disposicion ? '1' : '0');
    }

    if (tipo !== 'etapa') {
        f.testigos.forEach((t, i) => {
            datos.append(`testigos[${i}][nombre]`, t.nombre);
            datos.append(`testigos[${i}][cargo]`, t.cargo);
        });
        Object.entries(f.participantes).forEach(([k, v]) => v && datos.append(`participantes[${k}]`, v));
    }

    if (tipo === 'etapa') {
        datos.append('etapa', f.etapa);
        f.medios.forEach((m) => datos.append('medios[]', m));
        evidencias.value.forEach((e) => datos.append('evidencias[]', e));
    }

    if (f.observaciones) {
        datos.append('observaciones', f.observaciones);
    }

    ocupado.value = true;

    try {
        const r = await api.procedimiento(seccion.registro.id, tipo, datos);
        reemplazar(r.data);
        toast.success(r.message);
        dialogoBaja.value = null;
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo registrar.');
    } finally {
        ocupado.value = false;
    }
}

function elegirArchivos(evento: Event, multiple: boolean) {
    const lista = Array.from((evento.target as HTMLInputElement).files ?? []);

    if (multiple) {
        evidencias.value = lista;
    } else {
        archivo.value = lista[0] ?? null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <p v-if="cargando" class="text-sm text-[var(--mrl-texto-suave)]">
            Cargando documentos…
        </p>
        <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>

        <SeccionDocumentosProceso
            v-for="s in secciones"
            :key="`${s.proceso}-${s.registro.tipo}-${s.registro.id}`"
            :seccion="s"
            :ocupado="ocupado"
            @accion="(item, accion) => alAccionar(s, item, accion)"
            @accion-seccion="(accion) => alAccionSeccion(s, accion)"
        />
    </div>

    <!-- Datos faltantes -->
    <Dialog :open="pendiente !== null" @update:open="(v: boolean) => !v && (pendiente = null)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Faltan {{ faltantes.length }} dato(s) requerido(s)</DialogTitle>
                <DialogDescription>
                    {{ mensajeFaltantes }} Lo que captures aquí se guarda en la
                    ficha del colaborador (no se vuelve a pedir).
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div v-for="f in faltantes" :key="columnaDe(f)" class="flex flex-col gap-1">
                    <Label :for="`falta-${f.campo}`">{{ f.etiqueta }}</Label>
                    <template v-if="f.editable">
                        <NativeSelect
                            v-if="f.tipo === 'estado_civil'"
                            :id="`falta-${f.campo}`"
                            v-model="valores[columnaDe(f)]"
                        >
                            <NativeSelectOption value="">Selecciona…</NativeSelectOption>
                            <NativeSelectOption value="soltero">Soltero(a)</NativeSelectOption>
                            <NativeSelectOption value="casado">Casado(a)</NativeSelectOption>
                            <NativeSelectOption value="union_libre">Unión libre</NativeSelectOption>
                            <NativeSelectOption value="divorciado">Divorciado(a)</NativeSelectOption>
                            <NativeSelectOption value="viudo">Viudo(a)</NativeSelectOption>
                        </NativeSelect>
                        <NativeSelect
                            v-else-if="f.tipo === 'genero'"
                            :id="`falta-${f.campo}`"
                            v-model="valores[columnaDe(f)]"
                        >
                            <NativeSelectOption value="">Selecciona…</NativeSelectOption>
                            <NativeSelectOption value="masculino">Masculino</NativeSelectOption>
                            <NativeSelectOption value="femenino">Femenino</NativeSelectOption>
                        </NativeSelect>
                        <Input
                            v-else
                            :id="`falta-${f.campo}`"
                            v-model="valores[columnaDe(f)]"
                            :type="f.tipo === 'fecha' ? 'date' : f.tipo === 'hora' ? 'time' : f.tipo === 'correo' ? 'email' : 'text'"
                        />
                    </template>
                    <p v-else class="text-xs text-[var(--mrl-texto-suave)]">
                        Se completa en su módulo ({{ f.fuente }}): no se puede
                        capturar aquí.
                    </p>
                    <p v-if="f.documentos?.length" class="text-xs text-[var(--mrl-texto-suave)]">
                        Lo piden: {{ f.documentos.join(', ') }}
                    </p>
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="pendiente = null">Cancelar</Button>
                <Button :disabled="ocupado" @click="completarYGenerar">Guardar y generar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Paso del flujo físico -->
    <Dialog :open="paso !== null" @update:open="(v: boolean) => !v && (paso = null)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ paso ? tituloPaso[paso.accion] : '' }}</DialogTitle>
                <DialogDescription>{{ paso?.item.nombre }}</DialogDescription>
            </DialogHeader>
            <div v-if="paso" class="flex flex-col gap-3">
                <template v-if="paso.accion === 'firma-fisica'">
                    <label v-if="paso.item.requiere.huella" class="flex items-center gap-2 text-sm">
                        <Casilla v-model="formPaso.huella_registrada" /> Se recabó la huella
                    </label>
                    <div v-for="(t, i) in formPaso.testigos" :key="i" class="grid gap-2 sm:grid-cols-2">
                        <Input v-model="t.nombre" :placeholder="`Testigo ${i + 1}: nombre`" />
                        <Input v-model="t.puesto" placeholder="Cargo" />
                    </div>
                </template>
                <template v-if="paso.accion === 'envio'">
                    <Input v-model="formPaso.paqueteria" placeholder="Paquetería" />
                    <Input v-model="formPaso.numero_guia" placeholder="Número de guía" />
                    <Label>Comprobante (opcional)</Label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="(e) => elegirArchivos(e, false)" />
                </template>
                <template v-if="paso.accion === 'escaneo'">
                    <Label>Escaneo o foto del documento firmado</Label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" capture="environment" @change="(e) => elegirArchivos(e, false)" />
                </template>
                <template v-if="paso.accion !== 'escaneo' && paso.accion !== 'imprimir'">
                    <Label>Fecha real (opcional)</Label>
                    <Input v-model="formPaso.fecha" type="date" />
                </template>
                <Textarea v-model="formPaso.observaciones" placeholder="Observaciones (opcional)" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="paso = null">Cancelar</Button>
                <Button :disabled="ocupado" @click="confirmarPaso">Confirmar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Procedimiento de baja -->
    <Dialog :open="dialogoBaja !== null" @update:open="(v: boolean) => !v && (dialogoBaja = null)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
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
                    administrativa de negativa con dos testigos; las firmas son
                    físicas.
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
                        <Casilla v-model="formBaja.documentos" :value="d.clave" /> {{ d.nombre }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="formBaja.finiquito_a_disposicion" /> El finiquito
                        queda a su disposición
                    </label>
                </template>
                <template v-if="dialogoBaja.tipo !== 'etapa'">
                    <div v-for="(t, i) in formBaja.testigos" :key="i" class="grid gap-2 sm:grid-cols-2">
                        <Input v-model="t.nombre" :placeholder="`Testigo ${i + 1}: nombre`" />
                        <Input v-model="t.cargo" placeholder="Cargo" />
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <Input v-model="formBaja.participantes.rh_nombre" placeholder="RH: nombre" />
                        <Input v-model="formBaja.participantes.rh_cargo" placeholder="RH: cargo" />
                        <Input v-model="formBaja.participantes.jefe_nombre" placeholder="Jefe inmediato: nombre" />
                        <Input v-model="formBaja.participantes.jefe_cargo" placeholder="Jefe inmediato: cargo" />
                        <Input v-model="formBaja.participantes.lugar_acta" placeholder="Ciudad (p. ej. Cuernavaca, Morelos)" />
                        <Input v-model="formBaja.participantes.hora_acta" type="time" placeholder="Hora" />
                    </div>
                    <Input v-model="formBaja.participantes.domicilio_acta" placeholder="Domicilio donde se levanta el acta" />
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Lo que dejes vacío se propone desde PEOPLE (RH que opera,
                        jefe del colaborador, ciudad y domicilio de su sucursal).
                    </p>
                </template>
                <template v-if="dialogoBaja.tipo === 'etapa'">
                    <NativeSelect v-model="formBaja.etapa">
                        <NativeSelectOption v-for="(etiqueta, clave) in etapas" :key="clave" :value="clave">
                            {{ etiqueta }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <div v-if="formBaja.etapa === 'notificacion_electronica'" class="flex gap-4 text-sm">
                        <label class="flex items-center gap-2"><Casilla v-model="formBaja.medios" value="correo" /> Correo</label>
                        <label class="flex items-center gap-2"><Casilla v-model="formBaja.medios" value="whatsapp" /> WhatsApp corporativo</label>
                    </div>
                    <Label>Evidencias (capturas, correos, acuses)</Label>
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" @change="(e) => elegirArchivos(e, true)" />
                </template>
                <Textarea v-model="formBaja.observaciones" placeholder="Observaciones (opcional)" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogoBaja = null">Cancelar</Button>
                <Button :disabled="ocupado" @click="confirmarBaja">Guardar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
