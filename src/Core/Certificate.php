<?php

namespace Winex\Certificacion\Core;

class Certificate
{
    /** Variable de entorno por la que se le pasa la clave del .pfx a `openssl`. */
    private const ENV_CLAVE = 'WINEX_PFX_PASS';

    private $privateKey;
    private $publicKey;
    private $openSslPath;
    /** @var string|null Hoja + intermedios en PEM (para el handshake TLS mutuo); null si el .pfx solo trae la hoja. */
    private $chainPem = null;

    /**
     * @param string $pfx         Ruta al .pfx (o .pem), o su contenido binario si $esContenido = true.
     * @param bool   $esContenido true para pasar los bytes directamente (uso desde API/BD).
     */
    public function __construct(string $pfx, string $password, string $openSslPath = 'openssl', bool $esContenido = false)
    {
        $this->openSslPath = $openSslPath;

        if ($esContenido) {
            $contenido = $pfx;
        } else {
            if (!file_exists($pfx)) {
                throw new \Exception("El archivo del certificado no existe en la ruta: {$pfx}");
            }
            $contenido = file_get_contents($pfx);
        }

        $this->cargarDesdeContenido($contenido, $password);
    }

    /**
     * Construye desde los bytes del .pfx/.pem (sin tocar el disco salvo el fallback legacy).
     */
    public static function fromContents(string $pfxBinary, string $password, string $openSslPath = 'openssl'): self
    {
        return new self($pfxBinary, $password, $openSslPath, true);
    }

    private function cargarDesdeContenido(string $contenido, string $password): void
    {
        // Caso PEM: el .pfx ya se convirtió a texto (clave + certificado). Evita
        // por completo PKCS#12 y el proveedor "legacy" de OpenSSL 3 — útil en
        // hosts compartidos donde no hay binario openssl con -legacy o `shell_exec`
        // está deshabilitado. Ver cómo convertirlo en DEPLOY.md.
        if (str_contains($contenido, '-----BEGIN ')) {
            $this->cargarDesdePem($contenido, $password);
            return;
        }

        // Caso PKCS#12 (.pfx). Intento 1: lector nativo de PHP.
        $certs = [];
        if (openssl_pkcs12_read($contenido, $certs, $password)) {
            $this->privateKey = $certs['pkey'];
            $this->publicKey = $certs['cert'];
            if (!empty($certs['extracerts'])) {
                $this->chainPem = rtrim($certs['cert']) . "\n" . implode("\n", array_map('trim', $certs['extracerts'])) . "\n";
            }
            return;
        }

        // Intento 2: los .pfx del SII usan cifrado legacy (pbeWithSHAAnd40BitRC2-CBC)
        // que OpenSSL 3 no lee de forma nativa ("digital envelope routines::unsupported").
        // Fallback al binario `openssl` con el proveedor legacy.
        $tmp = tempnam(sys_get_temp_dir(), 'pfx_');
        try {
            file_put_contents($tmp, $contenido);
            @chmod($tmp, 0600);
            $this->extractLegacyViaCli($tmp, $password);
        } finally {
            @unlink($tmp);
        }
    }

