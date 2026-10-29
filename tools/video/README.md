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
