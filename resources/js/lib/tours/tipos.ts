export type PasoTour = {
    /** Selector CSS del elemento real de la pantalla que se resalta. */
    selector: string;
    titulo: string;
    texto: string;
};

export type Tour = {
    /** Identificador único y estable: se usa para recordar "ya lo vi". */
    id: string;
    titulo: string;
    pasos: PasoTour[];
};
