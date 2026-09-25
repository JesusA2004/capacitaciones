<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Camera, CheckCircle2, ImageUp, RotateCcw } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Foto de perfil del colaborador: subir un archivo o tomarla en el momento
 * con la cámara (celular o webcam). Antes de enviarla se recorta al centro
 * en un cuadrado de 800×800 px — así se ve bien como miniatura en todos los
 * módulos sin importar la orientación original. El servidor la vuelve a
 * validar y normalizar (App\Services\Colaboradores\FotoColaboradorService).
 */
const props = withDefaults(
    defineProps<{
        nombre: string;
        fotoUrl?: string | null;
        /** Endpoint POST que recibe el campo `foto`. */
        urlSubida: string;
        puedeEditar?: boolean;
        compacto?: boolean;
    }>(),
    { fotoUrl: null, puedeEditar: true, compacto: false },
);

const LADO = 800;

const inputArchivo = ref<HTMLInputElement | null>(null);
const inputCamaraNativa = ref<HTMLInputElement | null>(null);
const video = ref<HTMLVideoElement | null>(null);

const dialogoAbierto = ref(false);
const modoCamara = ref(false);
const vistaPrevia = ref<string | null>(null);
const archivoListo = ref<File | null>(null);
const enviando = ref(false);
const error = ref<string | null>(null);

let flujo: MediaStream | null = null;

function detenerCamara(): void {
    flujo?.getTracks().forEach((pista) => pista.stop());
    flujo = null;
}

function reiniciar(): void {
    detenerCamara();

    if (vistaPrevia.value) {
        URL.revokeObjectURL(vistaPrevia.value);
    }

    vistaPrevia.value = null;
    archivoListo.value = null;
    modoCamara.value = false;
    error.value = null;
}

function cerrar(abierto: boolean): void {
    dialogoAbierto.value = abierto;

    if (!abierto) {
        reiniciar();
    }
}

/** Recorta al centro en cuadrado y exporta JPEG. */
function recortarCuadrado(
    fuente: CanvasImageSource,
    ancho: number,
    alto: number,
): Promise<File> {
    const lado = Math.min(ancho, alto);
    const lienzo = document.createElement('canvas');
    lienzo.width = LADO;
    lienzo.height = LADO;

    const contexto = lienzo.getContext('2d');

    if (!contexto) {
        return Promise.reject(new Error('Canvas no disponible'));
    }

    contexto.drawImage(
        fuente,
        (ancho - lado) / 2,
        (alto - lado) / 2,
        lado,
        lado,
        0,
        0,
        LADO,
        LADO,
    );

    return new Promise((resolver, rechazar) => {
        lienzo.toBlob(
            (blob) =>
                blob
                    ? resolver(
                          new File([blob], 'foto.jpg', { type: 'image/jpeg' }),
                      )
                    : rechazar(new Error('No se pudo generar la imagen')),
            'image/jpeg',
            0.9,
        );
    });
}

async function prepararArchivo(archivo: File): Promise<void> {
    error.value = null;

    if (!archivo.type.startsWith('image/')) {
        error.value = 'El archivo debe ser una imagen (JPG, PNG o WEBP).';

        return;
    }

    try {
        const mapa = await createImageBitmap(archivo);
        const recortado = await recortarCuadrado(mapa, mapa.width, mapa.height);
        mapa.close();
        mostrarVistaPrevia(recortado);
    } catch {
        error.value = 'No se pudo leer la imagen. Intenta con otra.';
    }
}

function mostrarVistaPrevia(archivo: File): void {
    if (vistaPrevia.value) {
        URL.revokeObjectURL(vistaPrevia.value);
    }

    archivoListo.value = archivo;
    vistaPrevia.value = URL.createObjectURL(archivo);
    detenerCamara();
    modoCamara.value = false;
    dialogoAbierto.value = true;
}

function alElegirArchivo(evento: Event): void {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];
    entrada.value = '';

    if (archivo) {
        void prepararArchivo(archivo);
    }
}

async function abrirCamara(): Promise<void> {
    reiniciar();

    // Sin getUserMedia (http, navegador viejo): el input con `capture`
    // abre directamente la cámara frontal en celulares.
    if (!navigator.mediaDevices?.getUserMedia) {
        inputCamaraNativa.value?.click();

        return;
    }

    dialogoAbierto.value = true;
    modoCamara.value = true;

    try {
        flujo = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 1280 },
                height: { ideal: 1280 },
            },
            audio: false,
        });

        if (video.value) {
            video.value.srcObject = flujo;
            await video.value.play();
        }
    } catch {
        modoCamara.value = false;
        error.value =
            'No se pudo acceder a la cámara. Revisa el permiso del navegador o sube una foto desde tu dispositivo.';
    }
}

