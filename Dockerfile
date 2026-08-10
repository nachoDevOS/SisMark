# =============================================================================
# SisMark — imagen de producción (Laravel 13 + PHP 8.3 sobre NGINX Unit)
#
# Todo en un solo Dockerfile: no hace falta docker compose. La imagen levanta
# la aplicación completa en un único proceso (`unitd`), que sirve los estáticos
# de `public/` y ejecuta PHP sin necesidad de nginx + php-fpm + supervisor.
#
# Incluye el driver de SQL Server (`pdo_sqlsrv` + msodbcsql18) para poder
# correr los comandos `sia:migrar-*` desde el propio contenedor, que es como se
# carga la base la primera vez. Fuera de esa carga el sistema no consulta al
# SIA: trabaja siempre contra MySQL.
#
# La base MySQL es un recurso aparte (en Coolify: «+ New» → «Database» → MySQL)
# y se apunta con las variables de entorno. Ver .env.docker.example.
#
# El microservicio de biométricos tiene su propio Dockerfile, en
# `device-service/`, y se despliega como un segundo recurso. Esta imagen
# igual lo trae adentro: con SISMARK_DEVICE_SERVICE=true el entrypoint lo
# arranca acá mismo, escuchando en 127.0.0.1, y se despliega todo como un solo
# recurso. Ver .env.docker.example, sección 3.
#
#   docker build -t sismark .
#   docker run -d --env-file .env.docker -p 8000:8000 sismark
# =============================================================================


# -----------------------------------------------------------------------------
# Etapa 1 — Assets de Vite (Tailwind 4)
#
# El layout del sistema trae su CSS embebido y no depende de Vite. Hoy ninguna
# vista usa @vite —la única que lo hacía era welcome.blade.php, que se eliminó
# por no tener ruta—, así que esta etapa compila un manifiesto que nadie lee.
# Se conserva para no romper el despliegue si mañana alguna vista lo usa; el día
# que se decida que no hace falta, se sacan esta etapa, su COPY de más abajo y
# las dependencias de npm.
#
# Si el servidor de construcción no tiene salida a internet, esta etapa falla
# al descargar las tipografías de bunny.net (plugin `fonts` de vite.config.js).
# En ese caso, comentar esta etapa y el COPY que la usa más abajo.
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


# -----------------------------------------------------------------------------
# Etapa 2 — Aplicación
# -----------------------------------------------------------------------------
FROM unit:1.34.2-php8.3

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
COPY --from=assets /build/public/build ./public/build

# `--optimize` sin `--classmap-authoritative`: blade-ui-kit/blade-heroicons
# resuelve componentes por nombre en tiempo de ejecución.
RUN composer dump-autoload --no-dev --optimize

# `public/hot` es del servidor de desarrollo de Vite: si viaja a la imagen,
# Laravel cree que hay un dev-server escuchando y pide los assets a un puerto
# que no existe. Se borra por si el .dockerignore no lo atrapó.
RUN rm -f public/hot public/fonts-manifest.dev.json

# --- Permisos ----------------------------------------------------------------
# Unit corre los procesos de PHP como el usuario `unit` (uid 999), así que es
# quien tiene que poder escribir en storage y en la caché de Blade.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && chown -R unit:unit storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Enlace de las fotos de perfil. Se crea acá y no en el arranque porque
# `public/` es de root y el proceso de PHP no puede escribir ahí.
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage

# --- Configuración de Unit ---------------------------------------------------
# El entrypoint de la imagen aplica los .json de este directorio en el primer
# arranque. Como Unit valida el esquema y rechaza cualquier clave que no
# conozca —incluidas las de comentario tipo "//"—, el archivo va sin
# anotaciones y lo que hace se explica acá:
#
#   listeners."*:8000".forwarded  El proxy de adelante (Traefik en Coolify)
#       termina el HTTPS. Sin esto Unit ve la petición como http, Laravel arma
#       las URLs en http y el navegador bloquea los AJAX de los listados por
#       contenido mixto. Se confía solo en rangos privados, nunca en internet.
#   routes.sismark  Lo que exista como archivo en public/ lo sirve Unit
#       directo; el resto cae en index.php. La excepción de /index.php impide
#       pedir el front controller como archivo estático.
#   applications.sismark.processes  Arranca con 2 procesos y crece hasta 16:
#       un reporte procesado o la lectura en vivo de un equipo pueden tardar, y
#       no conviene que una consulta lenta deje la aplicación sin procesos.
COPY docker/unit.json /docker-entrypoint.d/unit.json

# --- Arranque ----------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/sismark-entrypoint
# El repositorio se edita en Windows: con finales de línea CRLF el shell falla
# con "exec format error".
RUN sed -i 's/\r$//' /usr/local/bin/sismark-entrypoint \
    && chmod +x /usr/local/bin/sismark-entrypoint

EXPOSE 8000

# `/up` es la ruta de salud que registra bootstrap/app.php.
#
# El período de gracia es largo a propósito: el arranque corre las migraciones
# ANTES de levantar el servidor, y una migración pesada sobre la tabla de
# marcaciones —4,4 millones de filas— puede tardar minutos. Con los 40 s por
# defecto, el orquestador daba el despliegue por fallido y lo revertía en mitad
# de la migración (medido en Coolify: el índice de `asistencias` tardó 1 m 23 s).
#
# Para una migración que vaya a tardar más que esto, el camino es desplegar con
# SISMARK_SKIP_MIGRATIONS=true y correrla aparte.
HEALTHCHECK --interval=15s --timeout=5s --start-period=600s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

# El entrypoint propio prepara la base y las cachés, y después le cede el
# control al entrypoint de Unit, que es quien aplica /docker-entrypoint.d/.
ENTRYPOINT ["sismark-entrypoint"]
CMD ["unitd", "--no-daemon", "--control", "unix:/var/run/control.unit.sock"]
