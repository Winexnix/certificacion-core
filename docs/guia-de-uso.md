# Guía de uso

Cómo pasar de cero a emitir boletas electrónicas (tipo 39) con esta librería. Sigue el orden real del proceso ante el SII: **certificación** (ambiente de pruebas `maullin`) y después **producción**.

> Esto emite documentos tributarios reales. Prueba siempre en certificación primero. Sin garantía; ver [LICENSE](../LICENSE).

## 0. Qué necesitas

- PHP 8.3+ con las extensiones `curl`, `dom`, `mbstring` y `openssl` (y `soap` solo para el canal de certificación).
- `openssl` en el PATH, o su ruta en el `.env`. Los `.pfx` que entrega el SII son antiguos y OpenSSL 3 no siempre los lee; la librería ya reintenta con el modo `-legacy`.
- Un **certificado digital** (`.pfx`) del representante o administrador de la empresa, y su clave.
- Una empresa con giro y clave tributaria en el SII.

## 1. Instalar y configurar

```bash
composer install
cp .env.example .env
```

Completa el `.env`:

| Variable | Qué es |
|---|---|
| `SII_CERT_PATH` | Ruta al `.pfx`, p. ej. `data/certs/mi-certificado.pfx` |
| `SII_CERT_PASS` | Clave del certificado |
| `SII_OPENSSL_PATH` | `openssl`, o la ruta completa al binario |
| `SII_RUT_EMPRESA` | RUT de la empresa emisora, con guion y DV |
| `SII_RUT_FIRMA` | RUT de quien firma (dueño del certificado) |
| `EMISOR_RAZON_SOCIAL`, `EMISOR_GIRO`, `EMISOR_DIRECCION`, `EMISOR_COMUNA` | Datos del emisor. **El giro no puede llevar abreviaciones** |

Deja el certificado en `data/certs/` y los CAF en `data/caf/`. Ambas carpetas están en el `.gitignore`: **nunca las subas a git** (el CAF trae la llave privada de tus folios).

Comprueba que el certificado abre y que el SII te da un token (solo lectura, no emite nada):

```bash
php bin/produccion/emitir-boleta.php
```

Debe terminar con `Autenticacion funciona`.

## 2. Usarlo como librería

Los scripts de `bin/` son ejemplos. En tu propio proyecto:

El paquete no está en Packagist; instálalo directo desde GitHub. En el `composer.json` de tu proyecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Winexnix/certificacion-core" }
    ],
    "require": { "winex/certificacion": "^1.9" }
}
```

```php
use Winex\Certificacion\Core\{Certificate, FirmaElectronica};

$cert = new Certificate('/ruta/certificado.pfx', 'clave', 'openssl');
// o desde bytes (por ejemplo, guardado cifrado en tu base de datos):
// $cert = Certificate::fromContents($bytesDelPfx, 'clave', 'openssl');

$firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());
```

## 3. Pedir folios (CAF)

El CAF es el archivo con los folios autorizados; sin él no se puede timbrar.

```php
use Winex\Certificacion\Folios\{Ambiente, SolicitudFolios};

$solicitud = new SolicitudFolios(Ambiente::Certificacion); // o Ambiente::Produccion
$sesion = $solicitud->autenticar($cert);                   // reutiliza la sesión: el SII frena los re-login
$cafXml = $solicitud->solicitar($sesion, '76123456-0', 39, 10); // RUT, tipo de documento, cantidad
file_put_contents('data/caf/Boleta/caf-39.xml', $cafXml);
```

Si lanza `FoliosSinCafException`, el SII ya timbró los folios pero no se pudo bajar el archivo: **no reintentes**, descárgalo desde "Folios ya autorizados" en el portal. Detalle en [folios.md](folios.md).

## 4. Certificación: postular y pasar el set de pruebas

Este es el trámite obligatorio antes de emitir en producción.

**4.1 Postular** en `maullin` (ver el código completo en el [README](../README.md#postulacion-y-declaracion-de-cumplimiento-boletas)):

```php
use Winex\Certificacion\Postulacion\{DatosPostulacion, PostulacionSii};

$postulacion = new PostulacionSii;
$sesion = $postulacion->autenticar($cert);
$postulacion->postular($sesion, new DatosPostulacion(
    '76123456-0', '12345678-5',
    'admin@miempresa.cl', 'contacto@miempresa.cl', 'intercambio@miempresa.cl',
    'Mi Software', boletaExenta: true,
));
```

Anota la **fecha en que postulas**: es la `FchResol` que se usa en certificación. El `NroResol` de certificación es `0`.

**4.2 Bajar el set de pruebas.** El SII te entrega un `.txt` con los casos. Guárdalo como `data/set_pruebas/Set Prueba BE.txt` (el que viene en el repo es un ejemplo).

**4.3 Enviar el set.** Edita en `bin/certificacion/enviar-set-boletas.php` la ruta del CAF (`$rutasCaf`), los folios (`$folioQueue`, uno por caso) y la fecha de resolución (`EnvioBoleta`), y ejecuta:

```bash
php bin/certificacion/enviar-set-boletas.php
```

Imprime los casos que leyó para que verifiques que coinciden con tu set, y termina con un `TrackID`.

**4.4 Enviar el RCOF** (consumo de folios). Debe usar **la misma fecha, folios y montos** que el set, o el SII lo rechaza. Ajusta `$folioInicial`, `$folioFinal`, `$montosBrutosPorCaso` y la fecha/número de resolución en `bin/certificacion/enviar-consumo-folios.php`:

```bash
php bin/certificacion/enviar-consumo-folios.php
```

**4.5 Revisar el estado del envío:**

```php
use Winex\Certificacion\Certificacion\SiiSoapClient;

