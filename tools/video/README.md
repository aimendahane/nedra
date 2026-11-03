# Vidéo de formation « المسؤول عن المشروع » (1.12.0)

Enregistrée avec Playwright sur le site de démonstration (sous-titres arabes à l'écran, sans voix), puis assemblée avec ffmpeg.

```sh
cd /srv/wp && sudo -u www-data env PS_TEST=1 DEMO=1 php /home/user/nedra/protecta-suivi/tests/e2e-setup.php
NODE_PATH=/opt/node22/lib/node_modules node tools/video/record.js /tmp/psx/video
ffmpeg -i /tmp/psx/video/raw-desktop/*.webm -i /tmp/psx/video/raw-mobile/*.webm -filter_complex \
  "[0:v]fps=25,scale=1366:768,setsar=1[a];[1:v]fps=25,scale=-2:740,pad=1366:768:(ow-iw)/2:(oh-ih)/2:color=0x1E3A6E,setsar=1[b];[a][b]concat=n=2:v=1:a=0,format=yuv420p[v]" \
  -map "[v]" -c:v libx264 -crf 24 -movflags +faststart video.mp4
```
La vidéo (~6 min, 10 Mo) n'est pas versionnée ; elle est envoyée au client. À refaire après un changement d'interface important.

## Version 2 (03/11, demande du client : « avec la voix, et bien meilleure »)

- Voix arabe (homme, fusha) : moteur hors ligne sherpa-onnx (PyPI, 1.12.40) + voix libre Piper « ar_JO-kareem-medium »
  (version sherpa-onnx, release GitHub k2-fsa). Texte ENTIÈREMENT vocalisé à la main (`narration.py`), chaque phrase
  vérifiée par reconnaissance vocale (Whisper small, même moteur) : `voice.py` affiche le texte reconnu et un score.
  Rien de cela n'entre dans le thème (outils de fabrication de la vidéo seulement).
- Capture HD par CDP screencast (`record2.js`), une scène par phrase (la scène dure le temps de la voix) ; zoom suivi
  de l'élément expliqué et projecteur ; messages du site déplacés en haut pendant le tournage (pas sous les sous-titres).
- Montage `assemble.py` (Pillow + ffmpeg) : 30 i/s, caméra lissée, sous-titres en PNG (`captions.js`), voix placée au
  début de chaque scène, partie téléphone sur fond aux couleurs de la marque. ~6 min 50, 1920×1080, 39 Mo.
- Données réalistes : jeu de démonstration + `demo-realistic.php` (noms de projets plausibles, numéros d'offre, factures).

```sh
cd /srv/wp && sudo -u www-data env PS_TEST=1 DEMO=1 php …/tests/e2e-setup.php && sudo -u www-data env PS_TEST=1 php tools/video/demo-realistic.php
python voice.py                      # voix + contrôle (venv avec sherpa-onnx==1.12.40, soundfile, numpy, pillow)
node record2.js /tmp/v /tmp/psx/tts/scenes.json && node captions.js /tmp/v /tmp/psx/tts/scenes.json
python assemble.py /tmp/v /tmp/psx/tts/out /tmp/v/video.mp4
```
