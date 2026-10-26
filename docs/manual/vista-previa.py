"""Arma la vista previa de un tutorial: 1,8 s de cada capítulo (menos la presentación), 640×360, sin sonido.

La tarjeta de la Ayuda la repite en bucle para que el video se vea «andando» sin bajar
el MP4 entero. Los capítulos los lee de app/Services/ManualUsuario.php.
Uso: python docs/manual/vista-previa.py cambiar-turno
"""

import os
import re
import subprocess
import sys

clave = sys.argv[1]
raiz = os.path.join(os.path.dirname(__file__), "..", "..")
fuente = open(os.path.join(raiz, "app", "Services", "ManualUsuario.php"), encoding="utf8").read()
bloque = fuente[fuente.index(f"'{clave}' =>"):]
bloque = bloque[: bloque.index("],\n        ],") if "],\n        ]," in bloque else None]
segundos = [int(s) for s in re.findall(r"'segundo' => (\d+)", bloque)]
# Sin la presentación: es el mismo Escritorio en todos y las tarjetas se verían iguales.
segundos = segundos[1:]

video = os.path.join(raiz, "resources", "manual", f"{clave}.mp4")
salida = os.path.join(raiz, "resources", "manual", f"{clave}.previa.mp4")
# 1,5 s después del comienzo del capítulo: el primer instante suele ser el corte de cámara.
tramos = [f"[0:v]trim=start={s + 1.5}:duration=1.8,setpts=PTS-STARTPTS[t{i}]" for i, s in enumerate(segundos)]
filtro = ";".join(tramos) + ";" + "".join(f"[t{i}]" for i in range(len(segundos))) \
    + f"concat=n={len(segundos)}:v=1:a=0,scale=640:-2,fps=24[v]"
subprocess.check_call(["ffmpeg", "-y", "-loglevel", "error", "-i", video, "-filter_complex", filtro, "-map", "[v]",
                       "-an", "-c:v", "libx264", "-preset", "slow", "-crf", "28", "-pix_fmt", "yuv420p",
                       "-movflags", "+faststart", salida])
print(salida, os.path.getsize(salida) // 1024, "KB", len(segundos), "tramos")
