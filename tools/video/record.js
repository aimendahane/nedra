// Vidéo de formation « المسؤول عن المشروع » : enregistrement Playwright + sous-titres arabes (sans voix).
// Usage : node record.js <dossier de sortie>   (site de démo : http://localhost:8081, compte masoul)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const OUT = process.argv[2] || '/tmp/psx/video';
const BASE = 'http://localhost:8081';
const PASS = 'Test-pass-2026';
const OVERLAY = fs.readFileSync(path.join(__dirname, 'overlay.js'), 'utf8');
const W = 1366, H = 768;

const wait = (p, ms) => p.waitForTimeout(ms);
async function cap(p, text, sub, ms) { await p.evaluate(([t, s]) => window.__cap(t, s), [text, sub || '']); await wait(p, ms || Math.max(3500, 60 * (text.length + (sub || '').length))); }
async function capOff(p) { await p.evaluate(() => window.__cap('')); }
async function step(p, t) { await p.evaluate((x) => window.__step(x), t); }
async function card(p, h, lines, ms) { await p.evaluate(([a, b]) => window.__card(a, b), [h, lines]); await wait(p, ms || 4500); await p.evaluate(() => window.__card('')); await wait(p, 600); }
let mouse = { x: W / 2, y: H / 2 };
async function moveTo(p, sel, opts) {
  const el = typeof sel === 'string' ? await p.waitForSelector(sel, { state: 'visible', timeout: 15000 }) : sel;
  await el.scrollIntoViewIfNeeded();
  await wait(p, 250);
  const b = await el.boundingBox();
  const x = b.x + b.width * ((opts && opts.fx) || 0.5), y = b.y + b.height * ((opts && opts.fy) || 0.5);
  await p.mouse.move(x, y, { steps: 18 });
  mouse = { x, y };
  await wait(p, 250);
  return el;
}
async function click(p, sel, opts) { const el = await moveTo(p, sel, opts); await wait(p, 200); await p.mouse.down(); await wait(p, 90); await p.mouse.up(); await wait(p, (opts && opts.after) || 900); return el; }
async function type(p, sel, text, delay) { await click(p, sel, { after: 200 }); await p.keyboard.press('Control+A'); await p.keyboard.press('Delete'); await p.keyboard.type(text, { delay: delay || 70 }); await wait(p, 400); }
async function hl(p, sel, on) { await p.evaluate(([s, o]) => document.querySelectorAll(s).forEach((e) => e.classList.toggle('vid-hl', o)), [sel, on !== false]); }
async function go(p, hash) { await p.evaluate((h) => { location.hash = h; }, hash); await p.waitForLoadState('networkidle'); await wait(p, 900); }

