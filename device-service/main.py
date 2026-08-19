"""
Microservicio de dispositivos de SisMark.

Es la ÚNICA pieza del sistema que habla el protocolo ZKTeco con los equipos
biométricos (usando la librería pyzk sobre TCP 4370). La aplicación Laravel
nunca abre sockets a los equipos: le pide todo a este servicio por HTTP.

Seguridad: este servicio NUNCA debe exponerse a internet. Escucha solo en la red
interna y exige el encabezado 'X-Auth-Token' en cada petición (salvo /health).
El token se comparte con Laravel a través del .env de ambos lados.
"""

import os
import time
from datetime import date
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException, Query, Request, status
from fastapi.responses import JSONResponse
from zk import ZK
from zk.exception import ZKErrorConnection, ZKErrorResponse, ZKNetworkError


def cargar_env(ruta: Path) -> None:
    """Carga variables del archivo .env al entorno del proceso, sin pisar las
    que ya estén seteadas (por ejemplo, en un servicio de systemd).

    Evita depender de que quien arranca `uvicorn` haya exportado las
    variables a mano en la terminal antes: si se le olvida (o reinicia el
    proceso en una ventana nueva sin volver a cargarlas), el servicio queda
    sin token y rechaza todo. Sin dependencias nuevas: no hace falta
    python-dotenv para esto.
    """
    if not ruta.is_file():
        return

    for linea in ruta.read_text(encoding="utf-8").splitlines():
        linea = linea.strip()
        if not linea or linea.startswith("#") or "=" not in linea:
            continue
        clave, _, valor = linea.partition("=")
        clave = clave.strip()
        valor = valor.strip().strip('"').strip("'")
        os.environ.setdefault(clave, valor)


cargar_env(Path(__file__).resolve().parent / ".env")

app = FastAPI(
    title="SisMark device-service",
    description="Puente HTTP hacia los equipos biométricos ZKTeco.",
    version="1.0.0",
)


@app.exception_handler(Exception)
async def error_no_controlado(request: Request, exc: Exception) -> JSONResponse:
    """Red de seguridad: cualquier excepción que se escape de un endpoint
    vuelve como JSON con el motivo real, en vez de un 500 vacío. Sin esto,
    diagnosticar por qué un equipo puntual no conecta exige mirar la consola
    del proceso en vez del mensaje que ya llega a Laravel/al usuario.
    """
    return JSONResponse(
        status_code=500,
        content={"detail": f"{type(exc).__name__}: {exc}"},
    )


# Token compartido con Laravel. Se lee del entorno del proceso.
AUTH_TOKEN = os.environ.get("DEVICE_SERVICE_TOKEN", "")

# Segundos de espera al conectar con un equipo antes de darlo por caído.
CONNECT_TIMEOUT = int(os.environ.get("DEVICE_SERVICE_TIMEOUT", "5"))

# Segundos que se cachea en memoria el mapa de nombres de cada equipo.
# Leer los usuarios del reloj (get_users) es una lectura TCP completa; antes se
# hacía en CADA consulta de marcaciones solo para ponerle el nombre a cada una.
# Como los usuarios cambian poco, se cachea: las marcaciones se siguen leyendo
# 100% en vivo, pero se ahorra la segunda lectura del equipo en cada llamada.
# Poner 0 desactiva la caché (vuelve a leer usuarios siempre).
USERS_TTL = int(os.environ.get("DEVICE_SERVICE_USERS_TTL", "300"))

# Caché en proceso: "ip:port" -> (expira_en_monotonic, {user_id: nombre}).
_cache_nombres: dict[str, tuple[float, dict]] = {}


def mapa_nombres(conn, ip: str, port: int) -> dict:
    """Devuelve {user_id: nombre} del equipo, cacheado USERS_TTL segundos.

    Si hay entrada vigente en caché, no le pega al reloj; si no, lee los
    usuarios una vez y la guarda. Con USERS_TTL=0 nunca reutiliza.
    """
    clave = f"{ip}:{port}"
    ahora = time.monotonic()
    entrada = _cache_nombres.get(clave)
    if entrada and entrada[0] > ahora:
        return entrada[1]

    nombres = {u.user_id: u.name for u in conn.get_users()}
    _cache_nombres[clave] = (ahora + USERS_TTL, nombres)
    return nombres


def parse_fecha(valor: str | None) -> date | None:
    """Parsea 'YYYY-MM-DD' a date; None si viene vacío o mal formado."""
    if not valor:
        return None
    try:
        return date.fromisoformat(valor)
    except ValueError:
        return None


def verificar_token(x_auth_token: str = Header(default="")) -> None:
    """Valida el encabezado X-Auth-Token contra el token configurado.

    Corta la petición con 401 si el token falta o no coincide. Si el servicio
    arranca sin token configurado, rechaza todo por seguridad (fail-closed).
    """
    if not AUTH_TOKEN:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="El microservicio no tiene DEVICE_SERVICE_TOKEN configurado.",
        )
    if x_auth_token != AUTH_TOKEN:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token de autenticación inválido o ausente.",
        )


