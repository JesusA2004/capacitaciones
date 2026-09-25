<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, Lock, LockOpen, MessageCircleHeart, Pencil, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AccionesCelebracionHoy from '@/components/Celebraciones/AccionesCelebracionHoy.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { useInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import { destroy, store, update } from '@/routes/celebraciones/mensajes';
import { recepcion } from '@/routes/rh/celebraciones';

/**
 * Pantalla de una celebración (destino de sus notificaciones):
 *  - compañero: tarjeta + "Déjale un mensaje"; solo ve SU felicitación;
 *  - homenajeado: su tarjeta y "Mensajes para ti" (todos);
 *  - RH: todo lo anterior + enviar / avisar / moderar.
 * Lo que se muestra lo decide el backend (los mensajes ajenos ni siquiera
 * llegan a esta página si no puedes verlos).
 */
type Mensaje = {
    id: number;
    mensaje: string | null;
    foto_url: string | null;
    autor: { nombre: string; puesto: string | null; foto_url: string | null };
    es_mio: boolean;
    puede_editar: boolean;
    puede_eliminar: boolean;
    creado_en: string;
    editado_en: string | null;
};

const props = defineProps<{
    celebracion: {
        id: number;
        tipo: 'cumpleanos' | 'aniversario_laboral';
        tipo_etiqueta: string;
        fecha: string;
        es_hoy: boolean;
        anios: number | null;
        titulo: string;
        homenajeado: { colaborador_id: number; nombre: string; puesto: string | null; sucursal: string | null; foto_url: string | null };
        tarjeta_url: string;
        recibe_mensajes: boolean;
        es_mia: boolean;
        puede_escribir: boolean;
        puede_ver_todos: boolean;
        mi_mensaje: Mensaje | null;
        mensajes_count: number | null;
        avisada_todos_at: string | null;
        puede_enviar: boolean;
        puede_moderar: boolean;
    };
    mensajes: Mensaje[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Celebración', href: '' },
        ],
    },
});

const { getInitials } = useInitials();
const { confirmarAccion } = useAlertas();

const form = useForm({ mensaje: '' });
const editando = ref(false);
const edicion = useForm({ mensaje: props.celebracion.mi_mensaje?.mensaje ?? '' });

const otrosMensajes = computed(() => props.mensajes.filter((m) => !m.es_mio || props.celebracion.puede_ver_todos));

function enviar() {
    form.post(store.url(props.celebracion.id), { preserveScroll: true, onSuccess: () => form.reset() });
}

function guardarEdicion() {
    const mio = props.celebracion.mi_mensaje;

    if (!mio) {
        return;
    }

    edicion.put(update.url([props.celebracion.id, mio.id]), { preserveScroll: true, onSuccess: () => (editando.value = false) });
}

async function eliminar(mensaje: Mensaje) {
    const ok = await confirmarAccion('¿Eliminar esta felicitación?', mensaje.es_mio ? 'Se borrará tu mensaje.' : 'Se retirará por moderación; queda registro en la bitácora.', 'Sí, eliminar');

    if (ok) {
        router.delete(destroy.url([props.celebracion.id, mensaje.id]), { preserveScroll: true });
    }
}

function alternarRecepcion() {
    router.post(recepcion.url(props.celebracion.id), {}, { preserveScroll: true });
}