async function desktop(browser) {
  const ctx = await browser.newContext({ viewport: { width: W, height: H }, recordVideo: { dir: OUT + '/raw-desktop', size: { width: W, height: H } } });
  await ctx.addInitScript(OVERLAY);
  const p = await ctx.newPage();
  await p.goto(BASE + '/');
  await wait(p, 800);
  await card(p, 'شرح الموقع للمسؤول عن المشروع', ['متابعة المشاريع والعروض — Protecta Group', 'من إدخال الطلب إلى العرض والفاتورة والتسليم'], 5500);

  // 1. الدخول
  await step(p, '1 · الدخول');
  await cap(p, 'افتح الموقع واكتب اسم المستخدم وكلمة المرور', 'بعد ساعتين بدون استعمال يُغلق الحساب تلقائيًا للحماية', 4500);
  await type(p, 'input[name=log]', 'masoul');
  await type(p, 'input[name=pwd]', PASS, 40);
  await click(p, 'button[type=submit]', { after: 300 });
  await p.waitForSelector('.ps-nav__link');
  await wait(p, 1200);

  // 2. مهامي
  await step(p, '2 · مهامي');
  await cap(p, 'أول صفحة هي «مهامي»: كل ما عليك فعله اليوم', 'العروض المتأخرة، المتابعات المبرمجة، التسليمات، والطلبات التي تنتظر قرارك');
  await hl(p, '[data-today]');
  await cap(p, 'سطر «اليوم» يلخّص كل شيء — اضغط على أي رقم للذهاب إلى قسمه', '', 4500);
  await hl(p, '[data-today]', false);
  const chip = await p.$('[data-jump-block]');
  if (chip) { await click(p, chip, { after: 1500 }); }
  await cap(p, 'اضغط على اسم أي مشروع لفتح بطاقته', 'الأقسام الفارغة تُجمع في سطر صغير «✔ لا شيء في: …»', 4500);
  await p.mouse.wheel(0, -2000); await wait(p, 600);
  await hl(p, '.ps-nav');
  await cap(p, 'القائمة الجانبية: الصفحات التي تستعملها كل يوم', 'الرقم البرتقالي = عدد ما ينتظرك في الصفحة', 4500);
  await hl(p, '.ps-nav', false);
  await click(p, '#ps-usermenu', { after: 800 });
  await cap(p, 'اضغط على اسمك في الأعلى: اللغة، الوضع الليلي، وتسجيل الخروج', '', 4500);
  await p.keyboard.press('Escape'); await wait(p, 500);
  await click(p, '#ps-bell', { after: 900 });
  await cap(p, '🔔 الإشعارات: كل تغيير في مشاريعك يصلك هنا', 'ومن هنا تربط حسابك بـ Telegram لتصلك الإشعارات على الهاتف', 5500);
  await p.keyboard.press('Escape'); await p.mouse.click(700, 400); await wait(p, 500);

  // 3. إدخال سريع
  await step(p, '3 · طلب جديد (إدخال سريع)');
  await go(p, '#/projets');
  await cap(p, 'وصلك طلب جديد؟ سجّله فورًا من «المشاريع» ← «⚡ إدخال سريع»', '', 4000);
  await click(p, '[data-quick-add]', { after: 1200 });
  await cap(p, '4 خانات فقط: اسم المشروع، الشركة، تاريخ الاستلام، المسؤول', 'الباقي تكمله لاحقًا من بطاقة المشروع', 4500);
  await click(p, '[data-action=save]', { after: 1200 });
  await cap(p, 'إن نسيت خانة: تظهر قائمة في الأعلى، والضغط على اسمها يأخذك إليها مباشرة', '', 5000);
  await click(p, '.ps-errsum [data-err-jump]', { after: 800 });
  await type(p, '[data-bind=name]', 'إنارة مجمع النخيل السكني');
  await type(p, '[data-company=owner]', 'شركة النخيل للاستثمار');
  await p.keyboard.press('Escape');
  await cap(p, 'الشركة: اكتب الاسم واختر من الدليل، أو تُضاف شركة جديدة تلقائيًا', '', 4000);
  await click(p, '[data-action=save]', { after: 1500 });
  await cap(p, '✔ حُفظ الطلب برقم مشروع، وحالته «⏳ لم يُحدد بعد»', 'لا يظهر في متابعة العروض حتى تقرّر «مشروع؟ نعم»', 5000);

  // 4. القرار: مشروع؟
  await step(p, '4 · القرار: مشروع؟');
  await go(p, '#/projets');
  await cap(p, 'في «المشاريع»: عمود «مشروع؟» — نعم / لا / ⏳', 'الفلاتر السريعة في الأعلى تعرض عدد كل حالة', 4500);
  await click(p, '[data-chip="isProject"][data-value="2"]', { after: 1200 });
  const target = await p.evaluate(async () => (await PS.api.get('projects?q=' + encodeURIComponent('مجمع النخيل'))).rows[0].id);
  await hl(p, `.ps-table--list tr[data-id="${target}"], .ps-table--list [data-pid="${target}"]`);
  await cap(p, 'اضغط «نعم» عندما يصبح الطلب عرضًا', 'أو «لا» واختر السبب: يصبح «🚫 مشروع مُلغى» ولا يُحسب في العروض', 4500);
  await click(p, `.ps-table--list [data-pid="${target}"][data-yn="1"]`, { after: 1200 });
  await cap(p, 'رسالة تذكّرك بما ينقص (رقم العرض، الأصناف…) مع زر «فتح البطاقة»', 'وبعد كل تغيير سريع يظهر زر «↩ تراجع» لمدة 8 ثوانٍ', 5500);
  await click(p, '.ps-toast [data-toast-action]', { after: 1800 });

  // 5. بطاقة المشروع
  await step(p, '5 · بطاقة المشروع');
  await cap(p, 'بطاقة المشروع: أزرار في الأعلى للانتقال بين الأقسام', 'الخانات الصفراء إجبارية، والثانوية في «المزيد من الحقول»', 5000);
  await click(p, '[data-action="suggest-offer"]', { after: 800 });
  await cap(p, '«اقتراح رقم» يكتب رقم العرض تلقائيًا', '', 3500);
  await click(p, '[data-jump="ps-sec-items"]', { after: 1200 });
  await cap(p, 'الأصناف المطلوبة: لكل صنف الموظف المسؤول عنه والأجل', 'كل صنف يصبح سطرًا في «متابعة العروض»', 4500);
  await wait(p, 600);
  await click(p, '[data-action=save]', { after: 1500 });
  await cap(p, 'عند الحفظ تظهر الخانات الناقصة في قائمة واحدة — كل اسم رابط إلى خانته', 'أكمل البيانات ثم «💾 حفظ التعديلات»', 5500);
  await p.mouse.wheel(0, -3000); await wait(p, 600);

  // 6. مشروع كامل : التبويبات
  const demo = await p.evaluate(async () => (await PS.api.get('projects?q=D01')).rows[0].id);
  await go(p, '#/projet?id=' + demo);
  await cap(p, 'مشروع كامل: في الأعلى الحالة والأولوية، وتحتها 4 تبويبات', 'البيانات · سجل المتابعة · الملفات · سجل التعديلات', 5000);
  await click(p, '.ps-tab[data-tab="followups"]', { after: 1200 });
  await cap(p, 'سجل المتابعة: كل اتصال أو اجتماع مع الحريف', 'حدّد «تاريخ المتابعة القادمة» ليظهر في «مهامي» في يومه', 4500);
  await type(p, 'form[data-followup] textarea[name=text]', 'اتصال بالحريف: ينتظر الموافقة على العرض', 45);
  await type(p, 'form[data-followup] input[name=nextDate]', '26/10/2026', 60);
  await click(p, 'form[data-followup] [type=submit]', { after: 1500 });
  await click(p, '.ps-tab[data-tab="files"]', { after: 1200 });
  await cap(p, 'الملفات: اسحب الملف إلى المربع أو اضغط لاختياره', 'كل الأنواع (PDF، Excel، Word، صور…) — محمية ولا تُفتح إلا من داخل الموقع', 5000);
  await click(p, '.ps-tab[data-tab="log"]', { after: 1200 });
  await cap(p, 'سجل التعديلات: من غيّر ماذا ومتى — لا يضيع شيء', '', 4000);

  // 7. متابعة العروض
  await step(p, '6 · متابعة العروض');
  await go(p, '#/offres');
  await cap(p, 'متابعة العروض: سطر لكل صنف', 'الأحمر = متأخر «لازم قرار» · الأخضر = مقبول', 4500);
  await hl(p, '[data-chips="offer"]');
  await cap(p, 'الفلاتر السريعة: ⚠ لازم قرار (ومنها ما يخصك)، وكل حالة بعددها', '', 4500);
  await hl(p, '[data-chips="offer"]', false);
  await click(p, '[data-chip="offer"][data-value="late"]', { after: 1300 });
  await cap(p, 'غيّر «حالة العرض» مباشرة من الجدول', '«لم يُرسل» يطلب السبب، و«مرسل» يطلب تاريخ الإرسال', 4500);
  const lateRow = await p.$('.ps-table--list tbody tr[data-row] [data-field="offerStatusId"]');
  if (lateRow) {
    const sent = await p.evaluate(() => PS.lists.byCode('offer_status', 'sent').id);
    await moveTo(p, lateRow);
    await lateRow.selectOption(String(sent));
    await wait(p, 1500);
    if (await p.$('.ps-modal [data-q="date"]')) { await cap(p, 'تاريخ الإرسال مقترح (اليوم) ويمكن تغييره', '', 3500); await click(p, '.ps-modal [data-btn=ok]', { after: 1500 }); }
    await cap(p, '✔ تم الحفظ — وزر «↩ تراجع» إن أخطأت', '', 3500);
  }
  const anyRow = await p.$('.ps-table--list tbody tr[data-row] td:nth-child(4)');
  await go(p, '#/offres');
  await click(p, '.ps-table--list tbody tr[data-row] td:nth-child(4)', { after: 1300 });
  await cap(p, 'الضغط على السطر يفتح اللوحة الجانبية: كل تفاصيل العرض', 'رقم فاتورة العرض، المسؤول والمتابع (التغيير يتطلب سببًا)، الملاحظة، الملفات والفواتير', 6000);
  await click(p, '.ps-modal [data-btn="invoices"]', { after: 1500 });

  // 8. الفواتير
  await step(p, '7 · الفواتير');
  await cap(p, '🧾 الفواتير: كل فاتورة تخص العرض كاملًا (كل أصنافه)', '', 4000);
  await click(p, '.ps-modal [data-btn="new"]', { after: 1300 });
  await type(p, '.ps-modal [name=invoiceNo]', 'FA-2026-118', 70);
  await hl(p, '.ps-modal [name=replyDeadline]');
  await cap(p, '«آخر أجل للرد» مقترح تلقائيًا: تاريخ الإرسال + 15 يومًا (يمكن تغييره)', 'تذكير قبل الأجل بـ3 أيام، يوم الأجل، ثم كل 15 يومًا حتى يرد الحريف', 6000);
  await hl(p, '.ps-modal [name=replyDeadline]', false);
  await click(p, '.ps-modal [data-btn=save]', { after: 1800 });
  const closeBtn = await p.$('.ps-modal [data-btn=close]');
  if (closeBtn) await click(p, closeBtn, { after: 800 });
  await go(p, '#/factures');
  await cap(p, 'صفحة «الفواتير»: في الانتظار، المتأخرة، والأجوبة «نعم / لا»', 'غيّر «جواب الحريف» عندما يرد', 5000);

  // 9. التسليم
  await step(p, '8 · متابعة التسليم');
  await go(p, '#/livraison');
  await cap(p, 'متابعة التسليم: أجل التسليم، الحالة، والتأخير محسوب تلقائيًا', '«متأخر» يظهر وحده عندما يمر الأجل — لا تختاره يدويًا', 5500);
  await click(p, '[data-chip="delivery"][data-value="late"]', { after: 1300 });
  await cap(p, 'الضغط على السطر لتسجيل تاريخ التسليم الفعلي وفاتورة المورد', '', 4000);

  // 10. مهمة جديدة
  await step(p, '9 · مهمة لموظف');
  await go(p, '#/taches');
  await click(p, '[data-new-task]', { after: 1200 });
  await cap(p, '📌 مهمة جديدة: اختر الموظف واكتب المهمة والأجل', 'يمكن تسجيل رسالة صوتية 🎙 — تصله في «مهامي» وبالبريد / Telegram', 5000);
  const emp = await p.$eval('.ps-modal select[name=employeeId] option:nth-child(3)', (o) => o.value);
  await moveTo(p, '.ps-modal select[name=employeeId]');
  await p.selectOption('.ps-modal select[name=employeeId]', emp); await wait(p, 700);
  await type(p, '.ps-modal input[name=title]', 'جهّز عرض الأسعار لمجمع النخيل', 50);
  await click(p, '.ps-modal [data-btn=save]', { after: 2000 });
  await cap(p, 'المهام التي أرسلتها تبقى في «مهام أرسلتها» حتى ينجزها الموظف', '', 4500);

  await capOff(p); await step(p, '');
  await card(p, 'ملخّص يوم المسؤول', ['1. افتح «مهامي» وعالج سطر «اليوم»', '2. سجّل كل طلب جديد بـ«⚡ إدخال سريع» ثم قرّر «مشروع؟»', '3. تابع العروض والفواتير والتسليمات — والمتابعة بعد كل اتصال'], 9000);
  await ctx.close();
}

