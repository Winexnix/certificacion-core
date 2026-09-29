<?php

namespace Winex\Certificacion\Certificacion;

use Winex\Certificacion\Core\FirmaElectronica;
use SoapClient;
use SimpleXMLElement;

class SiiSoapClient
{
    private $firmaElectronica;
    private $host;
    private $wsdlCrSeed;
    private $wsdlGetToken;

    /**
     * Tiempos maximos hacia el SII, en segundos. Sin ellos, si el SII se cuelga
     * cada peticion deja un proceso PHP esperando sin limite; en un hosting
     * compartido los procesos se cuentan por cuenta, asi que un SII lento podia
     * dejar sin servicio a todos los sitios de la cuenta. Lo normal es que el SII
     * responda en 1-3 s: estos topes solo se alcanzan si esta caido o colgado.
     */
    // La conexion de file_get_contents usa default_socket_timeout; 'timeout' acota la respuesta.
    private const SEGUNDOS_CONEXION = 10;
    private const SEGUNDOS_RESPUESTA = 45;

    /**
     * $host: 'maullin.sii.cl' (certificacion, default) o 'palena.sii.cl' (produccion).
     */
    public function __construct(FirmaElectronica $firmaElectronica, string $host = 'maullin.sii.cl')
    {
        $this->firmaElectronica = $firmaElectronica;
        $this->host = $host;
        $this->wsdlCrSeed = "https://{$host}/DTEWS/CrSeed.jws?WSDL";
        $this->wsdlGetToken = "https://{$host}/DTEWS/GetTokenFromSeed.jws?WSDL";
    }

    public function getToken(string $privateKey, string $publicKeyLimpia): string
    {
        // Sin cache en disco (antes habia uno: un archivo por host en
        // data/output, sin distinguir empresa ni certificado, en una carpeta
        // 0777). El token queda atado a la identidad de quien firmo la semilla,
        // asi que ese archivo filtraba el token de una empresa hacia el envio de
        // otra si dos procesos caian en la misma ventana de ~50 minutos. Quien
        // llama pide el token una vez y lo reusa en memoria (los scripts bin/ ya lo
        // hacen asi).

        // Verificacion TLS ENCENDIDA. Apagada, cualquiera en el camino (un
        // hosting compartido, una red publica) puede hacerse pasar por el SII,
        // quedarse con el token y leer o alterar lo que se envia. Los
        // certificados de maullin y palena son validos: comprobado con curl y
        // con PHP. Si falla aca, falta el paquete de CA (openssl.cafile).
        $opcionesSsl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];

        // 2. Obtener Semilla
        $xmlSoapSeed = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header/><soapenv:Body><getSeed/></soapenv:Body></soapenv:Envelope>';
        $contextoSeed = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
                'content' => $xmlSoapSeed,
                'timeout' => self::SEGUNDOS_RESPUESTA,
                'ignore_errors' => true
            ],
            'ssl' => $opcionesSsl
        ]);

        $resSemilla = @file_get_contents(str_replace('?WSDL', '', $this->wsdlCrSeed), false, $contextoSeed);

        if ($resSemilla === false) {
            throw new \Exception($this->errorDeConexion('pedir la semilla'));
        }

        if (preg_match('/<SEMILLA>([0-9]+)<\/SEMILLA>/', html_entity_decode($resSemilla), $matches)) {
            $semilla = $matches[1];
        } else {
            throw new \Exception("No se pudo extraer la semilla.");
        }

        // 3. Firmar el SignedInfo
        $body = "<getToken><item><Semilla>{$semilla}</Semilla></item></getToken>";
        $digest = base64_encode(sha1($body, true));
        $signedInfo = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#"><CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/><SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/><Reference URI=""><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms><DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/><DigestValue>' . $digest . '</DigestValue></Reference></SignedInfo>';

        $firma = '';
        openssl_sign($signedInfo, $firma, $privateKey, OPENSSL_ALGO_SHA1);
        $signatureValue = base64_encode($firma);

        $certData = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));
        $modulus = base64_encode($certData['rsa']['n']);
        $exponent = base64_encode($certData['rsa']['e']);

        $xmlSemillaFirmada = '<?xml version="1.0"?>