async function capturar(): Promise<void> {
    const fuente = video.value;

    if (!fuente || !fuente.videoWidth) {
        return;
    }

    try {
        mostrarVistaPrevia(
            await recortarCuadrado(
                fuente,
                fuente.videoWidth,
                fuente.videoHeight,
            ),
        );
    } catch {
        error.value = 'No se pudo tomar la foto. Intenta de nuevo.';
    }
}

function guardar(): void {
    if (!archivoListo.value) {
        return;
    }

    enviando.value = true;
    router.post(
        props.urlSubida,
        { foto: archivoListo.value },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => cerrar(false),
            onError: (errores) => {
                error.value =
                    errores.foto ??
                    'No se pudo guardar la foto. Intenta de nuevo.';
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

onBeforeUnmount(reiniciar);
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-center"
        :class="
            compacto ? '' : 'rounded-2xl border border-border/60 bg-card p-5'
        "
    >
        <div class="relative w-fit">
            <ColaboradorAvatar
                :nombre="nombre"
                :foto-url="fotoUrl"
                tamano="xl"
                class="size-24 rounded-2xl text-2xl"
            />
            <span
                v-if="fotoUrl"
                class="absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full bg-emerald-500 text-white ring-2 ring-background"
            >
                <CheckCircle2 class="size-4" />
            </span>
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div>
                <p class="text-base font-semibold">Foto de perfil</p>
                <p class="text-sm text-muted-foreground">
                    {{
                        fotoUrl
                            ? 'Se muestra en todos los módulos donde apareces.'
                            : 'Aún no hay foto. Súbela o tómala ahora: aparecerá en expedientes, solicitudes, organigrama y cumpleaños.'
                    }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Foto formal de trabajo: de frente, rostro descubierto, fondo
                    claro y buena iluminación.
                </p>
            </div>

            <div v-if="puedeEditar" class="flex flex-wrap gap-2">
                <Button size="sm" @click="abrirCamara">
                    <Camera class="size-4" />
                    Tomar foto
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    @click="inputArchivo?.click()"
                >
                    <ImageUp class="size-4" />
                    {{ fotoUrl ? 'Cambiar foto' : 'Subir foto' }}
                </Button>
            </div>
            <p v-if="error && !dialogoAbierto" class="text-sm text-amber-600">
                {{ error }}
            </p>
        </div>

        <input
            ref="inputArchivo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="alElegirArchivo"
        />
        <input
            ref="inputCamaraNativa"
            type="file"
            accept="image/*"
            capture="user"
            class="hidden"
            @change="alElegirArchivo"
        />

        <Dialog :open="dialogoAbierto" @update:open="cerrar">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ modoCamara ? 'Tomar foto' : 'Revisa tu foto' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            modoCamara
                                ? 'Colócate de frente, centrado en el recuadro, con buena luz.'
                                : 'Así se verá en el sistema. Si no te convence, tómala de nuevo.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="relative mx-auto aspect-square w-full max-w-xs overflow-hidden rounded-2xl bg-muted"
                >
                    <video
                        v-show="modoCamara"
                        ref="video"
                        class="size-full -scale-x-100 object-cover"
                        playsinline
                        muted
                    />
                    <img
                        v-if="!modoCamara && vistaPrevia"
                        :src="vistaPrevia"
                        alt="Vista previa"
                        class="size-full object-cover"
                    />
                    <div
                        v-if="modoCamara"
                        class="pointer-events-none absolute inset-6 rounded-full border-2 border-dashed border-white/70"
                    />
                </div>

                <p v-if="error" class="text-sm text-amber-600">{{ error }}</p>

                <DialogFooter class="gap-2">
                    <template v-if="modoCamara">
                        <Button variant="outline" @click="cerrar(false)">
                            Cancelar
                        </Button>
                        <Button @click="capturar">
                            <Camera class="size-4" />
                            Capturar
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            variant="outline"
                            :disabled="enviando"
                            @click="abrirCamara"
                        >
                            <RotateCcw class="size-4" />
                            Repetir
                        </Button>
                        <Button
                            :disabled="enviando || !archivoListo"
                            @click="guardar"
                        >
                            <Spinner v-if="enviando" />
                            Usar esta foto
                        </Button>
                    </template>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
