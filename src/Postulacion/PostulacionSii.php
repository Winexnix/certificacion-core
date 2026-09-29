<?php

namespace Winex\Certificacion\Postulacion;

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Folios\Ambiente;
use Winex\Certificacion\Folios\SesionCaducaException;
use Winex\Certificacion\Folios\SesionSii;
use Winex\Certificacion\Folios\SolicitudFolios;
use Winex\Certificacion\Folios\SolicitudFoliosException;
use Winex\Certificacion\Folios\TransporteCurl;
use Winex\Certificacion\Folios\TransporteHttp;

/**
 * Postula una empresa a boleta electronica en el ambiente de certificacion
 * (maullin), replicando el asistente `cvc_cgi/dte/pe_*`:
 *
 *   1. POST pe_ingrut              -> aceptar condiciones, pide el RUT
 *   2. POST pe_datos_empresa       -> 0..2 paginas de aviso ("Continuar") y el formulario
 *   3. POST pe_confirma            -> pantalla de confirmacion (el SII repite los datos)
 *   4. POST pe_graba_postulacion   -> * GRABA la postulacion *
 *
 * La postulacion se puede repetir (vuelve a dejar la empresa en certificacion con
 * los datos nuevos). El usuario autenticado tiene que ser representante legal.
 *
 * Candados: todo va a maullin.sii.cl, y antes de grabar se revisa que la
 * confirmacion del SII traiga exactamente los datos enviados.
 */
final class PostulacionSii
{
    private const HOST = 'maullin.sii.cl';

    private const SEGUNDOS = 70;

    /** Textos que delatan que el SII rechazo el paso. */
    private const MARCADORES_ERROR = [
        'El código de este mensaje es',
        'no está autorizado',
        'no es representante',
        'No ha sido posible completar su solicitud',
        'Usted no tiene',
    ];

    private TransporteHttp $transporte;

    private NavegadorSii $navegador;

    public function __construct(?TransporteHttp $transporte = null)
    {
        $this->transporte = $transporte ?? new TransporteCurl;
        $this->navegador = new NavegadorSii($this->transporte, self::HOST);
    }

