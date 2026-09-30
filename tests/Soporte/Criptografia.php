<?php

namespace Winex\Certificacion\Tests\Soporte;

/**
 * Material criptografico de MENTIRA para los tests: claves RSA, certificados
 * autofirmados, .pfx y CAF generados al vuelo. Nada de esto es del SII ni de
 * ningun contribuyente real.
 *
 * Usa su propio openssl.cnf minimo para no depender de la configuracion del
 * equipo (en Windows, el del sistema suele estar roto o no existir).
 */
final class Criptografia
{
    private static ?string $config = null;

    private static ?\OpenSSLAsymmetricKey $llave = null;

    private static ?string $certificadoPem = null;

    public static function opciones(): array
    {
        if (self::$config === null) {
            self::$config = tempnam(sys_get_temp_dir(), 'cnf_');
            file_put_contents(self::$config, "[req]\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\n[v3]\nbasicConstraints=CA:TRUE\n");
            register_shutdown_function(static fn () => @unlink(self::$config));
        }

        return ['config' => self::$config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    }

    public static function llave(): \OpenSSLAsymmetricKey
    {
        return self::$llave ??= openssl_pkey_new(self::opciones())
            ?: throw new \RuntimeException('No se pudo generar la llave RSA: '.openssl_error_string());
    }

    /** Llave privada en PEM (sin cifrar). */
    public static function llavePem(): string
    {
        openssl_pkey_export(self::llave(), $pem, null, self::opciones());

        return $pem;
    }

    /** Certificado autofirmado en PEM, con CN "Titular de Prueba". */
    public static function certificadoPem(): string
    {
        if (self::$certificadoPem === null) {
            $llave = self::llave();
            $csr = openssl_csr_new(['commonName' => 'Titular de Prueba'], $llave, self::opciones());
            $cert = openssl_csr_sign($csr, null, $llave, 30, self::opciones());
            openssl_x509_export($cert, self::$certificadoPem);
        }

        return self::$certificadoPem;
    }

    /** Contenido binario de un .pfx protegido con la clave indicada. */
    public static function pfx(string $clave): string
    {
        openssl_pkcs12_export(self::certificadoPem(), $pfx, self::llave(), $clave, self::opciones());

        return $pfx;
    }

    public static function clavePublicaPem(): string
    {
        return openssl_pkey_get_details(self::llave())['key'];
    }

    /**
     * CAF de prueba. El <RSASK> va en PKCS#1 (como los del SII); la <FRMA> es
     * relleno porque solo el SII podria validarla.
     */
    public static function caf(string $rut = '76123456-0', string $razon = 'EMPRESA DE PRUEBA SPA', int $desde = 1, int $hasta = 10): string
    {
        $pem = self::llavePem();
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem));
        // OpenSSL 3 exporta PKCS#8 (hay que quitar su cabecera de 26 bytes en una
        // RSA de 2048 bits); OpenSSL 1.x ya exporta PKCS#1.
        $pkcs1 = str_contains($pem, 'BEGIN RSA PRIVATE KEY') ? $der : substr($der, 26);
        $cuerpo = chunk_split(base64_encode($pkcs1), 64, "\n");

        return '<?xml version="1.0"?>'."\n"
            .'<AUTORIZACION>'."\n"
            .'<CAF version="1.0">'."\n"
            .'<DA>'."\n"
            ."<RE>{$rut}</RE>\n<RS>{$razon}</RS>\n<TD>39</TD>\n<RNG><D>{$desde}</D><H>{$hasta}</H></RNG>\n"
            ."<FA>2026-09-01</FA>\n<IDK>100</IDK>\n"
            ."</DA>\n"
            .'<FRMA algoritmo="SHA1withRSA">'.base64_encode(str_repeat('x', 64)).'</FRMA>'."\n"
            ."</CAF>\n"
            ."<RSASK>-----BEGIN RSA PRIVATE KEY-----\n{$cuerpo}-----END RSA PRIVATE KEY-----\n</RSASK>\n"
            ."</AUTORIZACION>\n";
    }
}
