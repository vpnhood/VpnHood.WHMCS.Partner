// capture-screenshots.js — regenerate the README's WHMCS admin screenshots.
//
// Run it through scripts/capture-screenshots.ps1, which supplies the credentials.
// Output goes to docs/images/.
//
// Two rules this script exists to enforce, because doing them by hand is where
// screenshots leak things:
//
//   1. Nothing sensitive is ever rasterised. The WHMCS "Configure" screen is a single
//      shared form holding EVERY addon's settings, so a naive capture puts unrelated
//      modules' live API keys in frame. Values are overwritten in the DOM *before* the
//      screenshot, not covered by a box afterwards — a box can be cropped back off.
//   2. Every shot is an element screenshot scoped to the connector, never a full page,
//      so whatever else the source install happens to have does not appear.
//
// After redacting, a leak check scans what is actually VISIBLE in the target element
// for credential-shaped text and reports anything suspicious. Review the images before
// committing them regardless: the check is a safety net, not a substitute for looking.

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = (process.env.WHMCS_URL || 'https://whmcs-dev.vpnhood.com').replace(/\/+$/, '');
const OUT = process.env.SHOT_DIR || path.join(__dirname, '..', 'docs', 'images');
const USER = process.env.WHMCS_USER;
const PASS = process.env.WHMCS_PASS;

// Product to use for the Module Settings shot. Any product assigned to the connector
// works; the script resolves one from the addon page when this is not set.
const PRODUCT_ID = process.env.WHMCS_PRODUCT_ID || '';

// The Hub URL a fresh install is pre-filled with — keep in step with the 'Default' of
// the HubUrl field in modules/addons/vpnhoodpartnerconfig/vpnhoodpartnerconfig.php, so
// the screenshot shows a partner what they will actually see.
const DEFAULT_HUB_URL = 'https://account.vpnhood.com/';

// The install being captured may be running an older build than the repo, whose Hub URL
// field description still shows a stale example address. Render the description the
// SHIPPED module has, so the screenshot documents the version being released rather
// than whatever happens to be deployed on the capture source.
//
// Keep this identical to the 'Description' of the HubUrl field in
// modules/addons/vpnhoodpartnerconfig/vpnhoodpartnerconfig.php.
const HUB_URL_DESCRIPTION = "Your provider's WHMCS base URL.";

const PLACEHOLDERS = [
  ['[vpnhoodpartnerconfig][HubUrl]', DEFAULT_HUB_URL],
  ['[vpnhoodpartnerconfig][ApiKey]', 'your-api-key'],
  ['[vpnhoodpartnerconfig][ApiSecret]', 'your-api-secret'],
  // Anything belonging to another VpnHood module must not be legible at all.
  ['[vpnhoodconfig][APIKey]', 'REDACTED'],
  ['[vpnhoodconfig][ProjectId]', 'REDACTED'],
  ['[vpnhoodpartnerhub][OrderGateway]', 'REDACTED'],
];

async function redact(page) {
  await page.evaluate(({ pairs, hubDescription }) => {
    // Bring an older deployed build's field description up to what the repo ships.
    // Matched on the stale example address it is the only thing carrying.
    const descWalker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = descWalker.nextNode(); n; n = descWalker.nextNode()) {
      if (/yourprovider\.com/i.test(n.nodeValue)) n.nodeValue = hubDescription;
    }

    for (const [frag, val] of pairs) {
      document.querySelectorAll('input,textarea').forEach((el) => {
        if ((el.name || '').includes(frag)) {
          el.value = val;
          el.setAttribute('value', val);
          if (el.type === 'password') el.type = 'text'; // show the placeholder, not dots
        }
      });
    }

    // The credit balance -> a neutral example. Scope this to the balance line only.
    // Replacing every money-shaped string on the page corrupts real documentation: the
    // sync blurb states products are created "priced 0.00", and a blanket rule rewrote
    // that to "100.00 USD" — telling partners the exact opposite of what happens.
    // Walk TEXT NODES within that one element, because the "Credit balance:" label and
    // the figure itself live in different nodes.
    const money = /\b[\d,]+\.\d{2}(\s*[A-Z]{3})?\b/;
    const balanceEl = [...document.querySelectorAll('p,div,span,li,td,h1,h2,h3,h4')]
      .filter(e => /Credit balance/i.test(e.textContent || ''))
      .sort((a, b) => (a.textContent || '').length - (b.textContent || '').length)[0];
    if (balanceEl) {
      const walker = document.createTreeWalker(balanceEl, NodeFilter.SHOW_TEXT);
      for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (money.test(n.nodeValue)) n.nodeValue = n.nodeValue.replace(money, '100.00 USD');
      }
    }

    // A dev install runs with Skip TLS Verification on. Publishing it ticked would show
    // every partner doing the one thing that field warns against.
    document.querySelectorAll('input[type=checkbox]').forEach((el) => {
      if ((el.name || '').includes('SkipTlsVerify')) el.checked = false;
    });

    // Chrome that would confuse a partner or date the image.
    document.querySelectorAll('.global-admin-warning, .alert-warning').forEach((el) => {
      if (/Hooks Debug Mode/i.test(el.textContent || '')) el.remove();
    });
  }, { pairs: PLACEHOLDERS, hubDescription: HUB_URL_DESCRIPTION });
}

