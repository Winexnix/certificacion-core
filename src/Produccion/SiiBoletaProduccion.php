<?php

namespace Winex\Certificacion\Produccion;

use Winex\Certificacion\Core\FirmaElectronica;

class SiiBoletaProduccion
{
    private $firmador;
    private $baseUrlAuth;
    private $baseUrlEnvio;
    private $userAgent = 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';

    /**
     * Tiempos maximos hacia el SII, en segundos. Sin ellos, si el SII se cuelga
     * cada peticion deja un proceso PHP esperando sin limite; en un hosting
     * compartido los procesos se cuentan por cuenta, asi que un SII lento podia
     * dejar sin servicio a todos los sitios de la cuenta. Lo normal es que el SII
     * responda en 1-3 s: estos topes solo se alcanzan si esta caido o colgado.
     */
    private const SEGUNDOS_CONEXION = 10;
    private const SEGUNDOS_RESPUESTA = 45;

    /**
     * API REST de Boleta Electronica (distinta del canal SOAP de EnvioDTE/factura).
     * Produccion: api.sii.cl (semilla/token) + rahue.sii.cl (envio).
     * Certificacion: apicert.sii.cl (semilla/token) + pangal.sii.cl (envio).
     */
    public function __construct(FirmaElectronica $firmador, bool $produccion = true)
    {
        $this->firmador = $firmador;
        $this->baseUrlAuth = $produccion ? 'https://api.sii.cl/recursos/v1' : 'https://apicert.sii.cl/recursos/v1';
        $this->baseUrlEnvio = $produccion ? 'https://rahue.sii.cl/recursos/v1' : 'https://pangal.sii.cl/recursos/v1';
    }