<getToken>
<item>
<Semilla>' . $semilla . '</Semilla>
</item>
<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
' . $signedInfo . '
<SignatureValue>' . $signatureValue . '</SignatureValue>
<KeyInfo>
<KeyValue>
<RSAKeyValue>
<Modulus>' . $modulus . '</Modulus>
<Exponent>' . $exponent . '</Exponent>
</RSAKeyValue>
</KeyValue>
<X509Data>
<X509Certificate>' . $publicKeyLimpia . '</X509Certificate>
</X509Data>
</KeyInfo>
</Signature>
</getToken>';

        // 4. Canjear Token
        $xmlSoapToken = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header/><soapenv:Body><getToken><pszXml>' . htmlspecialchars($xmlSemillaFirmada) . '</pszXml></getToken></soapenv:Body></soapenv:Envelope>';

        $contextoToken = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
                'content' => $xmlSoapToken,
                'timeout' => self::SEGUNDOS_RESPUESTA,
                'ignore_errors' => true
            ],
            'ssl' => $opcionesSsl
        ]);

        $resToken = @file_get_contents(str_replace('?WSDL', '', $this->wsdlGetToken), false, $contextoToken);

        if ($resToken === false) {
            throw new \Exception($this->errorDeConexion('canjear el token'));
        }

        if (preg_match('/<TOKEN>(.*?)<\/TOKEN>/', html_entity_decode($resToken), $matchesToken)) {
            $nuevoToken = $matchesToken[1];
            return $nuevoToken;
        }

        throw new \Exception("Error del SII: " . $resToken);
    }

    public function enviarDte(string $token, string $rutEmisor, string $rutEnvia, string $rutaArchivoXml): string
    {
        $url = "https://{$this->host}/cgi_dte/UPL/DTEUpload";

        list($rutEmpresa, $dvEmpresa) = explode('-', $rutEmisor);
        list($rutFirma, $dvFirma) = explode('-', $rutEnvia);

        $ch = curl_init($url);

        // Usamos CURLFile nativo de PHP para adjuntar el documento de forma segura
        $postData = [
            'rutSender' => $rutFirma,
            'dvSender' => $dvFirma,
            'rutCompany' => $rutEmpresa,
            'dvCompany' => $dvEmpresa,
            'archivo' => new \CURLFile($rutaArchivoXml, 'text/xml', basename($rutaArchivoXml))
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            // Verificacion TLS encendida: ver getToken().
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
            CURLOPT_TIMEOUT => self::SEGUNDOS_RESPUESTA,
            CURLOPT_HTTPHEADER => [
                "Cookie: TOKEN={$token}",
                "User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.1)",
                "Expect:" // CRÍTICO: Evita que cURL envíe la cabecera 'Expect: 100-continue' que hace colapsar al SII
            ]
        ]);

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);

        if ($error) {
            throw new \Exception("Fallo de red cURL: " . $error);
        }

        if (preg_match('/<TRACKID>(.*?)<\/TRACKID>/', $respuesta, $matches)) {
            return $matches[1];
        }

        throw new \Exception("Error al subir el DTE. Respuesta cruda del SII: \n" . $respuesta);
    }

    /**
     * Estado de un envio por TrackID (QueryEstUp.getEstUp): si el SII ya lo
     * proceso y cuantos documentos acepto, rechazo o dejo con reparos.
     *
     * Solo lectura. Maullin responde 503 de vez en cuando: se informa como
     * excepcion y quien llama vuelve a consultar mas tarde.
     */
    public function estadoEnvio(string $token, string $rutEmpresa, string $trackId): EstadoEnvio
    {
        if (!preg_match('/^\d{1,15}$/', $trackId)) {
            throw new \InvalidArgumentException("TrackID invalido: {$trackId}");
        }

        list($rut, $dv) = explode('-', $rutEmpresa);

        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
            . '<ns:getEstUp xmlns:ns="http://DefaultNamespace">'
            . '<RutCompania>' . htmlspecialchars($rut) . '</RutCompania><DvCompania>' . htmlspecialchars(strtoupper($dv)) . '</DvCompania>'
            . '<TrackId>' . $trackId . '</TrackId><Token>' . htmlspecialchars($token) . '</Token>'
            . '</ns:getEstUp></soapenv:Body></soapenv:Envelope>';

        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
                'content' => $xml,
                'timeout' => self::SEGUNDOS_RESPUESTA,
                'ignore_errors' => true
            ],
            // Verificacion TLS encendida: ver getToken().
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $respuesta = @file_get_contents("https://{$this->host}/DTEWS/QueryEstUp.jws", false, $contexto);

        if ($respuesta === false) {
            throw new \Exception($this->errorDeConexion('consultar el estado del envio'));
        }

        if (!str_contains($respuesta, 'getEstUpReturn')) {
            $detalle = trim(preg_replace('/\s+/', ' ', strip_tags($respuesta)));
            throw new \Exception("El SII no respondio el estado del envio {$trackId}: " . mb_substr($detalle, 0, 200));
        }

        return EstadoEnvio::desdeRespuesta($respuesta, $trackId);
    }

    /**
     * Mensaje util cuando no se pudo conectar. Con la verificacion TLS
     * encendida, la causa mas comun es un PHP sin paquete de CA configurado.
     */
    private function errorDeConexion(string $paso): string
    {
        $detalle = error_get_last()['message'] ?? 'sin detalle';

        return "No se pudo conectar con {$this->host} para {$paso}: {$detalle}. "
            . 'Si el error habla de certificados (certificate verify failed), '
            . 'configura openssl.cafile en el php.ini con un cacert.pem vigente.';
    }
}
