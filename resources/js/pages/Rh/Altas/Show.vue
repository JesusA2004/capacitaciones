<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Copy,
    Download,
    IdCard,
    Send,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import {
    aprobar,
    cancelar,
    enviar,
    index,
    rechazar,
    revisar,
} from '@/routes/rh/altas';
import { firma as descargarFirma } from '@/routes/rh/altas';
import { foto as descargarFoto } from '@/routes/rh/altas';
import { descargar as descargarDocumento } from '@/routes/rh/altas/documentos';
import type { AltaDigitalItem } from '@/types';

const props = defineProps<{
    alta: AltaDigitalItem;
}>();

// `layout` recibe una función en vez de un objeto estático porque
// `defineOptions()` se compila fuera del scope de setup() y no puede
// referenciar variables locales como `props`; Inertia la invoca con las
// props actuales de la página en cada render (ver @inertiajs/vue3).
defineOptions({
    layout: (pageProps: { alta: AltaDigitalItem }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Altas digitales', href: index.url() },
            {
                title: pageProps.alta.nombre ?? `Alta #${pageProps.alta.id}`,
                href: '',
            },
        ],
    }),
});

const { mostrarExito, mostrarError } = useAlertas();

const ligaPublica = computed(
    () => `${window.location.origin}/alta/${props.alta.token}`,
);

async function copiarLiga() {
    await navigator.clipboard.writeText(ligaPublica.value);
    mostrarExito('Liga copiada al portapapeles.');
}

const formEnviar = useForm({});
function enviarLiga() {
    formEnviar.post(enviar.url(props.alta.id), { preserveScroll: true });
}

const formRevisar = useForm({ estado: 'en_revision_rh', comentarios: '' });
function marcarEnRevision() {
    formRevisar
        .transform((d) => ({ ...d, estado: 'en_revision_rh' }))
        .put(revisar.url(props.alta.id), { preserveScroll: true });
}
function solicitarCorreccion() {
    formRevisar
        .transform((d) => ({ ...d, estado: 'requiere_correccion' }))
        .put(revisar.url(props.alta.id), { preserveScroll: true });
}

const formAprobar = useForm({});
function aprobarAlta() {
    formAprobar.post(aprobar.url(props.alta.id), {
        preserveScroll: true,
        onError: () => mostrarError('No fue posible aprobar el alta.'),
    });
}

const formRechazar = useForm({ motivo_rechazo: '' });
function rechazarAlta() {
    formRechazar.post(rechazar.url(props.alta.id), { preserveScroll: true });
}

