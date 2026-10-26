"""Arma el MP4 del tutorial a 1920x1080 y 30 fps.

Lee lo que dejó grabar-*.cjs en SALIDA (cuadros a 2x con su hora y los eventos
de foco, cursor, clic y narración) y dibuja encima, cuadro por cuadro: la cámara
que se acerca al foco, el recuadro que oscurece el resto, el cursor, la onda del
clic y el subtítulo. Todo lo que se mueve va acá para que se mueva a 30 fps.
Uso: python3 docs/manual/componer.py SALIDA destino.mp4
"""

import bisect
import json
import os
import subprocess
import sys
from multiprocessing import Pool

from PIL import Image, ImageDraw

W, H, FPS = 1920, 1080, 30
ESCALA = 2           # los cuadros vienen con deviceScaleFactor 2
SS = 2               # sobremuestreo de los trazos (bordes suaves)
ZOOM_MAX = 1.9
T_CAMARA = 0.9
T_FOCO = 0.5
T_CURSOR = 0.7
T_ONDA = 0.55
FUNDIDO = 0.25
VERDE = (0, 166, 90)

salida, archivo_mp4 = sys.argv[1], sys.argv[2]
datos = json.load(open(os.path.join(salida, "eventos.json")))
frames = datos["frames"]
t0, lento = frames[0]["t"], datos.get("lento", 1)
tiempos = [(f["t"] - t0) / lento for f in frames]
eventos = sorted(datos["eventos"], key=lambda e: e["t"])
for e in eventos:
    e["t"] = (e["t"] - t0) / lento
# Con la pantalla quieta el screencast no manda cuadros: el video dura hasta el
# último evento del guion (el cierre), no hasta el último cuadro.
duracion = max(tiempos[-1], eventos[-1]["t"]) + 0.6
de = lambda tipo: [e for e in eventos if e["tipo"] == tipo]  # noqa: E731


def suave(p):
    p = min(max(p, 0.0), 1.0)
    return 4 * p ** 3 if p < 0.5 else 1 - (-2 * p + 2) ** 3 / 2


def mezcla(a, b, p):
    return tuple(x + (y - x) * p for x, y in zip(a, b))


def encuadre(r):
    """El recorte (x, y, ancho) en px CSS que deja la caja r centrada y grande."""
    if not r:
        return (0.0, 0.0, float(W))
    z = max(1.0, min(W / (r["width"] + 520), H / (r["height"] + 420), ZOOM_MAX))
    w, h = W / z, H / z
    cx, cy = r["x"] + r["width"] / 2, r["y"] + r["height"] / 2
    return (min(max(cx - w / 2, 0), W - w), min(max(cy - h / 2, 0), H - h), w)


def tramos(lista, valor, duracion_tramo, inicial):
    """Cada evento arranca una transición desde donde iba la anterior en ese instante."""
    salida_, actual = [], inicial
    for e in lista:
        if salida_ and not e.get("salto"):
            t_prev, d, h = salida_[-1]
            actual = mezcla(d, h, suave((e["t"] - t_prev) / duracion_tramo))
        destino = valor(e)
        salida_.append((e["t"], destino if e.get("salto") else actual, destino))
        actual = destino
    return salida_


def en(tr, t, duracion_tramo, inicial):
    i = bisect.bisect_right([x[0] for x in tr], t) - 1
    if i < 0:
        return inicial
    t_ini, d, h = tr[i]
    return mezcla(d, h, suave((t - t_ini) / duracion_tramo))


FOCOS = de("foco")
CAMARA = tramos(FOCOS, lambda e: encuadre(e["r"]), T_CAMARA, (0.0, 0.0, float(W)))
CURSOR = tramos(de("cursor"), lambda e: (e["x"], e["y"]), T_CURSOR, (W * 0.78, H * 0.7))
CLICS = de("clic")
T_FOCOS = [e["t"] for e in FOCOS]

# Subtítulos: cada uno hasta el siguiente. Va arriba si algún foco de su tramo,
# ya encuadrado, cae en el tercio de abajo.
narras = de("narra")
SUBTITULOS = []
for i, n in enumerate(narras):
    fin = narras[i + 1]["t"] if i + 1 < len(narras) else duracion
    if not n.get("png"):
        continue
    lado = "abajo"
    previo = [f for f in FOCOS if f["t"] < n["t"]][-1:]
    for f in previo + [f for f in FOCOS if n["t"] <= f["t"] < fin]:
        if f["r"]:
            x, y, w = encuadre(f["r"])
            if (f["r"]["y"] + f["r"]["height"] - y) * (W / w) > H - 270:
                lado = "arriba"
    SUBTITULOS.append((n["t"], fin, os.path.join(salida, f'{n["png"]}-{lado}.png')))

