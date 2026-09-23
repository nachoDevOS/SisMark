#!/bin/sh
# =============================================================================
# Arranque del contenedor de SisMark.
#
# Deja la aplicación lista y después le cede el control al entrypoint de NGINX
# Unit, que es quien aplica la configuración de /docker-entrypoint.d/ y levanta
# `unitd`.
# =============================================================================
set -e

cd /var/www/html

# --- 0. ¿Arranque del servidor o comando suelto? -----------------------------
# Solo la secuencia completa cuando se levanta el servicio de verdad. Para
# cualquier otro comando (`php artisan …`, `sh`, `composer`) se ejecuta y
# listo: si no, `docker run --rm sismark php artisan key:generate --show`
# —el comando que genera la APP_KEY— moriría por no tener APP_KEY.
case "$1" in
    unitd|unitd-debug) ;;
    *)
        exec "$@"
        ;;
esac

echo "[sismark] Arrancando…"

# --- 1. Configuración mínima -------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "[sismark] ERROR: APP_KEY está vacía."
    echo "[sismark] Generar una con:  docker run --rm sismark php artisan key:generate --show"
    echo "[sismark] y ponerla en las variables de entorno del contenedor."
    exit 1
fi

if [ -z "${DB_HOST}" ] || [ -z "${DB_DATABASE}" ]; then
    echo "[sismark] ERROR: faltan DB_HOST o DB_DATABASE."
    exit 1
fi

# --- 2. Esperar a MySQL ------------------------------------------------------
# La base es un recurso aparte y puede tardar en aceptar conexiones. Sin esta
# espera, la primera migración muere y la aplicación queda contra una base que
# todavía no está.
#
# `127.0.0.1` y `localhost` son el error más común al desplegar: dentro del
# contenedor apuntan al contenedor mismo, no a la base. Se avisa de entrada en
# vez de dejar que el arranque se cuelgue dos minutos y el orquestador lo dé
# por muerto sin decir por qué.
case "${DB_HOST}" in
    127.0.0.1|localhost|::1)
        echo "[sismark] ERROR: DB_HOST=${DB_HOST} apunta al propio contenedor, no a la base."
        echo "[sismark] Usar el nombre del servicio o del recurso de la base"
        echo "[sismark] (p. ej. 'mysql'), o 'host.docker.internal' si la base"
        echo "[sismark] corre en la máquina que hospeda a Docker."
        exit 1
        ;;
esac

espera_maxima=90
echo "[sismark] Esperando a MySQL en ${DB_HOST}:${DB_PORT:-3306} (hasta ${espera_maxima}s)…"
intentos=0
until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT:-3306}" --silent 2>/dev/null; do
    intentos=$((intentos + 1))
    if [ "$((intentos * 3))" -ge "${espera_maxima}" ]; then
        echo "[sismark] ERROR: MySQL no respondió en ${espera_maxima}s."
        echo "[sismark] Revisar que DB_HOST sea alcanzable desde este contenedor,"
        echo "[sismark] que la base esté en la misma red, y que el usuario"
        echo "[sismark] '${DB_USERNAME:-(sin DB_USERNAME)}' pueda conectarse."
        # El motivo real del último intento, que hasta acá se descartaba.
        mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT:-3306}" 2>&1 | sed 's/^/[sismark]   /'
        exit 1
    fi
    # Una señal de vida cada 15 s: en un log de despliegue, el silencio no
    # distingue «todavía arrancando» de «colgado».
    if [ "$((intentos % 5))" -eq 0 ]; then
        echo "[sismark] …sigue sin responder ($((intentos * 3))s)"
    fi
    sleep 3
done
echo "[sismark] MySQL responde."

# --- 3. Permisos -------------------------------------------------------------
# `storage` suele venir de un volumen: el dueño lo pone Docker, no la imagen.
chown -R unit:unit storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# --- 4. Migraciones ----------------------------------------------------------
if [ "${SISMARK_SKIP_MIGRATIONS}" = "true" ]; then
    echo "[sismark] Migraciones salteadas (SISMARK_SKIP_MIGRATIONS=true)."