    /**
     * Login AUT2000 con el certificado (el mismo de la solicitud de folios). La
     * sesion sirve tambien para DeclaracionBoletas.
     *
     * @throws PostulacionException
     */
    public function autenticar(Certificate $cert): SesionSii
    {
        try {
            return (new SolicitudFolios(Ambiente::Certificacion, $this->transporte))->autenticar($cert);
        } catch (SolicitudFoliosException $e) {
            throw new PostulacionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SesionCaducaException si la sesion ya no sirve (antes de grabar)
     * @throws PostulacionException
     */
    public function postular(SesionSii $sesion, DatosPostulacion $datos): ResultadoPostulacion
    {
        $base = 'https://'.self::HOST.'/cvc_cgi/dte';
        $condiciones = 'https://'.self::HOST.'/cvc/dte/pe_condiciones.html';

        // 1. Aceptar condiciones.
        $html = $this->navegador->pagina($sesion, 'POST', "{$base}/pe_ingrut", ['ACEPTAR' => 'Aceptar Condiciones'], $condiciones, self::SEGUNDOS);
        $form = $this->formHacia($html, 'pe_datos_empresa', 'El SII no mostro el formulario del RUT de la empresa.');

        // 2. RUT de la empresa -> avisos -> formulario de datos.
        $referer = "{$base}/pe_ingrut";
        $html = $this->enviar($sesion, $base, $form, [
            'RUT_EMP' => $datos->empresa->numero,
            'DV_EMP' => $datos->empresa->dv,
        ], $referer);
        $referer = "{$base}/pe_datos_empresa";

        $avisos = [];
        for ($i = 0; ; $i++) {
            $form = NavegadorSii::form($html);

            if ($form !== null && str_contains($form['action'], 'pe_confirma')) {
                break;
            }
            if ($i >= 3 || $form === null || ! str_contains($form['action'], 'pe_datos_empresa')) {
                throw new PostulacionException('El SII no llego al formulario de postulacion. '.$this->mensaje($html));
            }

            // Pagina de aviso (p. ej. "perdera su calidad de usuario del facturador
            // gratuito"): se informa y se sigue, como hace el usuario en el portal.
            $avisos[] = $this->aviso($html);
            $html = $this->enviar($sesion, $base, $form, ['ACEPTAR' => 'Continuar'], $referer);
        }

        // 3. Datos. Se parte de lo que manda el SII (hidden) y se fijan los campos
        // propios; factura queda sin marcar.
        $valores = [
            'RUT_USU' => $datos->administrador->numero,
            'DV_USU' => $datos->administrador->dv,
            'MAIL_SUP' => $datos->correoAdministrador,
            'MAIL_SII' => $datos->correoContactoSii,
            'MAIL_DTE' => $datos->correoIntercambio,
            'URL' => $datos->url,
            'NOM_SW' => $datos->nombreSoftware,
            'ESBOL' => 'S',
            'BOLELEC' => 'S',
        ];
        if ($datos->boletaExenta) {
            $valores['BOLEXEN'] = 'S';
        }

        $inputs = array_diff_key($form['inputs'], array_flip(['ESFAC', 'FACT', 'NC', 'ND', 'SET03', 'SET06', 'SET11', 'SET84', 'SET72', 'BOLEXEN']));
        $form['inputs'] = $inputs;
        $html = $this->enviar($sesion, $base, $form, $valores + ['GRABAR' => 'Confirmar Datos'], $referer);
        $referer = "{$base}/pe_confirma";

        // 4. Confirmacion: el SII repite los datos en hidden. Tienen que ser los
        // enviados antes de grabar.
        $confirma = $this->formHacia($html, 'pe_graba_postulacion', 'El SII no mostro la confirmacion de la postulacion.');
        $esperado = [
            'RUT_EMP' => $datos->empresa->numero,
            'DV_EMP' => $datos->empresa->dv,
            'RUT_USU' => $datos->administrador->numero,
            'DV_USU' => $datos->administrador->dv,
            'MAIL_SUP' => $datos->correoAdministrador,
            'MAIL_SII' => $datos->correoContactoSii,
            'MAIL_DTE' => $datos->correoIntercambio,
            'NOM_SW' => $datos->nombreSoftware,
            'ESBOL' => 'S',
            'BOLELEC' => 'S',
            'BOLEXEN' => $datos->boletaExenta ? 'S' : null,
            'ESFAC' => 'N',
        ];
        foreach ($esperado as $campo => $valor) {
            $recibido = $confirma['inputs'][$campo] ?? null;
            $calza = $valor === null ? in_array($recibido, [null, '', 'N'], true) : strcasecmp((string) $recibido, $valor) === 0;

            if (! $calza) {
                throw new PostulacionException(
                    "Postulacion detenida antes de grabar: la confirmacion del SII trae {$campo}=".var_export($recibido, true)
                    .', distinto de lo enviado.',
                );
            }
        }

        $html = $this->enviar($sesion, $base, $confirma, ['CONF' => 'Confirmar Postulación'], $referer);

        if (mb_stripos(NavegadorSii::texto($html, PHP_INT_MAX), 'ha sido aceptada') === false) {
            throw new PostulacionException('El SII no confirmo la postulacion. '.$this->mensaje($html));
        }

        return new ResultadoPostulacion($datos->empresa, $avisos, NavegadorSii::texto($html, 600));
    }

    /**
     * POST del form con sus inputs + `$valores`. `pe_confirma` usa "Confirmar
     * Postulación" con tilde: el SII espera ISO-8859-1.
     *
     * @param  array{action: string, inputs: array<string, string>}  $form
     * @param  array<string, string>  $valores
     */
    private function enviar(SesionSii $sesion, string $base, array $form, array $valores, string $referer): string
    {
        $campos = array_map(
            fn (string $v) => mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8'),
            array_merge($form['inputs'], $valores),
        );

        $html = $this->navegador->pagina($sesion, 'POST', NavegadorSii::resolverUrl("{$base}/", $form['action']), $campos, $referer, self::SEGUNDOS);

        // Sobre el texto ya decodificado: el SII escribe las tildes como entidades.
        $texto = NavegadorSii::texto($html, PHP_INT_MAX);
        foreach (self::MARCADORES_ERROR as $marcador) {
            if (mb_stripos($texto, $marcador) !== false) {
                throw new PostulacionException('El SII rechazo la postulacion. '.$this->mensaje($html));
            }
        }

        return $html;
    }

    /** @return array{action: string, inputs: array<string, string>} */
    private function formHacia(string $html, string $accion, string $error): array
    {
        $form = NavegadorSii::form($html);

        if ($form === null || ! str_contains($form['action'], $accion)) {
            throw new PostulacionException("{$error} ".$this->mensaje($html));
        }

        return $form;
    }

    private function aviso(string $html): string
    {
        $texto = NavegadorSii::texto($html, 2000);

        if (preg_match('/Sr\. Contribuyente:\s*(.+?)(?:Continuar|Salir|$)/u', $texto, $m)) {
            return trim($m[1]);
        }

        return mb_substr($texto, 0, 400);
    }

    private function mensaje(string $html): string
    {
        $texto = NavegadorSii::texto($html);

        return $texto === '' ? '' : "(SII: {$texto})";
    }
}