_cache = {}


def png(ruta):
    if ruta not in _cache:
        _cache[ruta] = Image.open(ruta).convert("RGBA")
    return _cache[ruta]


def foco_en(t):
    """(caja CSS, opacidad) del recuadro de foco en el instante t."""
    i = bisect.bisect_right(T_FOCOS, t) - 1
    if i < 0:
        return None, 0
    nuevo, viejo = FOCOS[i]["r"], FOCOS[i - 1]["r"] if i > 0 else None
    p = suave((t - FOCOS[i]["t"]) / T_FOCO)
    caja = lambda r: (r["x"], r["y"], r["width"], r["height"])  # noqa: E731
    if nuevo and viejo:
        return mezcla(caja(viejo), caja(nuevo), p), 1
    if nuevo:
        return caja(nuevo), p
    if viejo:
        return caja(viejo), 1 - p
    return None, 0


def cuadro(i):
    t = i / FPS
    x, y, w = en(CAMARA, t, T_CAMARA, (0.0, 0.0, float(W)))
    s = W / w  # px de salida por px CSS
    a_salida = lambda px, py: ((px - x) * s, (py - y) * s)  # noqa: E731

    fuente = frames[max(0, bisect.bisect_right(tiempos, t) - 1)]["archivo"]
    caja = tuple(round(v * ESCALA) for v in (x, y, x + w, y + w * H / W))
    img = Image.open(fuente).resize((W, H), Image.LANCZOS, box=caja).convert("RGBA")

    # Foco y onda, dibujados al doble y reducidos: bordes sin serrucho.
    caja_foco, a = foco_en(t)
    ondas = [c for c in CLICS if c["t"] <= t < c["t"] + T_ONDA]
    if a > 0.01 or ondas:
        capa = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
        d = ImageDraw.Draw(capa)
        if a > 0.01:
            fx, fy, fw, fh = caja_foco
            x1, y1 = a_salida(fx - 10, fy - 10)
            x2, y2 = a_salida(fx + fw + 10, fy + fh + 10)
            hueco = [v * SS for v in (x1, y1, x2, y2)]
            d.rectangle([0, 0, W * SS, H * SS], fill=(15, 23, 42, int(140 * a)))
            d.rounded_rectangle(hueco, radius=12 * s * SS, fill=(0, 0, 0, 0))
            g = 4 * s * SS
            d.rounded_rectangle([hueco[0] - g, hueco[1] - g, hueco[2] + g, hueco[3] + g],
                                radius=(12 * s + 4 * s) * SS, outline=VERDE + (int(255 * a),), width=round(g))
        for c in ondas:
            p = (t - c["t"]) / T_ONDA
            cx, cy = a_salida(c["x"], c["y"])
            r = 22 * (0.3 + 1.3 * p) * s
            d.ellipse([(cx - r) * SS, (cy - r) * SS, (cx + r) * SS, (cy + r) * SS],
                      outline=(0, 141, 76, int(255 * (1 - p))), width=round(3 * s * SS))
        img.alpha_composite(capa.resize((W, H), Image.BILINEAR))

    # Cursor: la punta de la flecha está en (12.67, 10.33) de su caja de 44 px CSS.
    cx, cy = a_salida(*en(CURSOR, t, T_CURSOR, (W * 0.78, H * 0.7)))
    lado = round(44 * s)
    flecha = png(os.path.join(salida, "cursor.png")).resize((lado, lado), Image.LANCZOS)
    img.alpha_composite(flecha, (round(cx - 12.67 * s), round(cy - 10.33 * s)))

    for ini, fin, ruta in SUBTITULOS:
        if ini <= t < fin:
            a = min(1.0, (t - ini) / FUNDIDO, (fin - t) / FUNDIDO)
            sub = png(ruta)
            if a < 1:
                sub = sub.copy()
                sub.putalpha(sub.getchannel("A").point(lambda v: int(v * a)))
            img.alpha_composite(sub)
    return img.convert("RGB").tobytes()


if __name__ == "__main__":
    total = int(duracion * FPS)
    ff = subprocess.Popen(
        ["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}",
         "-r", str(FPS), "-i", "-", "-c:v", "libx264", "-preset", "slow", "-crf", "16",
         "-pix_fmt", "yuv420p", "-movflags", "+faststart", archivo_mp4],
        stdin=subprocess.PIPE,
    )
    with Pool(os.cpu_count()) as pool:
        for n, bytes_ in enumerate(pool.imap(cuadro, range(total), chunksize=8)):
            ff.stdin.write(bytes_)
            if n % 300 == 0:
                print(f"{n}/{total}", flush=True)
    ff.stdin.close()
    ff.wait()
    print("listo", archivo_mp4)