else
    echo "[sismark] Corriendo migraciones…"
    php artisan migrate --force
fi

# --- 5. Permisos y rol super_admin -------------------------------------------
# Corre en cada arranque: los permisos se declaran en RolePolicy::MODULOS, y un
# permiso nuevo que llega con el despliegue tiene que existir en la tabla
# `permissions` antes de marcarlo en /roles —si no, guardar el rol falla con
# PermissionDoesNotExist—. Es idempotente: crea lo que falta y no toca lo que
# ya existe, así que los permisos de cada rol se conservan.
#
# Se apaga con SISMARK_SEED=false.
if [ "${SISMARK_SEED:-true}" = "true" ]; then
    echo "[sismark] Sembrando roles y permisos…"
    php artisan db:seed --class=RolesAndPermissionsSeeder --force
fi

# --- 6. Cachés de producción -------------------------------------------------
# Primero se borra lo que pueda haber quedado del despliegue anterior:
#
#   view:clear              `storage` suele venir de un volumen, así que las
#       vistas compiladas de la versión vieja sobreviven al redeploy. Se borran
#       para que no quede ninguna —p. ej. de una vista que ya no existe—.
#   permission:cache-reset  La caché de roles y permisos vive en la tabla
#       `cache` de MySQL (CACHE_STORE=database), no en la imagen, así que
#       también sobrevive. Se resetea para que cada despliegue arranque
#       leyendo los permisos reales de la base.
#
# No se usa `optimize:clear` porque vacía la caché entera de la aplicación
# (padrón de Mamoré, catálogos) y el primer uso tras cada despliegue sería lento.
echo "[sismark] Limpiando vistas compiladas y caché de permisos…"
php artisan view:clear
php artisan permission:cache-reset

# Se regeneran en cada arranque: las variables de entorno pueden haber cambiado.
echo "[sismark] Cacheando configuración, rutas y vistas…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# --- 7. Microservicio de biométricos ----------------------------------------
# El microservicio Python corre DENTRO de este contenedor y se levanta acá. La
# imagen lo trae encendido (ENV SISMARK_DEVICE_SERVICE=true en el Dockerfile):
# no hay que configurar nada para que las acciones de equipos funcionen.
#
# Escucha solo en 127.0.0.1, así que el único que puede hablarle es el PHP de
# este mismo contenedor: el puerto no queda expuesto ni a internet ni a la red
# de Docker.
#
# Se apaga con SISMARK_DEVICE_SERVICE=false, que es lo que corresponde cuando el
# microservicio corre en OTRA máquina —la que sí tiene ruta hasta los relojes—
# con el contenedor de device-service/ y su DEVICE_SERVICE_URL apuntando ahí.
#
# Queda como proceso en segundo plano: al hacer `exec` más abajo, este shell es
# reemplazado y el proceso de Python pasa a colgar del PID 1. Si se cae, NO se
# reinicia solo —la sonda de salud del contenedor mira /up de Laravel, no al
# microservicio—; el reinicio es reiniciar el contenedor.
devices=${SISMARK_DEVICE_SERVICE:-true}

# Sin token el microservicio rechaza todo (fail-closed), así que arrancarlo no
# serviría de nada. Se avisa y se sigue: el resto del sistema —informes,
# marcaciones ya importadas, personal— funciona igual sin los equipos, y tumbar
# el arranque entero por esto dejaría la aplicación entera inaccesible.
if [ "${devices}" = "true" ] && [ -z "${DEVICE_SERVICE_TOKEN}" ]; then
    echo "[sismark] AVISO: DEVICE_SERVICE_TOKEN está vacío; no se arranca el microservicio."
    echo "[sismark] Las acciones de equipos van a fallar hasta que se configure."
    echo "[sismark] Generar un token con:  openssl rand -hex 32"
    echo "[sismark] y ponerlo en las variables de entorno del contenedor."
    devices=false
fi

