# Montage de la vidéo v2 : images de la capture (horodatées) → 30 i/s, zoom fluide, sous-titres, voix arabe, MP4.
# Usage : python assemble.py <dossier de la capture> <dossier des voix (id.wav)> <sortie.mp4>
import json, sys, subprocess, math, wave
import numpy as np
from PIL import Image

SRC, VOICES, OUTFILE = sys.argv[1], sys.argv[2], sys.argv[3]
FPS, W, H = 30, 1920, 1080
log = json.load(open(f'{SRC}/log.json'))

# --- segments : pc, mob, end (temps absolus) → temps de sortie consécutifs -----------------
seg_t = {s['seg']: s['t'] for s in log['segments']}
order = [('pc', seg_t['pc'], seg_t['pc-end']), ('mob', seg_t['mob'], seg_t['mob-end']), ('end', seg_t['end'], seg_t['end-end'])]
offset, timeline = 0.0, []
for name, a, b in order:
    timeline.append((name, a, b, offset))
    offset += b - a
TOTAL = offset
TOTAL_PC = seg_t['pc-end'] - seg_t['pc']

def to_out(t):
    for name, a, b, off in timeline:
        if a - 0.01 <= t <= b + 0.01:
            return off + (t - a)
    return None

frames = {}
for f in log['frames']:
    frames.setdefault(f['seg'], []).append((f['t'], f['file']))
for k in frames:
    frames[k].sort()

# --- zoom ------------------------------------------------------------------------------
def target_rect(z):
    if z.get('scale', 1) <= 1 or 'x' not in z:
        return (0.0, 0.0, float(W), float(H))
    s = min(z['scale'], 0.9 * W / max(z['w'], 1), 0.9 * H / max(z['h'], 1))
    s = max(1.0, s)
    w, h = W / s, H / s
    cx, cy = z['x'] + z['w'] / 2, z['y'] + z['h'] / 2
    x = min(max(cx - w / 2, 0), W - w)
    y = min(max(cy - h / 2, 0), H - h)
    return (x, y, w, h)

zooms = sorted(log['zooms'], key=lambda z: z['t'])
TR = 0.7  # durée d'une transition de zoom (s)
def ease(u):
    u = min(max(u, 0), 1)
    return u * u * (3 - 2 * u)
# Zoom « suivi » : la cible est la dernière position relevée ; la caméra la rejoint en douceur (lissage exponentiel),
# avec une transition plus franche quand on entre / sort du zoom.
def build_camera():
    cam, t, i, state = [], 0.0, 0, (0.0, 0.0, float(W), float(H))
    target = state
    for k in range(int(TOTAL_PC * FPS) + 2):
        t = seg_t['pc'] + k / FPS
        while i < len(zooms) and zooms[i]['t'] <= t:
            target = target_rect(zooms[i]); i += 1
        a = 1 - math.exp(-(1 / FPS) / 0.22)  # ~0,22 s de constante de temps
        state = tuple(s + (g - s) * a for s, g in zip(state, target))
        cam.append(state)
    return cam
# --- sous-titres -------------------------------------------------------------------------
caps = {}
scenes = []
for s in log['scenes']:
    o = to_out(s['t'])
    if o is None:
        continue
    scenes.append((o, o + s['dur'] + 0.45, s['id']))
    caps[s['id']] = Image.open(f'{SRC}/caps/{s["id"]}.png').convert('RGBA')

# --- fond du segment téléphone ---------------------------------------------------------------
bg = Image.new('RGB', (W, H))
grad = np.zeros((H, W, 3), dtype=np.uint8)
for i, (a, b) in enumerate(zip((0x1E, 0x3A, 0x6E), (0x38, 0x5B, 0x9A))):
    grad[:, :, i] = np.linspace(a, b, W, dtype=np.float32)[None, :].astype(np.uint8)
bg = Image.fromarray(grad)

# --- audio -----------------------------------------------------------------------------------
SR = 22050
audio = np.zeros(int((TOTAL + 2) * SR), dtype=np.float32)
for o, end, sid in scenes:
    with wave.open(f'{VOICES}/{sid}.wav') as w:
        sr = w.getframerate()
        x = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768
    if sr != SR:
        x = np.interp(np.linspace(0, len(x) - 1, int(len(x) * SR / sr)), np.arange(len(x)), x)
    i = int((o + 0.15) * SR)
    audio[i:i + len(x)] += x[: max(0, len(audio) - i)]
peak = np.max(np.abs(audio)) or 1
audio = (audio / peak * 0.89 * 32767).astype(np.int16)
with wave.open(f'{SRC}/voice.wav', 'wb') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR); w.writeframes(audio.tobytes())

# --- vidéo -----------------------------------------------------------------------------------
ff = subprocess.Popen(['ffmpeg', '-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', f'{W}x{H}', '-r', str(FPS), '-i', '-',
                       '-i', f'{SRC}/voice.wav', '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-pix_fmt', 'yuv420p',
                       '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-shortest', OUTFILE], stdin=subprocess.PIPE)
cache = {}
def load(seg, file):
    key = (seg, file)
    if key not in cache:
        cache.clear()
        im = Image.open(f'{SRC}/frames/{file}').convert('RGB')
        if seg == 'mob':
            im = im.resize((round(im.width * H / im.height), H), Image.LANCZOS)
            canvas = bg.copy(); canvas.paste(im, ((W - im.width) // 2, 0)); im = canvas
        elif im.size != (W, H):
            im = im.resize((W, H), Image.LANCZOS)
        cache[key] = im
    return cache[key]

CAM = build_camera()
n_frames = int(TOTAL * FPS)
for k in range(n_frames):
    t_out = k / FPS
    name, a, b, off = next(x for x in timeline if x[3] <= t_out < x[3] + (x[2] - x[1]) + 1e-9) if t_out < TOTAL else timeline[-1]
    t_abs = a + (t_out - off)
    lst = frames.get(name, [])
    j = max(0, np.searchsorted([f[0] for f in lst], t_abs, side='right') - 1) if lst else 0
    im = load(name, lst[j][1])
    if name == 'pc':
        x, y, w, h = CAM[min(len(CAM) - 1, max(0, int(round((t_abs - seg_t['pc']) * FPS))))]
        if w < W - 0.5:
            im = im.crop((round(x), round(y), round(x + w), round(y + h))).resize((W, H), Image.BICUBIC)
    for o, end, sid in scenes:
        if o <= t_out <= end:
            alpha = min(1, (t_out - o) / 0.25, (end - t_out) / 0.25)
            c = caps[sid]
            if alpha < 1:
                c = c.copy(); c.putalpha(c.getchannel('A').point(lambda v: int(v * alpha)))
            im = im.convert('RGBA'); im.alpha_composite(c); im = im.convert('RGB')
            break
    ff.stdin.write(im.tobytes())
    if k % 900 == 0:
        print(f'{k}/{n_frames}', flush=True)
ff.stdin.close()
ff.wait()
print('vidéo :', OUTFILE, round(TOTAL / 60, 1), 'min')
