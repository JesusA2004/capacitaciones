import Swal from 'sweetalert2';

const base = Swal.mixin({
    confirmButtonColor: '#64d64b',
    cancelButtonColor: '#6b7280',
    reverseButtons: true,
    focusCancel: true,
    buttonsStyling: true,
});

type ConfirmarOpciones = {
    titulo?: string;
    texto?: string;
    confirmarTexto?: string;
    cancelarTexto?: string;
};

async function confirmar(
    opciones: ConfirmarOpciones & { icono: 'warning' | 'question' },
): Promise<boolean> {
    const resultado = await base.fire({
        icon: opciones.icono,
        title: opciones.titulo,
        text: opciones.texto,
        showCancelButton: true,
        confirmButtonText: opciones.confirmarTexto ?? 'Confirmar',
        cancelButtonText: opciones.cancelarTexto ?? 'Cancelar',
    });

    return resultado.isConfirmed;
}

/**
 * Composable centralizado de alertas (SweetAlert2). Es la unica forma en que
 * la aplicacion debe mostrar confirmaciones y avisos modales; no inicializar
 * SweetAlert2 directamente en componentes o paginas.
 */
export function useAlertas() {
    function confirmarEliminacion(entidad = 'este registro'): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Eliminar registro?',
            texto: `Esta acción eliminará ${entidad} y no se puede deshacer.`,
            confirmarTexto: 'Sí, eliminar',
        });
    }

    function confirmarPublicacion(
        entidad = 'este contenido',
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Publicar ahora?',
            texto: `Al publicar, ${entidad} quedará visible según sus reglas de asignación.`,
            confirmarTexto: 'Sí, publicar',
        });
    }

    function confirmarAsignacionMasiva(
        cantidadUsuarios: number,
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Confirmar asignación masiva?',
            texto: `Esta asignación afectará a ${cantidadUsuarios} colaborador(es).`,
            confirmarTexto: 'Sí, asignar',
        });
    }

    function confirmarCambioAsistencia(): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Corregir asistencia?',
            texto: 'Este cambio quedará registrado en la auditoría con tu usuario, la fecha y el motivo.',
            confirmarTexto: 'Sí, corregir',
        });
    }

    function confirmarCierreIntento(): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Finalizar intento?',
            texto: 'Una vez enviado no podrás modificar tus respuestas.',
            confirmarTexto: 'Sí, finalizar',
        });
    }

    function avisarSesionExpirada(): Promise<void> {
        return base
            .fire({
                icon: 'info',
                title: 'Tu sesión ha expirado',
                text: 'Por seguridad debes iniciar sesión de nuevo para continuar.',
                confirmButtonText: 'Ir a iniciar sesión',
                allowOutsideClick: false,
            })
            .then(() => undefined);
    }

    function mostrarExito(mensaje: string, titulo = 'Listo'): Promise<void> {
        return base
            .fire({ icon: 'success', title: titulo, text: mensaje })
            .then(() => undefined);
    }

    function mostrarError(
        mensaje: string,
        titulo = 'Ocurrió un error',
    ): Promise<void> {
        return base
            .fire({ icon: 'error', title: titulo, text: mensaje })
            .then(() => undefined);
    }

    function mostrarAdvertencia(
        mensaje: string,
        titulo = 'Advertencia',
    ): Promise<void> {
        return base
            .fire({ icon: 'warning', title: titulo, text: mensaje })
            .then(() => undefined);
    }

    return {
        confirmarEliminacion,
        confirmarPublicacion,
        confirmarAsignacionMasiva,
        confirmarCambioAsistencia,
        confirmarCierreIntento,
        avisarSesionExpirada,
        mostrarExito,
        mostrarError,
        mostrarAdvertencia,
    };
}