async function assertClean(page, selector, label) {
  const leaked = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return ['(element missing)'];
    // Only what is VISIBLE can reach the image. Hidden inputs (CSRF tokens and such)
    // are in the DOM but not in frame, and flagging them buries the real hits.
    const visible = n => n.offsetParent !== null || getComputedStyle(n).position === 'fixed';
    const values = [...el.querySelectorAll('input,textarea')]
      .filter(i => i.type !== 'hidden' && visible(i)).map(i => i.value).join(' ');
    const hay = (el.innerText || '') + ' ' + values;

    const SAFE = /^(reseller|partner|vpnhood)[a-z0-9-]*$/i; // product refs, module slugs
    const bad = [];
    (hay.match(/\b[A-Za-z0-9_-]{24,}\b/g) || [])
      .filter(t => !SAFE.test(t))
      .forEach(t => bad.push('token:' + t.slice(0, 12) + '…'));
    // Money is only sensitive on the credit-balance line. Elsewhere it is ordinary
    // copy — the sync blurb legitimately says products are created "priced 0.00" — so
    // flagging every figure on the page just trains you to ignore the warnings.
    const balanceEl = [...el.querySelectorAll('p,div,span,li,td')]
      .filter(e => /Credit balance/i.test(e.textContent || ''))
      .sort((a, b) => (a.textContent || '').length - (b.textContent || '').length)[0];
    if (balanceEl) {
      ((balanceEl.textContent || '').match(/\b[\d,]+\.\d{2}(\s*[A-Z]{3})?\b/g) || [])
        .filter(m => !/^100\.00/.test(m)).forEach(m => bad.push('balance:' + m));
    }
    if (/whmcs-dev|localhost|127\.0\.0\.1/i.test(hay)) bad.push('non-public host in frame');
    // Placeholder addresses that no longer exist in the module's own text. If one is
    // still in frame, the deployed build is older than the repo AND the substitution
    // above failed to catch it.
    if (/yourprovider\.com/i.test(hay)) bad.push('stale example URL in frame');
    return bad;
  }, selector);

  if (leaked.length) {
    console.log(`    !! ${label}: REVIEW BEFORE COMMITTING -> ${leaked.join(', ')}`);
    return false;
  }
  console.log(`    ok  ${label}`);
  return true;
}

async function shoot(page, selector, file, label) {
  const el = await page.$(selector);
  if (!el) { console.log(`    -- skipped ${file} (no ${selector})`); return false; }
  const clean = await assertClean(page, selector, label);
  await el.screenshot({ path: path.join(OUT, file) });
  console.log(`    +   ${file} (${Math.round(fs.statSync(path.join(OUT, file)).size / 1024)} KB)`);
  return clean;
}

