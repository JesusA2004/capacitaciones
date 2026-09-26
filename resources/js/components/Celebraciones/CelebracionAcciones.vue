<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Copy, Download, Eye, ImageOff, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AccionesCelebracionHoy from '@/components/Celebraciones/AccionesCelebracionHoy.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { TEXTOS_CELEBRACION } from '@/lib/celebraciones';
import { mensajeFelicitacion } from '@/lib/cumpleanos';
import { tarjeta } from '@/routes/rh/celebraciones';
import { regenerar } from '@/routes/rh/celebraciones/tarjeta';
import type { EventoCelebracion, PermisosCelebracion, TipoCelebracion } from '@/types';

/**
 * Flujo COMPLETO de la tarjeta del día, idéntico para cumpleaños y
 * aniversarios (mismas rutas rh.celebraciones.*):
 *   Ver tarjeta → vista previa real (PNG del servidor) en un diálogo;
 *   Generar     → regenera con los datos actuales y refresca la vista previa;
 *   Descargar   → PNG como archivo;
 *   Copiar      → la IMAGEN al portapapeles, o el texto si el navegador no puede;
 *   Enviar / Avisar a todos → AccionesCelebracionHoy.
 * Los mensajes de éxito los manda el backend (flash toast): aquí no se
 * duplican; solo se muestran errores legibles.
 */
const props = defineProps<{
    evento: EventoCelebracion;
    tipo: TipoCelebracion;
    permisos: PermisosCelebracion;
}>();

const abierto = ref(false);
const version = ref(Date.now());
const cargandoImagen = ref(true);
const errorImagen = ref(false);
const generando = ref(false);
const copiando = ref(false);

const parametros = computed<[number, TipoCelebracion]>(() => [props.evento.colaborador_id, props.tipo]);
const urlDescarga = computed(() => tarjeta.url(parametros.value));
const urlVista = computed(() => `${urlDescarga.value}?ver=1&v=${version.value}`);

function refrescarVista() {
    cargandoImagen.value = true;
    errorImagen.value = false;
    version.value = Date.now();
}

function verTarjeta() {
    refrescarVista();
    abierto.value = true;
}

function generar() {
    generando.value = true;
    router.post(
        regenerar.url(parametros.value),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => refrescarVista(),
            onError: (errores) => toast.error(Object.values(errores)[0] ?? 'No se pudo generar la tarjeta. Intenta de nuevo.'),
            onFinish: () => (generando.value = false),
        },
    );
}

function textoFelicitacion(): string {
    return props.tipo === 'aniversario_laboral' && props.evento.anios
        ? `¡Felicidades, ${props.evento.nombre}, por tus ${props.evento.anios} ${props.evento.anios === 1 ? 'año' : 'años'} en MR. LANA!`
        : mensajeFelicitacion(props.evento.nombre);
}

async function copiar() {
    copiando.value = true;

    try {
        if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
            // La promesa va DENTRO del ClipboardItem: Safari exige que
            // clipboard.write() se llame en el mismo gesto del usuario.
            const imagen = fetch(`${urlDescarga.value}?ver=1`, { credentials: 'same-origin' }).then((respuesta) => {
                if (!respuesta.ok) {
                    throw new Error('tarjeta');
                }

                return respuesta.blob();
            });
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': imagen })]);
            toast.success('Imagen copiada. Pégala en WhatsApp o en un correo.');

            return;
        }

        await navigator.clipboard.writeText(textoFelicitacion());
        toast.info('Tu navegador no permite copiar imágenes; se copió el mensaje de felicitación.');
    } catch {
        toast.error('No se pudo copiar la tarjeta. Usa «Descargar».');
    } finally {
        copiando.value = false;
    }
}
</script>

<template>
    <Button size="sm" variant="secondary" @click="verTarjeta">
        <Eye class="size-4" />
        Ver tarjeta
    </Button>
    <Button v-if="permisos.gestionar" size="sm" variant="outline" :disabled="generando" @click="generar">
        <Spinner v-if="generando" />
        <Sparkles v-else class="size-4" />
        Generar
    </Button>
    <Button v-if="permisos.descargar" as-child size="sm" variant="outline">
        <a :href="urlDescarga" download><Download class="size-4" />Descargar</a>
    </Button>
    <Button size="sm" variant="outline" :disabled="copiando" @click="copiar">
        <Spinner v-if="copiando" />
        <Copy v-else class="size-4" />
        Copiar
    </Button>
    <AccionesCelebracionHoy
        :colaborador-id="evento.colaborador_id"
        :tipo="tipo"
        :nombre="evento.nombre"
        :anios="evento.anios"
        :enviada-at="evento.enviada_at"
        :avisada-todos-at="evento.avisada_todos_at"
    />

    <Dialog v-model:open="abierto">
        <DialogContent class="flex max-h-[calc(100dvh-1.5rem)] w-[calc(100vw-1.5rem)] flex-col gap-3 overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="break-words">{{ evento.nombre }}</DialogTitle>
                <DialogDescription>{{ TEXTOS_CELEBRACION[tipo].tarjeta }}{{ evento.detalle ? ` · ${evento.detalle}` : '' }}</DialogDescription>
            </DialogHeader>

            <div class="relative aspect-[4/5] w-full overflow-hidden rounded-lg border bg-muted/40">
                <Skeleton v-if="cargandoImagen && !errorImagen" class="absolute inset-0" />
                <div v-if="errorImagen" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
                    <ImageOff class="size-8" />
                    No se pudo cargar la tarjeta. Intenta generarla de nuevo.
                </div>
                <img
                    v-if="abierto"
                    :key="urlVista"
                    :src="urlVista"
                    :alt="`${TEXTOS_CELEBRACION[tipo].tarjeta} de ${evento.nombre}`"
                    class="size-full object-contain"
                    :class="{ invisible: cargandoImagen || errorImagen }"
                    @load="cargandoImagen = false"
                    @error="
                        cargandoImagen = false;
                        errorImagen = true;
                    "
                />
            </div>

            <slot name="dialogo" />

            <DialogFooter class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end">
                <Button v-if="permisos.gestionar" size="sm" variant="outline" :disabled="generando" @click="generar">
                    <Spinner v-if="generando" />
                    <Sparkles v-else class="size-4" />
                    Generar de nuevo
                </Button>
                <Button size="sm" variant="outline" :disabled="copiando" @click="copiar">
                    <Copy class="size-4" />
                    Copiar
                </Button>
                <Button v-if="permisos.descargar" as-child size="sm" class="col-span-2 sm:col-span-1">
                    <a :href="urlDescarga" download><Download class="size-4" />Descargar</a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
