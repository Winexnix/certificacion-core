<?php

namespace Winex\Certificacion\Postulacion;

/**
 * Lo que se declara al postular una empresa a boleta electronica en maullin
 * (`pe_datos_empresa`). Factura no se postula desde aca.
 *
 * Las reglas son las del formulario del SII (validadte.js): si no se cumplen, el
 * SII rechaza la pagina, asi que se cortan antes de salir.
 */
final class DatosPostulacion
{
    public readonly Rut $empresa;

    public readonly Rut $administrador;

    /**
     * @param  string  $rutEmpresa  empresa que postula
     * @param  string  $rutAdministrador  usuario administrador; tiene que ser quien se autentica
     *                                    para despues ver/declarar el avance
     * @param  string  $url  opcional; si va, tiene que empezar con "www."
     *
     * @throws PostulacionException
     */
    public function __construct(
        string $rutEmpresa,
        string $rutAdministrador,
        public readonly string $correoAdministrador,
        public readonly string $correoContactoSii,
        public readonly string $correoIntercambio,
        public readonly string $nombreSoftware,
        public readonly bool $boletaExenta = true,
        public readonly string $url = '',
    ) {
        $this->empresa = Rut::de($rutEmpresa, 'RUT de la empresa');
        $this->administrador = Rut::de($rutAdministrador, 'RUT del usuario administrador');

        self::correo($correoAdministrador, 'correo del usuario administrador');
        self::correo($correoContactoSii, 'correo de contacto con el SII');
        self::correo($correoIntercambio, 'correo de intercambio');

        if (trim($nombreSoftware) === '' || mb_strlen($nombreSoftware) > 30) {
            throw new PostulacionException('El nombre del software es obligatorio y tiene como maximo 30 caracteres.');
        }
        if ($url !== '' && (! str_starts_with($url, 'www.') || mb_strlen($url) > 100)) {
            throw new PostulacionException('La URL tiene que empezar con "www." y tener como maximo 100 caracteres.');
        }
    }

    /**
     * Regla de `esValidoMail2` del SII: sin espacios ni ! " # $ % & / ( ) = ? + * | < > [ ] ;
     * ni letras con tilde, un solo @ y al menos un punto despues. Maximo 50.
     *
     * @throws PostulacionException
     */
    public static function correo(string $correo, string $campo = 'correo'): void
    {
        $valido = strlen($correo) <= 50
            && preg_match('/^[\x21-\x7E]+$/', $correo) === 1
            && strpbrk($correo, '!"#$%&/()=?+*|<>[];') === false
            && substr_count($correo, '@') === 1
            && ! str_contains($correo, '@.')
            && ! in_array($correo[0], ['@', '.'], true)
            && ! in_array(substr($correo, -1), ['@', '.'], true)
            && str_contains(substr($correo, strpos($correo, '@') + 1), '.');

        if (! $valido) {
            throw new PostulacionException(
                "El {$campo} no lo acepta el SII: {$correo}. Sin + ni simbolos ni tildes, un solo @ y maximo 50 caracteres.",
            );
        }
    }
}