// ------------------------------------------------- rendering the "missing" state
//
// The "Create Missing Product(s)" button only renders when some upstream product has no
// local product yet — vpnhoodpartnerconfig_renderSyncForm() returns early on "Nothing
// to create". On a fully synced install the button is therefore unreachable.
//
// This script is READ ONLY against the WHMCS it captures. It never creates, deletes or
// repoints a product to force that state: doing so mutates a shared install, and a run
// that dies half way leaves real data wrong. Instead the same markup the module itself
// emits for a missing product is rendered into the page before the screenshot.
//
// The markup below therefore has to mirror vpnhoodpartnerconfig_renderSyncForm(). If
// that function's output changes, change this with it, or the screenshot will document
// a UI that no longer exists.
async function renderMissingState(page) {
  return page.evaluate(() => {
    const table = [...document.querySelectorAll('table')]
      .find(t => /Upstream Product/i.test(t.innerText));
    if (!table) return 'no upstream product table';
    const rows = [...table.querySelectorAll('tbody tr')];
    if (!rows.length) return 'no product rows';

    // Show the last product as not-yet-created: ticked checkbox + "Not created yet".
    const row = rows[rows.length - 1];
    const cells = row.querySelectorAll('td');
    if (cells.length < 5) return 'unexpected row shape';
    const ref = (cells[1].textContent.match(/\(ref ([^)]+)\)/) || [])[1] || '';
    cells[0].innerHTML = `<input type="checkbox" name="refs[]" value="${ref}" checked>`;
    cells[4].innerHTML = '<span class="label label-info">Not created yet</span>';

    const form = table.closest('form');
    if (!form) return 'products table is not inside the sync form';

    // The "Nothing to create" notice is what stands where the controls belong.
    form.querySelectorAll('.alert-success').forEach((el) => {
      if (/Nothing to create/i.test(el.textContent || '')) el.remove();
    });

    const controls = document.createElement('div');
    controls.className = 'form-inline';
    controls.style.marginBottom = '10px';
    controls.innerHTML =
      '<label style="margin-right:6px">Create in product group</label>'
      + '<select name="gid" class="form-control" required>'
      + '<option value="">— Select a group —</option></select> '
      + '<button type="submit" class="btn btn-primary">Create 1 Missing Product(s)</button>';

    const note = document.createElement('p');
    note.className = 'text-muted';
    note.innerHTML =
      'New products are created <b>hidden</b>, assigned to the <b>VpnHood Partner Connector</b>'
      + ' module with the correct Upstream Product already selected, and priced <b>0.00</b> on the'
      + ' cycles the upstream offers — your provider never dictates your retail price. Set your'
      + " price on each product's <b>Pricing</b> tab, then un-hide it. Existing products are never"
      + ' modified or removed.';

    form.appendChild(controls);
    form.appendChild(note);
    return null;
  });
}

async function openProductModuleTab(page, id) {
  await page.goto(`${BASE}/admin/configproducts.php?action=edit&id=${id}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  if (await passSudo(page)) {
    await page.goto(`${BASE}/admin/configproducts.php?action=edit&id=${id}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1800);
  }
  // The Module Settings pane only populates once its tab is opened.
  await page.evaluate(() => {
    const a = [...document.querySelectorAll('a')]
      .find(x => /Module Settings/i.test(x.textContent) && (x.getAttribute('href') || '').startsWith('#'));
    if (a) a.click();
  });
  await page.waitForTimeout(2500);
}

// WHMCS puts a second password prompt in front of some admin areas (Products/Services
// among them). Until it is cleared, those pages render only the prompt.
async function passSudo(page) {
  const gated = await page.evaluate(() => /Confirm password to continue/i.test(document.body.innerText));
  if (!gated) return false;
  console.log('    (clearing WHMCS password confirmation)');
  // Submit the form the password field belongs to — the page also carries two search
  // forms, and a generic submit click lands on one of those instead.
  const ok = await page.evaluate((pw) => {
    const box = [...document.querySelectorAll('input[type=password]')].find(i => i.offsetParent !== null);
    if (!box || !box.form) return false;
    box.value = pw;
    box.dispatchEvent(new Event('input', { bubbles: true }));
    const btn = box.form.querySelector('button[type=submit], input[type=submit]');
    if (btn) btn.click(); else box.form.submit();
    return true;
  }, PASS);
  if (!ok) return false;
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  return true;
}