$soap = new SiiSoapClient($firmador);
$token = $soap->getToken($cert->getPrivateKey(), $cert->getPublicKey());
$estado = $soap->estadoEnvio($token, '76123456-0', $trackId);
echo $estado->resumen();
if ($estado->limpio()) { /* aceptado, sin rechazos ni reparos */ }
```

**4.6 Pedir la revisión del set y declarar cumplimiento.** Con el envío limpio, `DeclaracionBoletas::solicitarRevisionSet()` pide la revisión; el resultado llega por correo. Con el set aprobado, `DeclaracionBoletas::declarar()` **autoriza la emisión en producción**. Ambos pasos están en el README y en [postulacion.md](postulacion.md).

Para ver en qué punto está tu empresa (solo lectura):

```bash
php bin/certificacion/estado-declaracion.php 76123456-0
```

## 5. Producción: emitir una boleta

Ya autorizado, pide folios de **producción** (`Ambiente::Produccion`) y emite. Esto arma la boleta, la timbra con el CAF, la firma y la sube:

```php
use Winex\Certificacion\Core\{EnvioBoleta, GeneradorBoleta, Timbre};
use Winex\Certificacion\Produccion\SiiBoletaProduccion;

$emisor = [
    'rut' => '76123456-0', 'razon_social' => 'MI EMPRESA SPA',
    'giro' => 'ACTIVIDADES DE CONSULTORIA DE INFORMATICA',
    'direccion' => 'CALLE 123', 'comuna' => 'SANTIAGO',
];
$receptor = ['rut' => '66666666-6', 'razon_social' => 'Cliente General'];
$detalle  = [['nombre' => 'Producto', 'cantidad' => 1, 'precio' => 1000]]; // precio con IVA incluido
$folio    = 1;                                                              // uno de tu CAF

// 1. XML de la boleta
$xml = (new GeneradorBoleta)->generarBoleta($emisor, $receptor, (string) $folio, $detalle, '39', null);

// 2. Timbre (TED) con el CAF
$dom = new DOMDocument; $dom->loadXML($xml);
$ted = (new Timbre('data/caf/Boleta/caf-39.xml'))->generarTed([
    'rut_emisor' => $emisor['rut'], 'tipo_dte' => '39', 'folio' => (string) $folio,
    'fecha' => date('Y-m-d'), 'rut_receptor' => $receptor['rut'],
    'razon_social_receptor' => $receptor['razon_social'],
    'monto_total' => $dom->getElementsByTagName('MntTotal')->item(0)->nodeValue,
    'nombre_item_1' => $dom->getElementsByTagName('NmbItem')->item(0)->nodeValue,
]);
$xml = str_replace('</Documento>', $ted . "\n<TmstFirma>" . date('Y-m-d\TH:i:s') . "</TmstFirma>\n</Documento>", $xml);

// 3. Firma y sobre. Resolución de producción: N° 80, del 2014-08-22
$dteFirmado = $firmador->firmarDte($xml, "#F{$folio}T39");
$envio = (new EnvioBoleta($firmador, $emisor['rut'], '12345678-5', '80', '2014-08-22'))->empaquetar($dteFirmado);
file_put_contents('data/output/boleta.xml', mb_convert_encoding($envio, 'ISO-8859-1', 'UTF-8'));

// 4. Envío
$sii = new SiiBoletaProduccion($firmador, true);   // true = producción
$token = $sii->getToken();
$res = $sii->enviarBoleta($token, $emisor['rut'], '12345678-5', 'data/output/boleta.xml');
echo "TrackID: {$res['trackid']}\n";
```

Consultar el resultado del envío por su `TrackID`:

```bash
php bin/produccion/consultar-envio.php <TRACKID>
```

Si generas el XML en memoria (por ejemplo desde una API web), usa `enviarBoletaXml()` en lugar de escribir el archivo tú mismo.

### Reglas de la boleta que conviene saber

- En boleta tipo 39 el **precio del detalle va con IVA incluido**; la librería calcula y desglosa `MntNeto` e `IVA` en los totales.
- Un ítem exento se marca con `'exento' => true` en el detalle.
- El receptor anónimo es `66666666-6`.
- El XML se firma y se envía en **ISO-8859-1**; por eso el `mb_convert_encoding` antes de escribir.
- Cada folio se usa **una sola vez**. Guarda qué folio usaste con qué boleta antes de enviar.
- Cada día debes informar el **RCOF** con los folios utilizados.

## 6. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| `ESTADO 11 - Certificate no existe` al pedir token | La firma de la semilla debe llevar el transform *enveloped-signature*; usa `firmarSemilla()` de esta librería. |
| No abre el `.pfx` | OpenSSL 3 y certificados antiguos: apunta `SII_OPENSSL_PATH` a un `openssl` que soporte `-legacy`. |
| `SesionCaducaException` | La sesión del portal venció antes de timbrar: autentica de nuevo y reintenta. |
| `FoliosSinCafException` | El SII timbró pero no bajó el CAF: no reintentes, descárgalo del portal. |
| El SII rechaza el set | Fecha o folios del RCOF distintos a los del set, o giro con abreviaciones. |
| `DeclaracionInciertaException` | La orden salió y no hubo confirmación: consulta `estado()` antes de reintentar. |
| Fallan las llamadas de postulación/declaración | El SII cambió su portal GWT; revisa con `DeclaracionBoletas::verificarPortal()`. |

## 7. Más detalle

La referencia clase por clase está en el [índice de la documentación](README.md): [core](core.md), [certificación](certificacion.md), [producción](produccion.md), [folios](folios.md), [postulación](postulacion.md) y [scripts](scripts-bin.md).

¿Dudas o quieres colaborar? Instagram [@mzfushi](https://instagram.com/mzfushi).
