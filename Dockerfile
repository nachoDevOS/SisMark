# =============================================================================
# SisMark — imagen de producción (Laravel 13 + PHP 8.3 sobre FrankenPHP)
#
# Todo en un solo Dockerfile: no hace falta docker compose. La imagen levanta
# la aplicación completa en un único proceso (`frankenphp`, que es Caddy con
# PHP adentro), que sirve los estáticos de `public/` y ejecuta PHP sin
# necesidad de nginx + php-fpm + supervisor.
#
# Hasta octubre de 2026 corría sobre NGINX Unit. Unit se archivó en octubre de
# 2025 y su imagen quedó congelada en PHP 8.3.26, sin los parches de seguridad
# posteriores (entre ellos los de 8.3.36: ejecución remota de código sin
# autenticación). FrankenPHP hace lo mismo con el mismo esquema de un proceso.
#
# Incluye el driver de SQL Server (`pdo_sqlsrv` + msodbcsql18) para poder
# correr los comandos `sia:migrar-*` desde el propio contenedor, que es como se
# carga la base la primera vez. Fuera de esa carga el sistema no consulta al
# SIA: trabaja siempre contra MySQL.
#
# La base MySQL es un recurso aparte (en Coolify: «+ New» → «Database» → MySQL)
# y se apunta con las variables de entorno. Ver README, «Variables de entorno».
#
# El microservicio de biométricos tiene su propio Dockerfile, en
# `device-service/`, y se despliega como un segundo recurso. Esta imagen
# igual lo trae adentro: con SISMARK_DEVICE_SERVICE=true el entrypoint lo
# arranca acá mismo, escuchando en 127.0.0.1, y se despliega todo como un solo
# recurso.
#
#   docker build -t sismark .
#   docker run -d --env-file .env.docker -p 8000:8000 sismark
# =============================================================================


# -----------------------------------------------------------------------------
# Etapa 1 — Aplicación
# -----------------------------------------------------------------------------
# Versión exacta: PHP 8.3.36 sobre Debian 12 (bookworm), el mismo Debian que
# usaba la imagen de Unit. Importa por el driver de SQL Server (msodbcsql18 sale
# del repositorio de Microsoft para Debian 12) y por el TLS antiguo del SIA.
# Para tomar un parche nuevo de PHP se cambia este tag y el mínimo de abajo.
FROM dunglas/frankenphp:1.13.2-php8.3.36-bookworm

# La construcción falla si el PHP de la imagen es anterior a 8.3.36: nunca se
# despliega, por error, un PHP con las fallas críticas que corrige esa versión.
RUN php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);' \
    || (echo "PHP $(php -r 'echo PHP_VERSION;') es anterior a 8.3.36" && exit 1)

# Hora de Bolivia: los relojes biométricos guardan en hora local y el
# procesador de asistencia compara marcas contra horarios sin zona horaria.
ENV TZ=America/La_Paz

# --- Librerías del sistema y extensiones de PHP ------------------------------
# gd    → simple-qrcode (los QR de los reportes) y phpspreadsheet
# zip   → phpspreadsheet (el .xlsx es un zip)
# intl  → fechas y números en español
# pcntl → señales, para los comandos largos de migración
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        unzip \
        git \
        gnupg2 \
        ca-certificates \
        default-mysql-client \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        unixodbc-dev \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        zip \
        gd \
        bcmath \
        exif \
        pcntl \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# --- Driver de SQL Server (conexión `sia`) ----------------------------------