const formCancelar = useForm({});
function cancelarAlta() {
    formCancelar.post(cancelar.url(props.alta.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="alta.nombre ?? 'Alta digital'" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            :titulo="
                `${alta.nombre ?? ''} ${alta.apellidos ?? ''}`.trim() ||
                'Alta digital'
            "
            :descripcion="alta.puesto?.nombre ?? 'Sin puesto asignado'"
            :icono="IdCard"
        >
            <EstadoBadge :estado="alta.estado" />
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">Liga segura</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="rounded bg-muted px-2 py-1 text-xs">{{
                            ligaPublica
                        }}</code>
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="copiarLiga"
                        >
                            <Copy class="size-4" />
                            Copiar
                        </Button>
                        <Button
                            size="sm"
                            :disabled="formEnviar.processing"
                            @click="enviarLiga"
                        >
                            <Send class="size-4" />
                            {{
                                alta.estado === 'creada'
                                    ? 'Enviar liga'
                                    : 'Reenviar liga'
                            }}
                        </Button>
                    </div>
                    <p
                        v-if="alta.token_expira_en"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Vigente hasta
                        {{ new Date(alta.token_expira_en).toLocaleString() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">Datos personales</h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Teléfono</dt>
                            <dd>{{ alta.telefono ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Correo</dt>
                            <dd>{{ alta.correo ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Fecha de nacimiento
                            </dt>
                            <dd>{{ alta.fecha_nacimiento ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">CURP</dt>
                            <dd>{{ alta.curp ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">RFC</dt>
                            <dd>{{ alta.rfc ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">NSS</dt>
                            <dd>{{ alta.nss ?? '—' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">Domicilio</dt>
                            <dd>{{ alta.domicilio ?? '—' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">
                                Contacto de emergencia
                            </dt>
                            <dd>
                                {{ alta.contacto_emergencia_nombre ?? '—' }} ·
                                {{ alta.contacto_emergencia_telefono ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        Foto, firma y documentos
                    </h2>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <a
                            v-if="alta.tiene_foto"
                            :href="descargarFoto.url(alta.id)"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 text-[var(--brand-primary)] hover:underline"
                            ><Download class="size-4" /> Fotografía</a
                        >
                        <a
                            v-if="alta.tiene_firma"
                            :href="descargarFirma.url(alta.id)"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 text-[var(--brand-primary)] hover:underline"
                            ><Download class="size-4" /> Firma</a
                        >
                    </div>
                    <ul class="mt-3 flex flex-col gap-2">
                        <li
                            v-for="documento in alta.documentos"
                            :key="documento.id"
                            class="flex items-center justify-between rounded-lg border border-border/60 p-2 text-sm"
                        >
                            <span>{{ documento.tipo?.nombre }}</span>
                            <a
                                :href="
                                    descargarDocumento.url([
                                        alta.id,
                                        documento.id,
                                    ])
                                "
                                target="_blank"
                                class="text-[var(--brand-primary)] hover:underline"
                                >Descargar</a
                            >
                        </li>
                        <p
                            v-if="!alta.documentos.length"
                            class="text-xs text-muted-foreground"
                        >
                            Sin documentos cargados todavía.
                        </p>
                    </ul>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        Avisos y consentimiento
                    </h2>
                    <p class="flex items-center gap-1.5 text-sm">
                        <CheckCircle2
                            v-if="alta.aviso_privacidad_aceptado"
                            class="size-4 text-[var(--success)]"
                        />
                        <XCircle v-else class="size-4 text-muted-foreground" />
                        Aviso de privacidad
                    </p>
                    <p class="flex items-center gap-1.5 text-sm">
                        <CheckCircle2
                            v-if="alta.consentimiento_datos_aceptado"
                            class="size-4 text-[var(--success)]"
                        />
                        <XCircle v-else class="size-4 text-muted-foreground" />
                        Consentimiento de datos
                    </p>
                </div>

                <div
                    v-if="alta.colaborador"
                    class="rounded-2xl border border-border/60 bg-card p-4 text-sm"
                >
                    <h2 class="mb-2 text-sm font-semibold">
                        Colaborador creado
                    </h2>
                    <p>
                        {{ alta.colaborador.name }}
                        {{ alta.colaborador.apellidos }}
                    </p>
                </div>

                <div
                    v-else
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                >
                    <h2 class="text-sm font-semibold">Revisión de RH</h2>

                    <Button
                        size="sm"
                        variant="secondary"
                        :disabled="formRevisar.processing"
                        @click="marcarEnRevision"
                        >Marcar en revisión</Button
                    >

                    <Textarea
                        v-model="formRevisar.comentarios"
                        placeholder="Comentarios para el candidato (requiere corrección)"
                        rows="2"
                    />
                    <Button
                        size="sm"
                        variant="secondary"
                        :disabled="formRevisar.processing"
                        @click="solicitarCorreccion"
                        >Solicitar corrección</Button
                    >

                    <Button
                        size="sm"
                        :disabled="formAprobar.processing"
                        @click="aprobarAlta"
                        >Aprobar y crear colaborador</Button
                    >

                    <Textarea
                        v-model="formRechazar.motivo_rechazo"
                        placeholder="Motivo de rechazo"
                        rows="2"
                    />
                    <Button
                        size="sm"
                        variant="destructive"
                        :disabled="
                            formRechazar.processing ||
                            !formRechazar.motivo_rechazo
                        "
                        @click="rechazarAlta"
                        >Rechazar</Button
                    >

                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="formCancelar.processing"
                        @click="cancelarAlta"
                        >Cancelar alta</Button
                    >
                </div>
            </div>
        </div>
    </div>
</template>
