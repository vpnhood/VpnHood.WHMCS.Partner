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
  await page.evaluate((pairs) => {
    for (const [frag, val] of pairs) {
      document.querySelectorAll('input,textarea').forEach((el) => {
        if ((el.name || '').includes(frag)) {
          el.value = val;
          el.setAttribute('value', val);
          if (el.type === 'password') el.type = 'text'; // show the placeholder, not dots
        }
      });
    }

    // Money -> a neutral example. Walk TEXT NODES: the "Credit balance:" label and the
    // figure live in different nodes, so keying off the label misses the number.
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const money = /\b[\d,]+\.\d{2}(\s*[A-Z]{3})?\b/;
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (money.test(n.nodeValue)) n.nodeValue = n.nodeValue.replace(money, '100.00 USD');
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
  }, PLACEHOLDERS);
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
    (hay.match(/\b[\d,]+\.\d{2}(\s*[A-Z]{3})?\b/g) || [])
      .filter(m => !/^100\.00/.test(m)).forEach(m => bad.push('money:' + m));
    if (/whmcs-dev|localhost|127\.0\.0\.1/i.test(hay)) bad.push('non-public host in frame');
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
  await page.goto(`${BASE}/admin/addonmodules.php?module=vpnhoodpartnerconfig`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);
  const productId = PRODUCT_ID || await page.evaluate(() => {
    const a = [...document.querySelectorAll('a')]
      .find(x => /configproducts\.php\?action=edit&id=\d+/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : '';
  });
  await redact(page);
  await page.evaluate(() => {
    const h = [...document.querySelectorAll('h1,h2,h3')].find(x => /Upstream Products/i.test(x.textContent));
    const el = h ? (h.closest('div') || h.parentElement) : document.querySelector('#contentarea');
    if (el) { el.id = 'shot-addon'; el.scrollIntoView({ block: 'center' }); }
  });
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-addon', '03-addon-page.png', 'addon page');

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
