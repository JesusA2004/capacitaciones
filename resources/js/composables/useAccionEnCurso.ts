import { ref } from 'vue';

type Clave = string | number;

type CallbacksVisita = {
    onStart: () => void;
    onFinish: () => void;
};

/**
 * Estado «en curso» de una acción con `router.*`: deshabilita el botón,
 * muestra el spinner e impide el doble clic. La clave distingue filas de una
 * misma lista.
 *
 *   const accion = useAccionEnCurso();
 *   accion.ejecutar(fila.id, (cb) => router.post(url, {}, { ...cb, preserveScroll: true }));
 *   <Button :disabled="accion.ocupado.value" ...>
 *       <Spinner v-if="accion.enCurso(fila.id)" />
 */
export function useAccionEnCurso() {
    const claves = ref(new Set<Clave>());
    const ocupado = ref(false);

    function enCurso(clave: Clave = 'accion'): boolean {
        return claves.value.has(clave);
    }

    function ejecutar(
        clave: Clave,
        visita: (callbacks: CallbacksVisita) => void,
    ): void {
        if (claves.value.has(clave)) {
            return;
        }

        visita({
            onStart: () => {
                claves.value.add(clave);
                ocupado.value = true;
            },
            onFinish: () => {
                claves.value.delete(clave);
                ocupado.value = claves.value.size > 0;
            },
        });
    }

    return { enCurso, ejecutar, ocupado };
}
