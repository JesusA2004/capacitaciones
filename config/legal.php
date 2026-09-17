<?php

// Texto de referencia para las pantallas de "avisos y consentimientos" del
// expediente (antes eran checkboxes sin nada que leer detrás). RH/Legal debe
// revisar y personalizar esta redacción antes de usarla como aviso oficial
// de la empresa — este es un punto de partida razonable en español mexicano
// alineado a la Ley Federal de Protección de Datos Personales en Posesión de
// los Particulares (LFPDPPP), no un documento validado por un abogado.

return [
    'aviso_privacidad' => <<<'TEXTO'
        MR. LANA, responsable del tratamiento de tus datos personales, hace de tu
        conocimiento el presente Aviso de Privacidad de conformidad con la Ley
        Federal de Protección de Datos Personales en Posesión de los Particulares.

        Los datos personales que recabamos (nombre, domicilio, contacto,
        identificación oficial, CURP, RFC, NSS, información laboral y, en su caso,
        datos sensibles como información médica relacionada con tu empleo) se
        utilizan para las siguientes finalidades:

        - Formalizar y administrar tu relación laboral.
        - Cumplir con obligaciones fiscales, de seguridad social y laborales.
        - Gestionar nómina, prestaciones y capacitación.
        - Contactarte en caso de emergencia.

        Puedes ejercer tus derechos de acceso, rectificación, cancelación y
        oposición (derechos ARCO) directamente con el área de Recursos Humanos.

        Este aviso puede sufrir modificaciones; cualquier cambio relevante te será
        notificado por los medios de contacto que tengamos registrados.
        TEXTO,

    'consentimiento_datos' => <<<'TEXTO'
        Al aceptar este consentimiento, autorizas a MR. LANA a tratar tus datos
        personales (incluyendo, en su caso, datos personales sensibles
        estrictamente necesarios para tu relación laboral) conforme a las
        finalidades descritas en el Aviso de Privacidad.

        Este consentimiento es indispensable para poder formalizar tu alta ante
        el IMSS, procesar tu nómina y administrar tu expediente laboral. Puedes
        revocarlo en cualquier momento contactando a Recursos Humanos, sin que
        eso afecte el tratamiento previo realizado conforme a la ley.
        TEXTO,
];