def conectar(ip: str, port: int, password: int, force_udp: bool = False) -> ZK:
    """Crea el objeto de conexión ZK hacia un equipo.

    No abre la conexión todavía; solo prepara los parámetros. La conexión real
    se abre con .connect() dentro de cada endpoint para poder cerrarla siempre.

    ommit_ping=True: algunos equipos (sobre todo los de reconocimiento facial,
    más nuevos que los relojes clásicos tipo iClock) tienen el ping ICMP
    deshabilitado aunque el puerto 4370 esté abierto y respondiendo. Sin esto,
    pyzk los da por caídos antes de intentar la conexión real.
    """
    return ZK(
        ip,
        port=port,
        timeout=CONNECT_TIMEOUT,
        password=password,
        force_udp=force_udp,
        ommit_ping=True,
    )


def conectar_con_reintento(ip: str, port: int, password: int) -> ZK:
    """Conecta por TCP y, si falla por un error de red/protocolo, reintenta
    una vez por UDP antes de rendirse.

    Algunos equipos ZKTeco más nuevos (terminales de reconocimiento facial,
    a diferencia de los relojes clásicos tipo iClock) solo hablan bien el
    protocolo ZK por UDP. Si el primer intento por TCP falla, vale la pena
    probar UDP antes de reportar el equipo como inalcanzable.
    """
    try:
        return conectar(ip, port, password, force_udp=False).connect()
    except (ZKErrorConnection, ZKErrorResponse, ZKNetworkError, OSError):
        return conectar(ip, port, password, force_udp=True).connect()


@app.get("/health")
def health() -> dict:
    """Chequeo de vida del microservicio. No requiere token."""
    return {"status": "ok", "service": "sismark-device-service"}


@app.get("/device/info", dependencies=[Depends(verificar_token)])
def device_info(
    ip: str = Query(..., description="IP del equipo en la LAN"),
    port: int = Query(4370, description="Puerto TCP ZKTeco"),
    password: int = Query(0, description="COMM key del equipo"),
) -> dict:
    """Conecta con un equipo y devuelve su información de identificación.

    Incluye una 'firma de algoritmo' (plataforma + firmware) que sirve para
    validar compatibilidad de huellas entre equipos: solo se transfieren huellas
    entre equipos con la misma firma.

    Ante cualquier fallo de conexión responde 503 con un mensaje claro, y siempre
    reactiva y cierra la conexión aunque haya error.
    """
    conn = None
    try:
        conn = conectar_con_reintento(ip, port, password)
        # Se deshabilita el equipo mientras se lee, para que nadie marque.
        conn.disable_device()

        plataforma = conn.get_platform()
        firmware = conn.get_firmware_version()

        return {
            "en_linea": True,
            "nombre": conn.get_device_name(),
            "serial": conn.get_serialnumber(),
            "plataforma": plataforma,
            "firmware": firmware,
            # Firma que identifica el formato de huella (BioBridge VX 9.0 / 10.0).
            "algoritmo": f"{plataforma} | {firmware}",
        }
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=f"No se pudo conectar con el equipo {ip}:{port}: {e}",
        )
    finally:
        # Cierre garantizado: reactivar el equipo y soltar el socket.
        if conn is not None:
            try:
                conn.enable_device()
                conn.disconnect()
            except Exception:
                pass


@app.get("/device/users", dependencies=[Depends(verificar_token)])
def device_users(
    ip: str = Query(..., description="IP del equipo en la LAN"),
    port: int = Query(4370, description="Puerto TCP ZKTeco"),
    password: int = Query(0, description="COMM key del equipo"),
) -> dict:
    """Devuelve la lista de usuarios registrados en el equipo.

    Mismo manejo de errores y cierre garantizado que /device/info.
    """
    conn = None
    try:
        conn = conectar_con_reintento(ip, port, password)
        conn.disable_device()

        gente = conn.get_users()

        # Refresca la caché de nombres de paso, así consultar usuarios sirve
        # también para invalidarla a mano si alguien cambió nombres en el reloj.
        _cache_nombres[f"{ip}:{port}"] = (
            time.monotonic() + USERS_TTL,
            {u.user_id: u.name for u in gente},
        )

        usuarios = [
            {
                "uid": u.uid,
                "user_id": u.user_id,
                "nombre": u.name,
                "privilegio": u.privilege,
            }
            for u in gente
        ]

        return {"en_linea": True, "total": len(usuarios), "usuarios": usuarios}
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=f"No se pudo leer los usuarios de {ip}:{port}: {e}",
        )
    finally:
        if conn is not None:
            try:
                conn.enable_device()
                conn.disconnect()
            except Exception:
                pass


