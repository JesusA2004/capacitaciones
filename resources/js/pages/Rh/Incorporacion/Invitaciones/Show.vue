<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    Copy,
    Download,
    QrCode,
    RefreshCw,
    ShieldOff,
} from '@lucide/vue';
import { computed } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import {
    index,
    qr as qrUrlRoute,
    regenerar,
    revocar,
} from '@/routes/rh/incorporacion/invitaciones';
import type { IncorporacionInvitacionItem } from '@/types/incorporacionInvitacion';

const props = defineProps<{
    invitacion: IncorporacionInvitacionItem;
    tokenPlano: string | null;
    qrUrl: string | null;
    qrSvg: string | null;
    puedeRegenerar: boolean;
    yaUsada: boolean;
    puedeRevocar: boolean;
    puedeDescargarQr: boolean;
}>();

defineOptions({
    layout: (pageProps: { invitacion: IncorporacionInvitacionItem }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Invitaciones de incorporación', href: index.url() },
            {
                title:
                    pageProps.invitacion.nombre_prellenado ??
                    `Invitación #${pageProps.invitacion.id}`,
                href: '',
            },
        ],
    }),
});

const { mostrarExito, confirmarRevocacion, confirmarRegeneracion } =
    useAlertas();

async function copiarLiga() {
    if (!props.qrUrl) {
        return;
    }

    await navigator.clipboard.writeText(props.qrUrl);
    mostrarExito('Liga copiada al portapapeles.');
}

const formRegenerar = useForm({});
async function regenerarInvitacion() {
    if (!(await confirmarRegeneracion('esta invitación'))) {
        return;
    }

    formRegenerar.post(regenerar.url(props.invitacion.id), {
        preserveScroll: true,
    });
}

const formRevocar = useForm({});
async function revocarInvitacion() {
    if (!(await confirmarRevocacion('esta invitación'))) {
        return;
    }

    formRevocar.post(revocar.url(props.invitacion.id), {
        preserveScroll: true,
    });
}

const puedeAccionar = computed(() => props.invitacion.estado === 'activo');

function fecha(valor: string | null | undefined): string {
    return valor
        ? new Date(valor).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}

function nombreUsuario(
    u: { name: string; apellidos?: string | null } | null | undefined,
): string {
    return u ? [u.name, u.apellidos].filter(Boolean).join(' ') : '—';
}
</script>