    private function cargarDesdePem(string $pem, string $password): void
    {
        // Un PEM convertido en Windows llega con CRLF. Normalizar deja el
        // material identico venga de un .pfx o de un .pem, para que cambiar de
        // formato no altere nada aguas abajo (el <X509Certificate> de la firma
        // y el certificado del handshake TLS mutuo).
        $pem = str_replace("\r\n", "\n", $pem);

        $key = @openssl_pkey_get_private($pem, $password);
        if ($key === false) {
            $key = @openssl_pkey_get_private($pem);
        }
        if ($key === false) {
            throw new \Exception('El PEM no tiene una clave privada legible (¿passphrase incorrecta?).');
        }
        if (!@openssl_pkey_export($key, $this->privateKey)) {
            // Entorno sin openssl.cnf utilizable: usar el bloque PEM tal cual
            // (FirmaElectronica lo pasa directo a openssl_sign / openssl_pkey_get_private).
            if (!preg_match('/-----BEGIN ([A-Z0-9 ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----/s', $pem, $mk)) {
                throw new \Exception('No se pudo extraer la clave privada del PEM.');
            }

            // Un bloque ENCRYPTED no sirve tal cual: openssl_sign no puede
            // abrirlo sin la passphrase y fallaria en silencio al firmar cada
            // DTE. Mejor reventar acá, al cargar el certificado, con una salida.
            if (str_contains($mk[1], 'ENCRYPTED')) {
                throw new \Exception(
                    'La clave privada del PEM está cifrada y este PHP no puede reexportarla '
                    . '(openssl_pkey_export falló: revisa OPENSSL_CONF / openssl.cnf). '
                    . 'Vuelve a convertir el certificado dejando la clave sin cifrar, '
                    . 'agregando -nodes: openssl pkcs12 -legacy -nodes -in cert.pfx -out cert.pem'
                );
            }

            $this->privateKey = $mk[0];
        }

        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
            throw new \Exception('El PEM no contiene el bloque CERTIFICATE.');
        }
        $this->publicKey = $m[0][0];
        if (count($m[0]) > 1) {
            $this->chainPem = implode("\n", $m[0]) . "\n";
        }
    }

    private function extractLegacyViaCli(string $pfxPath, string $password): void
    {
        // OPENSSL_MODULES_PATH es opcional: solo hace falta si este OpenSSL no
        // encuentra el proveedor "legacy" por si solo (p. ej. un OpenSSL portable
        // de Windows con legacy.dll junto al .exe). En Linux con el OpenSSL del
        // sistema NO hace falta: `-legacy` lo resuelve solo. Antes esto se
        // calculaba (mal) asumiendo el layout de Windows, y eso rompia la carga
        // del proveedor "legacy" en Linux.
        if ($modulesPath = getenv('OPENSSL_MODULES_PATH')) {
            putenv("OPENSSL_MODULES={$modulesPath}");
        }

        // -legacy para OpenSSL 3; si el binario es 1.x esa opcion no existe y se
        // reintenta sin ella (ahi el .pfx legacy se lee de forma nativa).
        $ultimaSalida = '';
        foreach ([true, false] as $legacy) {
            $rawKey = $this->openssl(['-nocerts', '-nodes'], $legacy, $pfxPath, $password);
            $rawCert = $this->openssl(['-clcerts', '-nokeys'], $legacy, $pfxPath, $password);

            $priv = $this->extractPemBlock($rawKey);
            $pub = $this->extractPemBlock($rawCert);

            if ($priv !== '' && $pub !== '') {
                $this->privateKey = $priv;
                $this->publicKey = $pub;

                // Cadena completa (hoja + intermedios) para el handshake TLS mutuo:
                // sin `-clcerts`, `pkcs12 -nokeys` vuelca todos los certificados del .pfx.
                $rawChain = $this->openssl(['-nokeys'], $legacy, $pfxPath, $password);
                if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $rawChain, $todos) && count($todos[0]) > 1) {
                    $this->chainPem = implode("\n", $todos[0]) . "\n";
                }

                return;
            }
            // Primero la salida del certificado: la de `-nocerts -nodes` trae la
            // llave privada en claro si alcanzo a extraerla.
            $ultimaSalida = trim($rawCert ?: $rawKey);
            $sinLegacy = ($sinLegacy ?? false) || str_contains($rawKey . $rawCert, 'unable to load provider legacy');
        }

        // La salida cruda de OpenSSL casi siempre dice el motivo real: password
        // incorrecta, proc_open/shell_exec deshabilitados, o proveedor "legacy" ausente.
        // Nunca con material PEM: el mensaje termina en logs.
        $ultimaSalida = self::sinMaterialPem($ultimaSalida);
        $detalle = $ultimaSalida !== ''
            ? substr($ultimaSalida, 0, 500)
            : '(sin salida — ¿proc_open y shell_exec deshabilitados en este hosting?)';

        if ($sinLegacy ?? false) {
            $detalle = 'OpenSSL no encuentra el proveedor "legacy": define OPENSSL_MODULES_PATH con la carpeta '
                . 'que tiene legacy.dll / legacy.so. ' . $detalle;
        }

        throw new \Exception(
            'Fallo al extraer las llaves del certificado vía OpenSSL CLI. '
            . 'Alternativa: subir el certificado convertido a PEM (ver DEPLOY.md). '
            . "Salida: {$detalle}"
        );
    }

    /**
     * Salida de OpenSSL apta para un mensaje de error: sin bloques PEM (llave
     * privada o certificados, aunque vengan cortados) ni los atributos de bolsa.
     */
    public static function sinMaterialPem(string $salida): string
    {
        $salida = str_replace("\r\n", "\n", $salida);
        $salida = (string) preg_replace('/-----BEGIN [^-]*-----.*?(?:-----END [^-]*-----|\z)/s', '[PEM omitido]', $salida);
        $salida = (string) preg_replace('/^(?:Bag|Key) Attributes.*(?:\n[ \t]+.*)*$/m', '', $salida);
        $salida = (string) preg_replace('/^[A-Za-z0-9+\/=]{40,}$/m', '', $salida);

        return trim((string) preg_replace("/\n{2,}/", "\n", $salida));
    }

    /**
     * Argumentos del comando `openssl pkcs12`. La clave NUNCA va aca dentro.
     *
     * En Linux `/proc/<pid>/cmdline` lo lee cualquier usuario del sistema —es lo
     * que muestra `ps`—, asi que un `-passin pass:LACLAVE` deja la contrasena del
     * certificado digital a la vista de las demas cuentas de un hosting
     * compartido, y esto corre varias veces por cada boleta emitida. La clave
     * viaja por el entorno (`env:`), que en `/proc/<pid>/environ` es 0400 del
     * dueno del proceso, o por un archivo temporal 0600 si no hay `proc_open`.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function comandoPkcs12(string $openSslPath, array $args, bool $legacy, string $origenClave): array
    {
        $comando = array_merge(
            [$openSslPath, 'pkcs12', '-in', '__PFX__'],
            $args,
            ['-passin', $origenClave],
        );

        if ($legacy) {
            $comando[] = '-legacy';
        }

        return $comando;
    }

    /**
     * Corre `openssl pkcs12` y devuelve su salida (stdout + stderr).
     *
     * @param  list<string>  $args
     */
    private function openssl(array $args, bool $legacy, string $pfxPath, string $password): string
    {
        if (function_exists('proc_open')) {
            return $this->opensslConEntorno($args, $legacy, $pfxPath, $password);
        }

        return $this->opensslConArchivo($args, $legacy, $pfxPath, $password);
    }

    /**
     * Camino preferido: la clave viaja en el entorno del proceso hijo y no toca
     * ni la linea de comandos ni el disco.
     *
     * @param  list<string>  $args
     */
    private function opensslConEntorno(array $args, bool $legacy, string $pfxPath, string $password): string
    {
        $comando = self::comandoPkcs12($this->openSslPath, $args, $legacy, 'env:' . self::ENV_CLAVE);
        $comando = $this->conRutaPfx($comando, $pfxPath);

        $entorno = [self::ENV_CLAVE => $password] + getenv();
        $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proceso = @proc_open($comando, $descriptores, $pipes, null, $entorno);

        if (!is_resource($proceso)) {
            return '';
        }

        $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proceso);

        return (string) $salida;
    }

    /**
     * Respaldo para hostings con `proc_open` deshabilitado: la clave va en un
     * archivo temporal 0600 que se borra enseguida. Peor que el entorno, pero
     * sigue sin quedar a la vista en `ps`.
     *
     * @param  list<string>  $args
     */
    private function opensslConArchivo(array $args, bool $legacy, string $pfxPath, string $password): string
    {
        $archivoClave = tempnam(sys_get_temp_dir(), 'pfxpw_');

        if ($archivoClave === false) {
            return '';
        }

        try {
            @chmod($archivoClave, 0600);
            file_put_contents($archivoClave, $password);

            $comando = self::comandoPkcs12(
                $this->openSslPath,
                $args,
                $legacy,
                'file:' . $archivoClave,
            );
            $comando = $this->conRutaPfx($comando, $pfxPath);

            $linea = implode(' ', array_map('escapeshellarg', $comando)) . ' 2>&1';

            return (string) shell_exec($linea);
        } finally {
            @unlink($archivoClave);
        }
    }

    /**
     * @param  list<string>  $comando
     * @return list<string>
     */
    private function conRutaPfx(array $comando, string $pfxPath): array
    {
        return array_map(fn ($arg) => $arg === '__PFX__' ? $pfxPath : $arg, $comando);
    }

    private function extractPemBlock(?string $consoleOutput): string
    {
        if (!$consoleOutput) {
            return '';
        }

        // Normalizar a LF: en Windows las tuberias de proc_open devuelven CRLF
        // donde shell_exec devolvia LF. El bloque tiene que salir igual en toda
        // plataforma, porque la llave publica se limpia y se incrusta en el
        // <X509Certificate> de la firma: un \r colado ahi la invalida.
        $consoleOutput = str_replace("\r\n", "\n", $consoleOutput);

        if (preg_match('/-----BEGIN (.*?)-----(.*?)-----END \1-----/s', $consoleOutput, $matches)) {
            return $matches[0];
        }
        return '';
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Hoja + intermedios que venian en el .pfx, en PEM. Si solo habia hoja,
     * devuelve lo mismo que getPublicKey(). Uso: cliente TLS mutuo (CURLOPT_SSLCERT).
     */
    public function getPublicKeyChain(): string
    {
        return $this->chainPem ?: $this->publicKey;
    }

    /**
     * Todo el material del certificado en un solo PEM: clave privada (sin cifrar)
     * + hoja + intermedios, en ese orden.
     *
     * Sirve para guardar un .pfx ya convertido y no volver a pagar el precio de
     * PKCS#12 nunca mas: los .pfx del SII usan cifrado legacy (RC2-40) que PHP no
     * lee de forma nativa, asi que cada carga tiene que invocar el binario
     * `openssl` — tres procesos, y dos cargas por boleta emitida. Cargando desde
     * este PEM no se invoca nada.
     *
     * La clave va SIN cifrar a proposito: un PEM con la clave cifrada no se puede
     * usar si `openssl_pkey_export` falla (hosting sin openssl.cnf utilizable).
     * Quien guarde esto debe cifrarlo en reposo por su cuenta.
     *
     * La hoja va SIEMPRE primero: quien lo relea toma el primer CERTIFICATE como
     * el del titular, y el SII rechaza el handshake TLS si la cadena viene
     * desordenada o incompleta.
     */
    public function toPem(): string
    {
        $certificados = [trim($this->publicKey)];

        if ($this->chainPem !== null
            && preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $this->chainPem, $m)) {
            foreach ($m[0] as $certificado) {
                $certificado = trim($certificado);

                if (!in_array($certificado, $certificados, true)) {
                    $certificados[] = $certificado;
                }
            }
        }

        return trim($this->privateKey) . "\n" . implode("\n", $certificados) . "\n";
    }

    /**
     * Metadatos del certificado (CN y vigencia), utiles para guardarlos al subirlo.
     */
    public function getInfo(): array
    {
        $parsed = openssl_x509_parse($this->publicKey) ?: [];

        return [
            'cn' => $parsed['subject']['CN'] ?? null,
            'not_before' => isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', $parsed['validFrom_time_t']) : null,
            'not_after' => isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', $parsed['validTo_time_t']) : null,
        ];
    }
}
