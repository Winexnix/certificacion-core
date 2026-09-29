# winex/certificacion — Boleta Electrónica del SII de Chile

Librería PHP (8.3+) para certificar y emitir boletas electrónicas (tipo 39/41) ante el SII: firma electrónica, timbre (TED/CAF), solicitud de folios, postulación y declaración de cumplimiento, y envío por SOAP (certificación) y REST (producción).

## Licencia y apoyo

Código libre bajo [Apache-2.0](LICENSE): úsalo, modifícalo y véndelo, incluso en productos comerciales. Solo te pido que **conserves el aviso de copyright y el archivo [NOTICE](NOTICE)** con el crédito a su autor, Meniaz (William Menares).

Si te ahorró semanas de pelear con el SII, puedes invitarme un café (opcional): **[ko-fi.com/meniaz](https://ko-fi.com/meniaz)**. También está el botón **Sponsor** de este repositorio.

## Consultas y colaboración

Para cualquier consulta, duda o si quieres colaborar, escríbeme por Instagram: **[@mzfushi](https://instagram.com/mzfushi)**.

Este repositorio es de mantenimiento cerrado: cualquiera puede hacer un fork, abrir issues y proponer cambios con un pull request, pero solo el autor puede fusionar cambios en `main`.

> **Sin garantía.** Esto emite documentos tributarios reales. Pruébalo primero en certificación (`maullin`) y revisa cada resultado; la responsabilidad ante el SII es de quien lo use. No es un producto oficial del SII ni está afiliado a él.

## Puesta en marcha

📖 **Guía paso a paso (postular, set de pruebas, folios, emitir en producción): [docs/guia-de-uso.md](docs/guia-de-uso.md).**

```
composer install
cp .env.example .env    # completa certificado y datos del emisor
```

Nunca subas a git tu certificado (`.pfx`), tu clave, los CAF (traen la llave privada del folio) ni el `.env`; el `.gitignore` ya los excluye.

## Estructura

```
bin/                        Scripts ejecutables (puntos de entrada)
  bootstrap.php               Arranque comun: autoload, .env, timezone, cwd
  certificacion/
    enviar-set-boletas.php    Envia el Set de Pruebas a maullin.sii.cl (SOAP)
    enviar-consumo-folios.php  Envia el RCOF (consumo de folios) a maullin.sii.cl
  produccion/
    emitir-boleta.php         Emite una boleta real via API REST (api.sii.cl / rahue.sii.cl)
    consultar-envio.php       Consulta el estado de un envio por TrackID
  util/
    firmar-semilla.php        Firma una semilla suelta y devuelve el XML de getToken
    verificar-ted.php         Verifica el TED (timbre) de las boletas de un XML

src/
  Core/                     Compartido entre certificacion y produccion
    Certificate.php            Lee el .pfx y extrae llave privada / certificado
    FirmaElectronica.php       Firma XML (DTE, sobre, semilla->token)
    Timbre.php                 Genera el TED con el CAF
    GeneradorBoleta.php        Arma el XML <DTE> de una boleta
    EnvioBoleta.php            Empaqueta uno o mas DTE en un <EnvioBOLETA>
  Certificacion/            Solo flujo de certificacion
    SiiSoapClient.php          Canal SOAP DTEWS (semilla/token + upload DTE)
    SetPruebasParser.php       Lee el .txt del Set de Pruebas del SII
    GeneradorConsumoFolios.php Arma el XML <ConsumoFolios> (RCOF)
  Produccion/              Solo flujo de produccion
    SiiBoletaProduccion.php    Canal REST de Boleta Electronica
  Folios/                  Pedir CAF al SII (asistente cvc_cgi/dte), ambos ambientes
    SolicitudFolios.php        Login por certificado + los 5 pasos del asistente
    Ambiente.php               Certificacion (maullin, IDK 100) / Produccion (palena, IDK 300)
    SesionSii.php              Cookies AUT2000 (credencial: guardarla cifrada)
    TransporteCurl.php         HTTP real; TransporteFalso para tests sin red
  Postulacion/             Postular a boleta electronica y declarar cumplimiento
    PostulacionSii.php         Asistente pe_* de maullin (postulacion, se puede repetir)
    DeclaracionBoletas.php     Revision del set y declaracion de cumplimiento en www4 (GWT-RPC)
    DatosPostulacion.php       Datos validados con las reglas del formulario del SII
    GwtRpc.php / LectorGwt.php Lo minimo de GWT-RPC para el portal de boletas

data/
  certs/                   Certificado digital (.pfx)
  caf/                     Archivos CAF (folios autorizados) por ambiente
  schemas/                 XSD del SII
  set_pruebas/             Archivo .txt del Set de Pruebas
  output/                  XML generados y cache de tokens (regenerable)
```

Namespaces PSR-4: `Winex\Certificacion\{Core,Certificacion,Produccion,Folios,Postulacion}\` -> `src/{Core,Certificacion,Produccion,Folios,Postulacion}/`.

## Solicitud de folios

```php
use Winex\Certificacion\Folios\{Ambiente, SolicitudFolios};

$solicitud = new SolicitudFolios(Ambiente::Certificacion); // o Ambiente::Produccion
$sesion = $solicitud->autenticar($certificate);            // reusar: el SII frena el re-login
$cafXml = $solicitud->solicitar($sesion, '76543210-3', 39, 10);
```

El ambiente se fija en codigo, no por configuracion. Candados: toda URL y
redireccion del asistente tiene que ir al host del ambiente, y el CAF tiene que
traer su IDK, el RUT y el tipo pedidos. `solicitar()` lanza `SesionCaducaException`
(antes de timbrar: re-autenticar y reintentar) o `FoliosSinCafException` (la orden
de timbrar ya salio: NO reintentar, bajar el CAF desde "Folios ya autorizados").

## Postulacion y declaracion de cumplimiento (boletas)

```php
use Winex\Certificacion\Postulacion\{DatosDeclaracion, DatosPostulacion, DeclaracionBoletas, PostulacionSii};

$postulacion = new PostulacionSii;
$sesion = $postulacion->autenticar($certificate);   // la misma sesion sirve para las dos

// 1. Postular (maullin). El admin tiene que ser quien se autentica.
$resultado = $postulacion->postular($sesion, new DatosPostulacion(
    '76123456-0', '12345678-5',
    'admin@empresa.cl', 'contacto@empresa.cl', 'intercambio@empresa.cl',
    'Mi Software', boletaExenta: true,
));
$resultado->avisos; // mostrarlos: p. ej. "dejara de ser usuario del facturador gratuito del SII"

// 2. Enviado el set: revisar el envio y, si quedo limpio, pedir la revision del set.
$estado = $siiSoapClient->estadoEnvio($token, '76123456-0', $trackIdBoletas);   // QueryEstUp
if ($estado->limpio()) {                        // EPR y todos aceptados, sin rechazos ni reparos
    try {
        (new DeclaracionBoletas)->solicitarRevisionSet($sesion, '76123456-0', $trackIdBoletas);
    } catch (SetSinDescargarException) {        // el SII exige haber descargado el set (?SET=1)
        (new DeclaracionBoletas)->descargarSetPruebas($sesion, '76123456-0', 'proveedor@empresa.cl'); // nunca en P90
        (new DeclaracionBoletas)->solicitarRevisionSet($sesion, '76123456-0', $trackIdBoletas);
    }
}                                               // el resultado llega por correo; si aprueba -> P90

// 3. (set aprobado) Declarar cumplimiento: AUTORIZA EN PRODUCCION.
$declaracion = new DeclaracionBoletas;
if ($declaracion->estado($sesion, '76123456-0')->porDeclarar()) {   // P90
    $declaracion->declarar($sesion, new DatosDeclaracion(
        '76123456-0', 'www.sii.cl', '76123456-0', 'Mi Empresa SpA', 'proveedor@empresa.cl',
    ));
}
```

Candados: la postulacion solo va a maullin y revisa que la confirmacion del SII
repita los datos antes de grabar. La declaracion solo sale con estado P90; si la
orden salio y no hubo confirmacion lanza `DeclaracionInciertaException` (consultar
`estado()` antes de reintentar: una empresa ya autorizada responde sin codigo).
Si el SII publica otra version del portal GWT, las llamadas fallan con un mensaje
claro: hay que recapturar `POLICY`/`PERMUTACION` en `DeclaracionBoletas`. Para detectarlo antes de
usarlo: `DeclaracionBoletas::verificarPortal()` (sin login; lista de problemas, vacía = vigente).

Diagnostico (solo lectura): `php bin/certificacion/estado-declaracion.php <rut-empresa>`.

## Configuracion

`.env` en la raiz: copia `.env.example` y completalo (certificado, clave, ruta de openssl y datos del emisor).

## Uso

Los scripts se pueden invocar desde cualquier directorio; `bootstrap.php` fija el
cwd a la raiz del proyecto.

```
composer install
php bin/produccion/emitir-boleta.php            # prueba el token (no emite nada)
php bin/produccion/consultar-envio.php <TRACKID>
php bin/certificacion/enviar-set-boletas.php
php bin/certificacion/enviar-consumo-folios.php
```

---

## Versionado

El campo `version` de `composer.json` **tiene que coincidir con el tag de git**
(así lo leen también las instalaciones vía repositorio `path`, que no ven los tags).
Al publicar una versión:

```bash
# 1. subir version en composer.json  2. commit  3. tag
git tag -a v1.2.0 -m "..."
```

⚠️ `src/` NO se normaliza de finales de línea (`* -text` en `.gitattributes`):
los literales multilínea arman XML que después se firma.
