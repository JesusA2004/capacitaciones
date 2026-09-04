<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Copy, Download, QrCode, RefreshCw, ShieldOff } from '@lucide/vue';
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
</script>

<template>
    <Head title="Invitación de incorporación" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            :titulo="
                invitacion.nombre_prellenado ?? 'Invitación de incorporación'
            "
            :descripcion="`Código ${invitacion.codigo_legible ?? '—'}`"
            :icono="QrCode"
        >
            <EstadoBadge :estado="invitacion.estado" />
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <div
                    v-if="tokenPlano && qrUrl"
                    class="rounded-2xl border border-border/60 bg-card p-4"
                >
                    <h2 class="mb-1 text-sm font-semibold">
                        Código QR (solo se muestra una vez)
                    </h2>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Por seguridad, este código no vuelve a mostrarse después
                        de salir de esta página. Cópialo, descárgalo o
                        compártelo ahora; si lo necesitas después, regenera la
                        invitación.
                    </p>

                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div
                            class="h-40 w-40 shrink-0 rounded-xl border border-border/60 bg-white p-2"
                            v-html="qrSvg"
                        />

                        <div class="flex flex-1 flex-col gap-2">
                            <code
                                class="rounded bg-muted px-2 py-1 text-xs break-all"
                                >{{ qrUrl }}</code
                            >
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    @click="copiarLiga"
                                >
                                    <Copy class="size-4" />
                                    Copiar liga
                                </Button>
                                <Button
                                    v-if="puedeDescargarQr"
                                    as-child
                                    size="sm"
                                    variant="outline"
                                >
                                    <a
                                        :href="`${qrUrlRoute.url(invitacion.id)}?formato=svg`"
                                        download="invitacion-qr.svg"
                                    >
                                        <Download class="size-4" />
                                        SVG
                                    </a>
                                </Button>
                                <Button
                                    v-if="puedeDescargarQr"
                                    as-child
                                    size="sm"
                                    variant="outline"
                                >
                                    <a
                                        :href="`${qrUrlRoute.url(invitacion.id)}?formato=png`"
                                        download="invitacion-qr.png"
                                    >
                                        <Download class="size-4" />
                                        PNG
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="rounded-2xl border border-dashed border-border/60 bg-card p-4 text-sm text-muted-foreground"
                >
                    El código de esta invitación ya no está disponible (solo se
                    muestra una vez, justo al crearla o regenerarla).
                    <template v-if="puedeAccionar && puedeRegenerar">
                        Genera uno nuevo si el colaborador aún lo necesita.
                    </template>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        Datos prellenados
                    </h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Nombre</dt>
                            <dd>{{ invitacion.nombre_prellenado ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Correo</dt>
                            <dd>{{ invitacion.email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Teléfono</dt>
                            <dd>{{ invitacion.telefono ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Empresa</dt>
                            <dd>{{ invitacion.empresa?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Sucursal</dt>
                            <dd>{{ invitacion.sucursal?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Departamento</dt>
                            <dd>
                                {{ invitacion.departamento?.nombre ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Puesto</dt>
                            <dd>{{ invitacion.puesto?.nombre ?? '—' }}</dd>
                        </div>
                        <div
                            class="col-span-2"
                            v-if="invitacion.metadata?.observaciones"
                        >
                            <dt class="text-muted-foreground">
                                Observaciones internas
                            </dt>
                            <dd>{{ invitacion.metadata.observaciones }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div
                    class="rounded-2xl border border-border/60 bg-card p-4 text-sm"
                >
                    <h2 class="mb-3 text-sm font-semibold">Vigencia y uso</h2>
                    <dl class="grid gap-2">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Vence</dt>
                            <dd>
                                {{
                                    new Date(
                                        invitacion.expires_at,
                                    ).toLocaleString()
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Usos</dt>
                            <dd>
                                {{ invitacion.usos_count }} /
                                {{ invitacion.max_usos }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Creada por</dt>
                            <dd>{{ invitacion.creado_por?.name ?? '—' }}</dd>
                        </div>
                        <div
                            v-if="invitacion.usado_por"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Usada por</dt>
                            <dd>{{ invitacion.usado_por.name }}</dd>
                        </div>
                        <div
                            v-if="invitacion.usuario"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Colaborador</dt>
                            <dd>{{ invitacion.usuario.name }}</dd>
                        </div>
                        <div
                            v-if="invitacion.revoked_at"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Revocada</dt>
                            <dd>
                                {{
                                    new Date(
                                        invitacion.revoked_at,
                                    ).toLocaleString()
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="invitacion.regenerada_desde"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Regenerada de</dt>
                            <dd>#{{ invitacion.regenerada_desde.id }}</dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-if="puedeRegenerar || puedeRevocar"
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                >
                    <h2 class="text-sm font-semibold">Acciones</h2>

                    <Button
                        v-if="puedeRegenerar"
                        size="sm"
                        variant="secondary"
                        :disabled="formRegenerar.processing"
                        @click="regenerarInvitacion"
                    >
                        <RefreshCw class="size-4" />
                        Regenerar QR
                    </Button>

                    <Button
                        v-if="puedeRevocar && puedeAccionar"
                        size="sm"
                        variant="destructive"
                        :disabled="formRevocar.processing"
                        @click="revocarInvitacion"
                    >
                        <ShieldOff class="size-4" />
                        Revocar
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
