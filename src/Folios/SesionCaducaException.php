<?php

namespace Winex\Certificacion\Folios;

/**
 * La sesion AUT2000 que se paso a SolicitudFolios::solicitar() ya no sirve. El
 * llamador que la tenia guardada la descarta, vuelve a autenticar y reintenta.
 *
 * Se lanza antes de timbrar: reintentar no gasta folios.
 */
class SesionCaducaException extends SolicitudFoliosException {}