@app.get("/device/attendance", dependencies=[Depends(verificar_token)])
def device_attendance(
    ip: str = Query(..., description="IP del equipo en la LAN"),
    port: int = Query(4370, description="Puerto TCP ZKTeco"),
    password: int = Query(0, description="COMM key del equipo"),
    desde: str | None = Query(None, description="Fecha mínima YYYY-MM-DD (inclusive)"),
    hasta: str | None = Query(None, description="Fecha máxima YYYY-MM-DD (inclusive)"),
) -> dict:
    """Devuelve las marcaciones (registros de asistencia) guardadas en el equipo.

    Cada marcación trae el usuario, la fecha/hora, el estado (entrada/salida según
    configuración del equipo) y el método de verificación (huella, tarjeta, clave).
    Se devuelven de la más reciente a la más antigua.

    El protocolo ZK no permite pedirle al reloj solo un rango: get_attendance()
    siempre vuelca todo el buffer. Pero si vienen `desde`/`hasta`, se filtra acá
    antes de responder, así Laravel recibe (y parsea) mucho menos en equipos con
    historial largo. Las marcaciones se leen en vivo en cada llamada.

    Devuelve tres cifras distintas, que es lo que le permite a SisMark saber si
    la transferencia se completó:

    - `en_equipo`: cuántas dice el propio reloj que tiene guardadas. Sale de su
      contador interno, no de contar lo que llegó.
    - `leidas`: cuántas se pudieron leer y parsear del buffer, antes de filtrar.
    - `total`: cuántas quedaron después de aplicar el rango pedido.

    `en_equipo` contra `leidas` es la prueba de integridad: si el reloj declara
    1500 y se leyeron 1499, la lectura se cortó por el medio y falta una
    marcación. Sin las dos cifras eso es invisible —se ven 1499 marcaciones y
    nada indica que debían ser 1500—. La comparación va contra `leidas` y no
    contra `total`, que con un rango chico es legítimamente menor.

    El equipo queda deshabilitado durante toda la lectura, así que nadie puede
    marcar en el medio y mover el contador: las dos cifras miden lo mismo.

    Mismo manejo de errores y cierre garantizado que el resto de endpoints.
    """
    conn = None
    try:
        conn = conectar_con_reintento(ip, port, password)
        conn.disable_device()

        # Mapa user_id -> nombre (cacheado; ver mapa_nombres).
        nombres = mapa_nombres(conn, ip, port)

        inicio = parse_fecha(desde)
        fin = parse_fecha(hasta)

        registros = conn.get_attendance()

        # get_attendance() ya llamó a read_sizes() internamente para saber cómo
        # partir el buffer, así que el contador del reloj está en memoria y
        # leerlo no cuesta otro viaje al equipo. getattr por si una versión de
        # pyzk no lo expone: sin el dato se informa None y SisMark omite la
        # comprobación, en vez de inventar un número.
        en_equipo = getattr(conn, "records", None)

        marcaciones = []
        for m in registros:
            dia = m.timestamp.date() if m.timestamp else None

            # Con rango pedido, se descartan las de fecha fuera del rango (y las
            # sin fecha, que no se pueden ubicar). Sin rango, entra todo.
            if inicio or fin:
                if dia is None or (inicio and dia < inicio) or (fin and dia > fin):
                    continue

            marcaciones.append(
                {
                    "uid": m.uid,
                    "user_id": m.user_id,
                    # Nombre del usuario si está registrado en el equipo.
                    "nombre": nombres.get(m.user_id) or "",
                    # Fecha/hora en formato ISO para que Laravel la parsee fácil.
                    "timestamp": m.timestamp.isoformat() if m.timestamp else None,
                    "estado": m.status,  # Código de estado (entrada/salida).
                    "verificacion": m.punch,  # Método: huella, tarjeta, clave, etc.
                }
            )

        # Más recientes primero.
        marcaciones.sort(key=lambda x: x["timestamp"] or "", reverse=True)

        return {
            "en_linea": True,
            "en_equipo": en_equipo,
            "leidas": len(registros),
            "total": len(marcaciones),
            "marcaciones": marcaciones,
        }
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=f"No se pudo leer las marcaciones de {ip}:{port}: {e}",
        )
    finally:
        if conn is not None:
            try:
                conn.enable_device()
                conn.disconnect()
            except Exception:
                pass


@app.post("/device/attendance/clear", dependencies=[Depends(verificar_token)])
def device_attendance_clear(
    ip: str = Query(..., description="IP del equipo en la LAN"),
    port: int = Query(4370, description="Puerto TCP ZKTeco"),
    password: int = Query(0, description="COMM key del equipo"),
) -> dict:
    """Borra del equipo TODAS las marcaciones guardadas en su buffer.

    OJO: el protocolo ZK solo ofrece clear_attendance(), que vacía el buffer
    entero. No existe borrado por rango de fechas: si se pide limpiar, se va
    todo el historial que el reloj tenga adentro. Es irreversible.

    Solo toca las marcaciones. Los usuarios, huellas, rostros y tarjetas
    registrados en el equipo quedan intactos.

    Mismo manejo de errores y cierre garantizado que el resto de endpoints.
    """
    conn = None
    try:
        conn = conectar_con_reintento(ip, port, password)
        # Se deshabilita el equipo mientras se borra, para que nadie marque
        # justo en el medio y su marcación se pierda sin haberse leído.
        conn.disable_device()

        conn.clear_attendance()

        return {"en_linea": True, "limpiado": True}
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=f"No se pudo limpiar las marcaciones de {ip}:{port}: {e}",
        )
    finally:
        if conn is not None:
            try:
                conn.enable_device()
                conn.disconnect()
            except Exception:
                pass