(async () => {
  if (!USER || !PASS) {
    console.error('WHMCS_USER and WHMCS_PASS must be set — run scripts/capture-screenshots.ps1');
    process.exit(1);
  }
  fs.mkdirSync(OUT, { recursive: true });
  let allClean = true;

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1400, height: 1000 },
    deviceScaleFactor: 2, // crisp on high-DPI screens
  });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/admin/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="username"]', USER);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (/login\.php/.test(page.url())) { console.error('login failed'); process.exit(1); }
  console.log(`logged in to ${BASE}\n`);

  // ---- 1. Addon Modules: the connector's row
  console.log('1. Addon Modules list');
  await page.goto(`${BASE}/admin/configaddonmods.php`, { waitUntil: 'networkidle' });
  await redact(page);
  await page.evaluate(() => {
    const tr = [...document.querySelectorAll('tr')]
      .find(t => /VpnHood Partner Connector Configuration/i.test(t.innerText));
    if (tr) { tr.id = 'shot-row'; tr.scrollIntoView({ block: 'center' }); }
  });
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-row', '01-addon-modules-row.png', 'addon row');

  // ---- 2. The connector's settings
  console.log('2. Configure form');
  await page.click('#vpnhoodpartnerconfig_configure');
  await page.waitForTimeout(2500);
  await redact(page);
  await page.evaluate(() => {
    const inp = document.querySelector('input[name="fields[vpnhoodpartnerconfig][HubUrl]"]');
    if (!inp) return;
    let el = inp;
    for (let i = 0; i < 8 && el.parentElement; i++) {
      el = el.parentElement;
      if (el.tagName === 'FORM' || el.querySelectorAll('input[type=text],input[type=password]').length >= 3) break;
    }
    el.id = 'shot-config';
    el.scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-config', '02-configure-settings.png', 'configure form');

  // ---- 3. The addon's own page
  console.log('3. Connector addon page');
  const gotoAddon = async () => {
    await page.goto(`${BASE}/admin/addonmodules.php?module=vpnhoodpartnerconfig`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
  };
  const nothingToCreate = () =>
    page.evaluate(() => /Nothing to create/i.test(document.body.innerText));

  await gotoAddon();
  const productId = PRODUCT_ID || await page.evaluate(() => {
    const a = [...document.querySelectorAll('a')]
      .find(x => /configproducts\.php\?action=edit&id=\d+/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : '';
  });

  const captureAddon = async () => {
    await redact(page);
    await page.evaluate(() => {
      const h = [...document.querySelectorAll('h1,h2,h3')].find(x => /Upstream Products/i.test(x.textContent));
      const el = h ? (h.closest('div') || h.parentElement) : document.querySelector('#contentarea');
      if (el) { el.id = 'shot-addon'; el.scrollIntoView({ block: 'center' }); }
    });
    await page.waitForTimeout(400);
    return shoot(page, '#shot-addon', '03-addon-page.png', 'addon page');
  };

  if (await nothingToCreate()) {
    // Fully synced, so the sync controls are not on the page. Render them the way the
    // module does for a missing product — no data is touched to bring that state about.
    const problem = await renderMissingState(page);
    if (problem) {
      console.log(`    !! could not render the missing-product state: ${problem}`);
      console.log('       capturing the page as-is; the Create button will not be in the shot');
      allClean = false;
    } else {
      console.log('    (rendered the "1 missing product" state — install untouched)');
    }
  }
  allClean &= await captureAddon();

  // ---- 4. A product's Module Settings tab
  console.log(`4. Product Module Settings tab (product ${productId || '?'})`);
  if (!productId) {
    console.log('    -- skipped: no product is assigned to the connector on this install');
  } else {
    await page.goto(`${BASE}/admin/configproducts.php?action=edit&id=${productId}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    if (await passSudo(page)) {
      await page.goto(`${BASE}/admin/configproducts.php?action=edit&id=${productId}`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(2000);
    }
    // Click by label, not a guessed #tabN — the index moves between WHMCS versions.
    const pane = await page.evaluate(() => {
      const a = [...document.querySelectorAll('a')]
        .find(x => /Module Settings/i.test(x.textContent) && (x.getAttribute('href') || '').startsWith('#'));
      if (!a) return null;
      a.click();
      return a.getAttribute('href');
    });
    await page.waitForTimeout(2500);
    await redact(page);
    await page.evaluate((sel) => {
      const el = (sel && document.querySelector(sel))
        || document.querySelector('.tab-pane.active') || document.querySelector('.tab-content');
      if (el) { el.id = 'shot-module'; el.scrollIntoView({ block: 'center' }); }
    }, pane);
    await page.waitForTimeout(400);
    allClean &= await shoot(page, '#shot-module', '04-product-module-settings.png', 'module settings');
  }

  await browser.close();
  console.log(`\nwrote to ${OUT}`);
  console.log(allClean
    ? 'No credential-shaped text found in any frame — still eyeball them before committing.'
    : 'SOMETHING WAS FLAGGED ABOVE. Do not commit until you have looked at those images.');
})().catch(e => { console.error('ERROR', e.message); process.exit(1); });