if [ "${devices}" = "true" ]; then
    device_bind="${DEVICE_SERVICE_BIND:-127.0.0.1}"
    device_port="${DEVICE_SERVICE_PORT:-9001}"
    device_log=/var/www/html/storage/logs/device-service.log

    : > "${device_log}"
    chown unit:unit "${device_log}"

    echo "[sismark] Arrancando microservicio de biométricos en ${device_bind}:${device_port}…"

    # `su -p` conserva el entorno: el microservicio lee DEVICE_SERVICE_TOKEN y
    # sus timeouts de las variables del proceso.
    su -p -s /bin/sh unit -c \
        "exec /opt/device-venv/bin/python -m uvicorn main:app \
             --app-dir /srv/device-service \
             --host '${device_bind}' --port '${device_port}'" \
        >> "${device_log}" 2>&1 &

    # Esperar a que conteste /health (no exige token) antes de seguir: sin esto,
    # el primer clic en «Probar conexión» tras un despliegue puede caer en el
    # hueco entre que Unit acepta peticiones y uvicorn terminó de levantar, y el
    # usuario ve «no se pudo contactar al microservicio» sin que nada esté mal.
    intentos=0
    until curl -fsS "http://127.0.0.1:${device_port}/health" >/dev/null 2>&1; do
        intentos=$((intentos + 1))
        if [ "${intentos}" -ge 30 ]; then
            echo "[sismark] AVISO: el microservicio no respondió en 30s. Últimas líneas de su log:"
            tail -n 20 "${device_log}" 2>/dev/null | sed 's/^/[sismark]   /'
            echo "[sismark] La aplicación arranca igual; las acciones de equipos van a fallar."
            break
        fi
        sleep 1
    done
    if [ "${intentos}" -lt 30 ]; then
        echo "[sismark] Microservicio de biométricos responde."
    fi
fi

# --- 8. Planificador de tareas ----------------------------------------------
# `schedule:work` es el cron del sistema dentro del contenedor: despierta cada
# minuto y corre lo que declara routes/console.php. Hoy eso es
# `sismark:sincronizar-equipos`, que baja las marcaciones de cada reloj en los
# días y a las horas configurados en su ficha (Biométricos > editar equipo >
# «Sincronizar automáticamente en días y horas fijas»).
#
# Solo lee los relojes: las marcaciones se copian a `asistencias` y el equipo
# conserva su historial. Vaciarlo es otra acción, a mano y con otro permiso.
#
# Sin este proceso los horarios quedan guardados pero no ocurre nada: en Coolify
# no hay crontab del host que dispare `schedule:run`, así que el planificador
# tiene que vivir acá adentro.
#
# Corre como `unit` —el mismo usuario que el PHP de Unit— para que los logs y
# las cachés que escriba queden con el dueño correcto. Igual que el
# microservicio, queda en segundo plano colgando del PID 1 y no se reinicia
# solo: si se cae, se reinicia el contenedor.
#
# Se apaga con SISMARK_SCHEDULER=false. Corresponde cuando se levanta más de una
# réplica de la imagen: el planificador tiene que correr en una sola, o cada
# réplica sincronizaría los mismos relojes a la misma hora.
if [ "${SISMARK_SCHEDULER:-true}" = "true" ]; then
    scheduler_log=/var/www/html/storage/logs/scheduler.log

    : > "${scheduler_log}"
    chown unit:unit "${scheduler_log}"

    echo "[sismark] Arrancando el planificador de tareas (sincronización automática de equipos)…"

    su -p -s /bin/sh unit -c \
        "exec php /var/www/html/artisan schedule:work" \
        >> "${scheduler_log}" 2>&1 &
else
    echo "[sismark] Planificador apagado (SISMARK_SCHEDULER=false): la sincronización automática de equipos no va a correr."
fi

# Las cachés se escribieron como root: se devuelven al usuario de Unit.
chown -R unit:unit storage bootstrap/cache

echo "[sismark] Listo. Cediendo el control a NGINX Unit."

exec /usr/local/bin/docker-entrypoint.sh "$@"