    private function curlGet(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
            CURLOPT_TIMEOUT => self::SEGUNDOS_RESPUESTA,
        ]);
        $respuesta = curl_exec($ch);
        $error = curl_error($ch);

        if ($error) {
            throw new \Exception("Fallo de red cURL (GET {$url}): {$error}");
        }

        return $respuesta;
    }

    public function getSemilla(): string
    {
        $respuesta = $this->curlGet("{$this->baseUrlAuth}/boleta.electronica.semilla");

        if (!preg_match('/<SEMILLA>(.*?)<\/SEMILLA>/', $respuesta, $matches)) {
            throw new \Exception("No se pudo obtener la semilla. Respuesta cruda: {$respuesta}");
        }

        return $matches[1];
    }

    /**
     * Canjea una semilla nueva por un token, SIN cache. Pensado para que el
     * llamador (ej. una app web) cachee el token por emisor con su propio driver.
     */
    public function solicitarToken(): string
    {
        $semilla = $this->getSemilla();

        $xmlFirmado = $this->firmador->firmarSemilla($semilla);

        $ch = curl_init("{$this->baseUrlAuth}/boleta.electronica.token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlFirmado,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
            CURLOPT_TIMEOUT => self::SEGUNDOS_RESPUESTA,
        ]);
        $respuesta = curl_exec($ch);
        $error = curl_error($ch);

        if ($error) {
            throw new \Exception("Fallo de red cURL al pedir token: {$error}");
        }

        if (!preg_match('/<TOKEN>(.*?)<\/TOKEN>/', $respuesta, $matches)) {
            throw new \Exception("No se pudo obtener el token. Respuesta cruda del SII: {$respuesta}");
        }

        return $matches[1];
    }

    /**
     * Igual que solicitarToken() pero con cache local en archivo (el token dura
     * 1 hora). Usado por los scripts CLI.
     */
    public function getToken(): string
    {
        $cacheDir = dirname(__DIR__, 2) . '/data/output';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/token_cache_boleta_produccion.txt';
        if (file_exists($cacheFile)) {
            $data = explode('|', file_get_contents($cacheFile));
            if (count($data) === 2 && (time() - (int) $data[0]) < 3000) {
                return $data[1];
            }
        }

        $token = $this->solicitarToken();
        file_put_contents($cacheFile, time() . '|' . $token);

        return $token;
    }

    /**
     * Sube un archivo de boletas firmado (EnvioBOLETA) a produccion/certificacion.
     * Documento real: esto consume folios y genera boletas legalmente validas.
     */
    public function enviarBoleta(string $token, string $rutEmisor, string $rutEnvia, string $rutaArchivoXml): array
    {
        return $this->subirBoleta($token, $rutEmisor, $rutEnvia, $rutaArchivoXml, basename($rutaArchivoXml));
    }

    /**
     * El envio de enviarBoleta(). $nombreArchivo es el nombre con que el SII recibe
     * el archivo en el multipart, que puede no coincidir con la ruta en disco.
     */
    private function subirBoleta(string $token, string $rutEmisor, string $rutEnvia, string $rutaArchivoXml, string $nombreArchivo): array
    {
        [$rutEmpresa, $dvEmpresa] = explode('-', $rutEmisor);
        [$rutFirma, $dvFirma] = explode('-', $rutEnvia);

        $ch = curl_init("{$this->baseUrlEnvio}/boleta.electronica.envio");

        $postData = [
            'rutSender' => $rutFirma,
            'dvSender' => $dvFirma,
            'rutCompany' => $rutEmpresa,
            'dvCompany' => $dvEmpresa,
            'archivo' => new \CURLFile($rutaArchivoXml, 'text/xml', $nombreArchivo),
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                "Cookie: TOKEN={$token}",
                "User-Agent: {$this->userAgent}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
            CURLOPT_TIMEOUT => self::SEGUNDOS_RESPUESTA,
        ]);

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($error) {
            throw new \Exception("Fallo de red cURL al enviar boleta: {$error}");
        }

        $data = json_decode($respuesta, true);

        if ($httpCode !== 200 || !$data) {
            throw new \Exception("Error al subir la boleta (HTTP {$httpCode}). Respuesta cruda: {$respuesta}");
        }

        return $data;
    }

    /**
     * Igual que enviarBoleta() pero recibiendo el XML en memoria; escribe y borra
     * un archivo temporal (cURL necesita un archivo real para el multipart).
     * Se escribe en el mismo archivo que crea tempnam() (exclusivo y 0600): no se
     * le agrega '.xml' a la ruta, porque esa seria otra ruta que nadie reservo y
     * podria existir de antes. El nombre .xml que ve el SII va en el CURLFile.
     */
    public function enviarBoletaXml(string $token, string $rutEmisor, string $rutEnvia, string $xml): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bol_');
        if ($tmp === false) {
            throw new \Exception('No se pudo crear el archivo temporal de la boleta');
        }
        try {
            @chmod($tmp, 0600);
            file_put_contents($tmp, $xml);
            return $this->subirBoleta($token, $rutEmisor, $rutEnvia, $tmp, basename($tmp) . '.xml');
        } finally {
            @unlink($tmp);
        }
    }

    public function consultarEnvio(string $token, string $rutEmisor, string $trackId): array
    {
        [$rut, $dv] = explode('-', $rutEmisor);
        $url = "{$this->baseUrlAuth}/boleta.electronica.envio/{$rut}-{$dv}-{$trackId}";

        $respuesta = $this->curlGet($url, ["Cookie: TOKEN={$token}"]);
        $data = json_decode($respuesta, true);

        if (!$data) {
            throw new \Exception("Respuesta inesperada al consultar el envio: {$respuesta}");
        }

        return $data;
    }

    /**
     * Envia el Reporte de Consumo de Folios (RCOF) firmado.
     */
    public function enviarConsumoFolios(string $token, string $rutEmisor, string $rutEnvia, string $xmlRcof): array
    {
        [$rutEmpresa, $dvEmpresa] = explode('-', $rutEmisor);
        [$rutFirma, $dvFirma] = explode('-', $rutEnvia);

        // Igual que en enviarBoletaXml(): se escribe en el archivo que crea tempnam()
        // y el nombre .xml va solo en el CURLFile. Se borra aunque algo falle.
        $tmp = tempnam(sys_get_temp_dir(), 'rcof_');
        if ($tmp === false) {
            throw new \Exception('No se pudo crear el archivo temporal del RCOF');
        }
        try {
            @chmod($tmp, 0600);
            file_put_contents($tmp, $xmlRcof);

            $ch = curl_init("{$this->baseUrlEnvio}/boleta.electronica.consumo");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'rutSender' => $rutFirma,
                    'dvSender' => $dvFirma,
                    'rutCompany' => $rutEmpresa,
                    'dvCompany' => $dvEmpresa,
                    'archivo' => new \CURLFile($tmp, 'text/xml', basename($tmp) . '.xml'),
                ],
                CURLOPT_HTTPHEADER => [
                    "Cookie: TOKEN={$token}",
                    "User-Agent: {$this->userAgent}",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
                CURLOPT_TIMEOUT => self::SEGUNDOS_RESPUESTA,
            ]);

            $respuesta = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            @unlink($tmp);
        }

        if ($error) {
            throw new \Exception("Fallo de red cURL al enviar RCOF: {$error}");
        }

        $data = json_decode($respuesta, true);

        if ($httpCode !== 200 || !$data) {
            throw new \Exception("Error al subir el RCOF (HTTP {$httpCode}). Respuesta cruda: {$respuesta}");
        }

        return $data;
    }
}