# Necesario para correr los comandos `sia:migrar-*` desde el contenedor, que es
# como se carga la base la primera vez. Fuera de eso el sistema no consulta al
# SIA: si el driver falta, el escritorio simplemente muestra «Sin conexión».
RUN curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# --- Microservicio de biométricos --------------------------------------------
# El microservicio Python viene DENTRO de esta imagen y el entrypoint lo levanta
# solo, sin configuración: la imagen se despliega como un único recurso y las
# acciones de equipos funcionan de entrada.
#
# Escucha en 127.0.0.1:9001, que es exactamente el valor por defecto de
# `services.device_service.url`. Al vivir los dos procesos en el mismo
# contenedor, `127.0.0.1` es el destino correcto, y el puerto 9001 no queda
# accesible desde afuera —ni desde internet ni desde la red de Docker—, que es
# lo que exige `device-service/main.py`.
#
# `device-service/Dockerfile` sigue existiendo para quien necesite correrlo en
# OTRA máquina (ver el aviso de abajo). En ese caso se apaga el de acá con
# SISMARK_DEVICE_SERVICE=false y se apunta DEVICE_SERVICE_URL al de afuera.
#
# OJO: esto resuelve dónde vive el microservicio, no la ruta de red hasta los
# relojes. Sigue haciendo falta que ESTE contenedor alcance el puerto 4370 de
# cada equipo; si la aplicación corre en un VPS fuera de la red del organismo,
# no hay Dockerfile que lo arregle.
#
# Debian marca su Python como "externally managed" (PEP 668) y rechaza pip
# sobre el intérprete del sistema: por eso el venv.
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
    && rm -rf /var/lib/apt/lists/*

COPY device-service/requirements.txt /srv/device-service/requirements.txt
RUN python3 -m venv /opt/device-venv \
    && /opt/device-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/device-venv/bin/pip install --no-cache-dir \
        -r /srv/device-service/requirements.txt

COPY device-service/main.py /srv/device-service/main.py

# Encendido por defecto: desplegar la imagen tal cual tiene que alcanzar. El
# bind a loopback es a propósito y no conviene cambiarlo salvo que otro
# contenedor tenga que consultar a este microservicio.
ENV SISMARK_DEVICE_SERVICE=true \
    DEVICE_SERVICE_BIND=127.0.0.1 \
    DEVICE_SERVICE_PORT=9001

# --- Planificador de tareas ---------------------------------------------------
# El entrypoint levanta `php artisan schedule:work` dentro del contenedor: es lo
# que dispara cada minuto lo declarado en routes/console.php, hoy la
# sincronización automática de los relojes en los días y a las horas que tenga
# configurados cada equipo en su ficha.
#
# Las horas se comparan contra `now()`, o sea contra APP_TIMEZONE: con la zona
# mal puesta, «08:30» dispara a otra hora. Por eso el TZ del sistema y el
# default de config/app.php son los dos America/La_Paz.
#
# Va acá adentro y no como cron del host porque en Coolify no hay crontab: el
# recurso es la imagen. Sin esto, los horarios se guardan pero no corre nada.
#
# Apagarlo (SISMARK_SCHEDULER=false) solo si se levanta más de una réplica de
# esta imagen: el planificador tiene que correr en una sola.
ENV SISMARK_SCHEDULER=true


# --- TLS antiguo para el SQL Server 2008 R2 ---------------------------------
# Sin esto el handshake muere con «unsupported protocol» antes de llegar a
# pedir usuario y contraseña. Ver docker/openssl-legacy.cnf.
COPY docker/openssl-legacy.cnf /etc/ssl/openssl-legacy.cnf
ENV OPENSSL_CONF=/etc/ssl/openssl-legacy.cnf

# --- Ajustes de PHP ----------------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sismark.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# --- Dependencias de Composer ------------------------------------------------
# Primero solo los manifiestos: si no cambian, Docker reutiliza esta capa y no
# vuelve a bajar nada.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# --- Código de la aplicación -------------------------------------------------
COPY . .

# `--optimize` sin `--classmap-authoritative`: blade-ui-kit/blade-heroicons
# resuelve componentes por nombre en tiempo de ejecución.
RUN composer dump-autoload --no-dev --optimize

# Restos de un servidor de desarrollo de Vite en el árbol de trabajo. El
# sistema ya no usa Vite —el CSS va embebido en el layout—, pero si estos
# archivos viajaran a la imagen, Laravel creería que hay un dev-server
# escuchando y pediría los assets a un puerto que no existe. Se borran por si
# el .dockerignore no los atrapó.
RUN rm -f public/hot public/fonts-manifest.dev.json

# --- Permisos ----------------------------------------------------------------
# FrankenPHP corre como `www-data` (no como root: el entrypoint le baja los
# privilegios al final), así que es quien tiene que poder escribir en storage y
# en la caché de Blade, y en los directorios de datos de Caddy.
#
# Sin capacidades: el puerto 8000 no las necesita, y así arranca igual si el
# orquestador pone `no-new-privileges`.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs storage/app/public bootstrap/cache \
             /data/caddy /config/caddy \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy \
    && chmod -R 775 storage bootstrap/cache \
    && (setcap -r /usr/local/bin/frankenphp 2>/dev/null || true)

# Enlace de las fotos de perfil. Se crea acá y no en el arranque porque
# `public/` es de root y el proceso de PHP no puede escribir ahí.
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage

# --- Configuración del servidor ----------------------------------------------
# docker/Caddyfile: puerto 8000, estáticos de public/ y el resto a index.php,
# de 2 a 16 hilos de PHP y X-Forwarded-* solo desde rangos privados. Lo explica
# el propio archivo.
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# --- Arranque ----------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/sismark-entrypoint
# El repositorio se edita en Windows: con finales de línea CRLF el shell falla
# con "exec format error".
RUN sed -i 's/\r$//' /usr/local/bin/sismark-entrypoint \
    && chmod +x /usr/local/bin/sismark-entrypoint

EXPOSE 8000

# `/up` es la ruta de salud que registra bootstrap/app.php.
#
# El arranque NO corre migraciones (se aplican a mano, ver docker/entrypoint.sh).
# Antes de levantar el servidor espera a MySQL (hasta 90 s) y al microservicio
# (hasta 30 s) y arma las cachés: el período de gracia cubre ese peor caso.
HEALTHCHECK --interval=15s --timeout=5s --start-period=180s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

# El entrypoint propio prepara la base y las cachés, y después levanta
# FrankenPHP como `www-data`.
ENTRYPOINT ["sismark-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