function fecha(valor: string): string {
    return new Date(valor).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="celebracion.titulo" />

    <div class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-6 p-4 sm:p-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <!-- Tarjeta oficial -->
        <section class="flex flex-col gap-3">
            <img :src="celebracion.tarjeta_url" :alt="celebracion.titulo" class="w-full rounded-2xl border shadow-sm" />
            <div class="flex flex-wrap gap-2">
                <Button as-child size="sm" variant="outline">
                    <a :href="`${celebracion.tarjeta_url}?descargar=1`" download><Download class="size-4" />Descargar tarjeta</a>
                </Button>
            </div>
        </section>

        <section class="flex min-w-0 flex-col gap-4">
            <div class="flex items-center gap-3">
                <Avatar class="size-14">
                    <AvatarImage v-if="celebracion.homenajeado.foto_url" :src="celebracion.homenajeado.foto_url" :alt="celebracion.homenajeado.nombre" />
                    <AvatarFallback>{{ getInitials(celebracion.homenajeado.nombre) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold break-words">{{ celebracion.es_mia ? '¡Felicidades!' : celebracion.titulo }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ celebracion.homenajeado.nombre }} · {{ [celebracion.homenajeado.puesto, celebracion.homenajeado.sucursal].filter(Boolean).join(' · ') }}
                    </p>
                </div>
            </div>

            <!-- RH -->
            <div v-if="celebracion.puede_enviar && celebracion.es_hoy" class="flex flex-wrap gap-2 rounded-xl border p-3">
                <AccionesCelebracionHoy
                    :colaborador-id="celebracion.homenajeado.colaborador_id"
                    :tipo="celebracion.tipo"
                    :nombre="celebracion.homenajeado.nombre"
                    :anios="celebracion.anios"
                    :avisada-todos-at="celebracion.avisada_todos_at"
                />
                <Button size="sm" variant="ghost" @click="alternarRecepcion">
                    <component :is="celebracion.recibe_mensajes ? Lock : LockOpen" class="size-4" />
                    {{ celebracion.recibe_mensajes ? 'Cerrar recepción' : 'Abrir recepción' }}
                </Button>
            </div>

            <!-- Compañero: escribir SU felicitación -->
            <div v-if="celebracion.puede_escribir" class="flex flex-col gap-2 rounded-2xl border p-4">
                <label for="mensaje" class="font-medium">Déjale un mensaje</label>
                <Textarea id="mensaje" v-model="form.mensaje" rows="4" maxlength="500" placeholder="Escribe tu felicitación…" />
                <InputError :message="form.errors.mensaje" />
                <p class="text-xs text-muted-foreground">Solo {{ celebracion.homenajeado.nombre.split(' ')[0] }} y RH pueden leerlo.</p>
                <Button class="w-fit" :disabled="form.processing || form.mensaje.trim() === ''" @click="enviar">
                    <Spinner v-if="form.processing" />
                    <Send v-else class="size-4" />
                    Enviar felicitación
                </Button>
            </div>

            <div v-else-if="celebracion.mi_mensaje" class="flex flex-col gap-2 rounded-2xl border p-4">
                <p class="text-sm font-medium">Ya enviaste tu felicitación.</p>
                <template v-if="!editando">
                    <p class="text-sm whitespace-pre-line">{{ celebracion.mi_mensaje.mensaje }}</p>
                    <div class="flex gap-2">
                        <Button v-if="celebracion.mi_mensaje.puede_editar" size="sm" variant="outline" @click="editando = true"><Pencil class="size-4" />Editar</Button>
                        <Button size="sm" variant="ghost" @click="eliminar(celebracion.mi_mensaje)"><Trash2 class="size-4" />Eliminar</Button>
                    </div>
                </template>
                <template v-else>
                    <Textarea v-model="edicion.mensaje" rows="4" maxlength="500" />
                    <InputError :message="edicion.errors.mensaje" />
                    <div class="flex gap-2">
                        <Button size="sm" :disabled="edicion.processing" @click="guardarEdicion">Guardar</Button>
                        <Button size="sm" variant="ghost" @click="editando = false">Cancelar</Button>
                    </div>
                </template>
            </div>

            <p v-else-if="!celebracion.es_mia && !celebracion.recibe_mensajes && !celebracion.puede_ver_todos" class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                La recepción de felicitaciones no está abierta.
            </p>

            <!-- Homenajeado / RH: todos los mensajes -->
            <div v-if="celebracion.puede_ver_todos" class="flex flex-col gap-3">
                <h2 class="flex items-center gap-2 font-semibold">
                    <MessageCircleHeart class="size-5 text-pink-500" />
                    {{ celebracion.es_mia ? 'Mensajes para ti' : 'Felicitaciones recibidas' }} ({{ celebracion.mensajes_count ?? mensajes.length }})
                </h2>
                <p v-if="otrosMensajes.length === 0" class="text-sm text-muted-foreground">Aún no hay mensajes.</p>
                <article v-for="m in otrosMensajes" :key="m.id" class="flex gap-3 rounded-2xl border p-3">
                    <Avatar class="size-10 shrink-0">
                        <AvatarImage v-if="m.autor.foto_url" :src="m.autor.foto_url" :alt="m.autor.nombre" />
                        <AvatarFallback>{{ getInitials(m.autor.nombre) }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ m.autor.nombre }}<span v-if="m.autor.puesto" class="font-normal text-muted-foreground"> · {{ m.autor.puesto }}</span></p>
                        <p class="text-sm whitespace-pre-line break-words">{{ m.mensaje }}</p>
                        <img v-if="m.foto_url" :src="m.foto_url" alt="Foto de la felicitación" class="mt-2 max-h-64 rounded-lg border" />
                        <p class="mt-1 text-xs text-muted-foreground">{{ fecha(m.creado_en) }}<template v-if="m.editado_en"> · editado</template></p>
                    </div>
                    <Button v-if="m.puede_eliminar && !m.es_mio" size="icon" variant="ghost" aria-label="Eliminar mensaje" @click="eliminar(m)">
                        <Trash2 class="size-4" />
                    </Button>
                </article>
            </div>
        </section>
    </div>
</template>
