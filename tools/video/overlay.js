// Injecté dans chaque page de la vidéo : sous-titres arabes, cartes de titre, curseur visible.
(() => {
  if (window.__vid) return;
  window.__vid = true;
  const css = `
  #vid-cap{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:2147483646;max-width:86%;
    background:rgba(16,24,40,.92);color:#fff;font:600 24px/1.55 Cairo,'Segoe UI',Tahoma,sans-serif;direction:rtl;text-align:center;
    padding:12px 26px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.35);border-bottom:4px solid #E8571F;transition:opacity .35s;opacity:0;pointer-events:none}
  #vid-cap small{display:block;font-weight:500;font-size:18px;color:#cbd5e1}
  #vid-step{position:fixed;top:12px;left:12px;z-index:2147483646;background:#E8571F;color:#fff;font:700 16px Cairo,sans-serif;direction:rtl;
    padding:6px 14px;border-radius:999px;opacity:0;transition:opacity .3s;pointer-events:none}
  #vid-card{position:fixed;inset:0;z-index:2147483647;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;
    background:linear-gradient(135deg,#1E3A6E,#385B9A 55%,#E8571F);color:#fff;font-family:Cairo,'Segoe UI',sans-serif;direction:rtl;text-align:center;
    opacity:0;transition:opacity .5s;pointer-events:none;padding:40px}
  #vid-card h1{font-size:52px;margin:0;font-weight:800} #vid-card p{font-size:26px;margin:0;max-width:900px;line-height:1.6}
  #vid-cursor{position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;
    background:rgba(232,87,31,.35);border:2px solid #E8571F;pointer-events:none;transition:transform .12s;left:-50px;top:-50px}
  #vid-cursor.down{transform:scale(.6);background:rgba(232,87,31,.7)}
  .vid-ripple{position:fixed;z-index:2147483646;width:16px;height:16px;margin:-8px 0 0 -8px;border-radius:50%;border:3px solid #E8571F;pointer-events:none;animation:vidr .6s ease-out forwards}
  @keyframes vidr{to{transform:scale(4);opacity:0}}
  .vid-hl{outline:4px solid #E8571F !important;outline-offset:3px !important;border-radius:8px;transition:outline-color .3s}`;
  const add = () => {
    if (document.getElementById('vid-cap')) return;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    for (const id of ['vid-cap', 'vid-step', 'vid-card', 'vid-cursor']) { const d = document.createElement('div'); d.id = id; document.body.appendChild(d); }
    const c = document.getElementById('vid-cursor');
    document.addEventListener('mousemove', (e) => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; }, true);
    document.addEventListener('mousedown', (e) => { c.classList.add('down'); const r = document.createElement('div'); r.className = 'vid-ripple'; r.style.left = e.clientX + 'px'; r.style.top = e.clientY + 'px'; document.body.appendChild(r); setTimeout(() => r.remove(), 700); }, true);
    document.addEventListener('mouseup', () => c.classList.remove('down'), true);
  };
  if (document.body) add(); else document.addEventListener('DOMContentLoaded', add);
  window.__cap = (t, sub) => { add(); const el = document.getElementById('vid-cap'); if (!t) { el.style.opacity = 0; return; } el.innerHTML = ''; el.append(t); if (sub) { const s = document.createElement('small'); s.textContent = sub; el.append(s); } el.style.opacity = 1; };
  window.__step = (t) => { add(); const el = document.getElementById('vid-step'); el.textContent = t || ''; el.style.opacity = t ? 1 : 0; };
  window.__card = (h, p) => { add(); const el = document.getElementById('vid-card'); if (!h) { el.style.opacity = 0; return; } el.innerHTML = ''; const a = document.createElement('h1'); a.textContent = h; el.append(a); (p || []).forEach((x) => { const q = document.createElement('p'); q.textContent = x; el.append(q); }); el.style.opacity = 1; };
})();
