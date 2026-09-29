<?php

/**
 * Arranque comun para todos los scripts de bin/.
 * Define BASE_PATH (raiz del proyecto), carga el autoload y el .env, fija la
 * zona horaria y deja el directorio de trabajo en la raiz para que las rutas
 * relativas (data/...) funcionen sin importar desde donde se invoque el script.
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(BASE_PATH)->load();

date_default_timezone_set('America/Santiago');

chdir(BASE_PATH);

/**
 * Datos del emisor para los scripts de ejemplo, leidos del .env
 * (SII_RUT_EMPRESA, SII_RUT_FIRMA, EMISOR_RAZON_SOCIAL, EMISOR_GIRO,
 * EMISOR_DIRECCION, EMISOR_COMUNA). Ver .env.example.
 *
 * @return array{rutEmpresa: string, rutFirma: string, emisor: array<string, string>}
 */
function datosEmisorDesdeEnv(): array
{
    foreach (['SII_RUT_EMPRESA', 'SII_RUT_FIRMA', 'EMISOR_RAZON_SOCIAL', 'EMISOR_GIRO', 'EMISOR_DIRECCION', 'EMISOR_COMUNA'] as $clave) {
        if (empty($_ENV[$clave])) {
            fwrite(STDERR, "Falta {$clave} en el .env (ver .env.example).\n");
            exit(1);
        }
    }

    return [
        'rutEmpresa' => $_ENV['SII_RUT_EMPRESA'],
        'rutFirma' => $_ENV['SII_RUT_FIRMA'],
        'emisor' => [
            'rut' => $_ENV['SII_RUT_EMPRESA'],
            'razon_social' => $_ENV['EMISOR_RAZON_SOCIAL'],
            'giro' => $_ENV['EMISOR_GIRO'],
            'direccion' => $_ENV['EMISOR_DIRECCION'],
            'comuna' => $_ENV['EMISOR_COMUNA'],
        ],
    ];
}