async function mobile(browser) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, recordVideo: { dir: OUT + '/raw-mobile', size: { width: 390, height: 844 } } });
  await ctx.addInitScript(OVERLAY);
  const p = await ctx.newPage();
  await p.goto(BASE + '/');
  await p.fill('input[name=log]', 'masoul'); await p.fill('input[name=pwd]', PASS);
  await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
  await p.waitForSelector('#ps-tabbar a'); await wait(p, 1000);
  await p.addStyleTag({ content: '#vid-cap{font-size:17px;bottom:86px;max-width:94%;padding:10px 14px} #vid-cap small{font-size:14px}' });
  await cap(p, 'على الهاتف: نفس الموقع، والشريط السفلي للتنقل بضغطة واحدة', '', 5000);
  await click(p, '#ps-tabbar a[href="#/projets"]', { after: 1500 });
  await cap(p, 'المشاريع تظهر كبطاقات — اضغط على البطاقة للتفاصيل', '', 4500);
  await click(p, '#ps-tabbar .ps-tabbar__a--add', { after: 1500 });
  await cap(p, '＋ في الوسط = إدخال سريع لطلب جديد من أي مكان', '', 4500);
  await click(p, '#ps-tabbar a[href="#/offres"]', { after: 1500 });
  await cap(p, '«المزيد» يفتح القائمة الكاملة', '', 3500);
  await click(p, '#ps-tabbar [data-tab-more]', { after: 2000 });
  await capOff(p);
  await wait(p, 800);
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  await desktop(browser);
  await mobile(browser);
  await browser.close();
  console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