<template>
    <Head title="Invitación de incorporación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            detalle
            :titulo="
                invitacion.nombre_prellenado ?? 'Invitación de incorporación'
            "
            :descripcion="`Código ${invitacion.codigo_legible ?? '—'}`"
            :icono="QrCode"
        >
            <EstadoBadge :estado="invitacion.estado" />
        </CrudPageHeader>

        <!-- QR ya usado: aviso claro y sin opción de generar otro. -->
        <div
            v-if="yaUsada"
            class="flex items-start gap-4 rounded-2xl border border-success/40 bg-success/10 p-5"
        >
            <CircleCheck
                class="mt-0.5 size-7 shrink-0 text-success"
            />
            <div class="flex flex-col gap-1">
                <p class="text-base font-semibold">
                    Este QR ya se usó — ya no puedes generar otro.
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        nombreUsuario(
                            invitacion.usado_por ?? invitacion.usuario,
                        )
                    }}
                    ya creó su cuenta con este código<template
                        v-if="invitacion.used_at"
                    >
                        el {{ fecha(invitacion.used_at) }}</template
                    >. Su proceso continúa desde su expediente.
                </p>
            </div>
        </div>

        <div
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_22rem]"
        >
            <!-- QR grande y completo -->
            <section
                v-if="tokenPlano && qrUrl"
                class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 xl:col-span-2"
            >
                <div>
                    <h2 class="text-lg font-semibold">Código QR de alta</h2>
                    <p class="text-sm text-muted-foreground">
                        La persona lo escanea con la cámara de su celular. Por
                        seguridad este código solo se muestra unos minutos:
                        cópialo, descárgalo o compártelo ahora.
                    </p>
                </div>

                <div
                    class="flex flex-col items-center gap-6 lg:flex-row lg:items-start"
                >
                    <div
                        class="qr-lienzo aspect-square w-full max-w-[min(32rem,70vh)] shrink-0 rounded-2xl border border-border/60 bg-white p-4 shadow-sm"
                        v-html="qrSvg"
                    />

                    <div class="flex w-full min-w-0 flex-1 flex-col gap-5">
                        <div>
                            <p
                                class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                            >
                                Código manual
                            </p>
                            <p
                                class="font-mono text-3xl font-bold tracking-[0.3em]"
                            >
                                {{ invitacion.codigo_legible ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                            >
                                Liga de la invitación
                            </p>
                            <code
                                class="block rounded-lg bg-muted px-3 py-2 text-sm break-all"
                                >{{ qrUrl }}</code
                            >
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button @click="copiarLiga">
                                <Copy class="size-4" />
                                Copiar liga
                            </Button>
                            <Button
                                v-if="puedeDescargarQr"
                                as-child
                                variant="outline"
                            >
                                <a
                                    :href="`${qrUrlRoute.url(invitacion.id)}?formato=png`"
                                    download="invitacion-qr.png"
                                >
                                    <Download class="size-4" />
                                    Descargar PNG
                                </a>
                            </Button>
                            <Button
                                v-if="puedeDescargarQr"
                                as-child
                                variant="outline"
                            >
                                <a
                                    :href="`${qrUrlRoute.url(invitacion.id)}?formato=svg`"
                                    download="invitacion-qr.svg"
                                >
                                    <Download class="size-4" />
                                    Descargar SVG
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-else-if="!yaUsada"
                class="flex flex-col justify-center gap-2 rounded-2xl border border-dashed border-border/60 bg-card p-6 xl:col-span-2"
            >
                <p class="text-base font-semibold">
                    Esta invitación ya no tiene un QR utilizable
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        invitacion.estado === 'revocado'
                            ? 'Fue revocada.'
                            : 'Ya venció.'
                    }}
                    <template v-if="puedeRegenerar">
                        Usa «Generar QR nuevo» si la persona todavía lo
                        necesita.
                    </template>
                </p>
            </section>

            <section
                class="rounded-2xl border border-border/60 bg-card p-5 xl:col-span-2"
            >
                <h2 class="mb-4 text-base font-semibold">Datos prellenados</h2>
                <dl
                    class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div>
                        <dt class="text-muted-foreground">Nombre</dt>
                        <dd class="font-medium">
                            {{ invitacion.nombre_prellenado ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Correo</dt>
                        <dd class="font-medium break-all">
                            {{ invitacion.email ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Teléfono</dt>
                        <dd class="font-medium">
                            {{ invitacion.telefono ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Empresa</dt>
                        <dd class="font-medium">
                            {{ invitacion.empresa?.nombre ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Sucursal</dt>
                        <dd class="font-medium">
                            {{ invitacion.sucursal?.nombre ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Departamento</dt>
                        <dd class="font-medium">
                            {{ invitacion.departamento?.nombre ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Puesto</dt>
                        <dd class="font-medium">
                            {{ invitacion.puesto?.nombre ?? '—' }}
                        </dd>
                    </div>
                    <div
                        v-if="invitacion.metadata?.observaciones"
                        class="sm:col-span-2 lg:col-span-3"
                    >
                        <dt class="text-muted-foreground">
                            Observaciones internas
                        </dt>
                        <dd>{{ invitacion.metadata.observaciones }}</dd>
                    </div>
                </dl>
            </section>

            <aside
                class="flex flex-col gap-4 xl:col-start-3 xl:row-span-2 xl:row-start-1"
            >
                <div
                    class="rounded-2xl border border-border/60 bg-card p-5 text-sm"
                >
                    <h2 class="mb-4 text-base font-semibold">Vigencia y uso</h2>
                    <dl class="grid gap-3">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Vence</dt>
                            <dd class="text-right">
                                {{ fecha(invitacion.expires_at) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Usos</dt>
                            <dd>
                                {{ invitacion.usos_count }} /
                                {{ invitacion.max_usos }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Creada por</dt>
                            <dd class="text-right">
                                {{ nombreUsuario(invitacion.creado_por) }}
                            </dd>
                        </div>
                        <div
                            v-if="invitacion.usado_por"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Usada por</dt>
                            <dd class="text-right">
                                {{ nombreUsuario(invitacion.usado_por) }}
                            </dd>
                        </div>
                        <div
                            v-if="invitacion.revoked_at"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Revocada</dt>
                            <dd class="text-right">
                                {{ fecha(invitacion.revoked_at) }}
                            </dd>
                        </div>
                        <div
                            v-if="invitacion.regenerada_desde"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Reemplaza a</dt>
                            <dd>#{{ invitacion.regenerada_desde.id }}</dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-if="puedeRegenerar || (puedeRevocar && puedeAccionar)"
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h2 class="text-base font-semibold">Acciones</h2>

                    <Button
                        v-if="puedeRegenerar"
                        variant="secondary"
                        :disabled="formRegenerar.processing"
                        @click="regenerarInvitacion"
                    >
                        <RefreshCw class="size-4" />
                        Generar QR nuevo
                    </Button>

                    <Button
                        v-if="puedeRevocar && puedeAccionar"
                        variant="destructive"
                        :disabled="formRevocar.processing"
                        @click="revocarInvitacion"
                    >
                        <ShieldOff class="size-4" />
                        Revocar
                    </Button>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
/* El SVG del QR trae width/height fijos: se fuerza a llenar el lienzo
   cuadrado para que nunca se corte ni se vea diminuto. */
.qr-lienzo :deep(svg) {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
