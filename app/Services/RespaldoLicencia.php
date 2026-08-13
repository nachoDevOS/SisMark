<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda y sirve el respaldo que justifica una licencia: el certificado médico,
 * el memorándum o la nota.
 *
 * Los archivos van al disco `s3` (DigitalOcean Spaces) y no al almacenamiento
 * local: la aplicación corre en un contenedor sin volumen persistente para
 * esto, así que un archivo escrito en disco se pierde en el próximo despliegue.
 *
 * El disco tiene `throw => true`, de modo que un fallo de subida sale como
 * excepción en vez de devolver `false` en silencio: no puede quedar una
 * licencia diciendo que tiene respaldo si el archivo nunca llegó.
 */
class RespaldoLicencia
{
    /**
     * Disco donde viven los respaldos.
     */
    public const DISCO = 's3';

    /**
     * Cuánto vive el enlace de descarga que se le entrega al navegador.
     *
     * Los archivos son privados —un certificado médico no puede quedar
     * accesible con solo adivinar la URL—, así que se sirven con un enlace
     * firmado y de vida corta en lugar de hacerlos públicos.
     */
    private const MINUTOS_ENLACE = 5;

    /**
     * Sube el respaldo y devuelve la ruta guardada y el nombre original.
     *
     * La ruta se arma por año y cédula (`licencias/2026/7633685/…`) para que el
     * bucket siga siendo navegable con miles de archivos, y el nombre se
     * reemplaza por uno aleatorio: los archivos que sube la gente traen tildes,
     * espacios y a veces el nombre de otra persona. El original se conserva
     * aparte, que es lo que se le muestra a quien descarga.
     *
     * @return array{0: string, 1: string} ruta y nombre original
     */
    public function guardar(UploadedFile $archivo, string $ci): array
    {
        $carpeta = 'licencias/'.now()->format('Y').'/'.trim($ci);
        $nombre = Str::random(40).'.'.strtolower($archivo->getClientOriginalExtension());

        Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombre);

        return [
            $carpeta.'/'.$nombre,
            mb_substr($archivo->getClientOriginalName(), 0, 255),
        ];
    }

    /**
     * Enlace temporal para descargar un respaldo, o `null` si la licencia no
     * tiene o el archivo ya no está en el bucket.
     *
     * Algunos proveedores compatibles con S3 no firman URLs; si eso pasa, se
     * devuelve `null` y quien llama muestra el aviso, en vez de romper la
     * pantalla entera por un archivo.
     */
    public function enlace(?string $ruta): ?string
    {
        if (! filled($ruta)) {
            return null;
        }

        try {
            $disco = Storage::disk(self::DISCO);

            return $disco->exists($ruta)
                ? $disco->temporaryUrl($ruta, now()->addMinutes(self::MINUTOS_ENLACE))
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Borra un respaldo del bucket. No falla si ya no está.
     */
    public function borrar(?string $ruta): void
    {
        if (! filled($ruta)) {
            return;
        }

        try {
            Storage::disk(self::DISCO)->delete($ruta);
        } catch (\Throwable) {
            // Un respaldo que no se pudo borrar no puede frenar la baja de la
            // licencia: queda huérfano en el bucket y se limpia aparte.
        }
    }
}
