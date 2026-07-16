import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const rootDir = path.resolve(new URL('..', import.meta.url).pathname);
const baseUrl = (process.env.DISOWN_SCREENSHOT_BASE_URL || 'https://sicher.bbs-einbeck.de/disown-dev').replace(/\/+$/, '');
const outputDir = path.resolve(rootDir, process.env.DISOWN_SCREENSHOT_DIR || 'docs/screenshots/review');
const storageStatePath = path.resolve(rootDir, process.env.DISOWN_SCREENSHOT_STATE || '.auth/screenshots-state.json');
const username = process.env.DISOWN_SCREENSHOT_USER || '';
const password = process.env.DISOWN_SCREENSHOT_PASSWORD || '';
const fullPage = process.env.DISOWN_SCREENSHOT_FULL_PAGE === '1';
const maskSensitive = process.env.DISOWN_SCREENSHOT_MASK === '1'
  || (process.env.DISOWN_SCREENSHOT_PROFILE || '').includes('masked');

const pages = [
  { name: 'admin-open', url: '/admin.php?filter=open&page=1', waitFor: 'h1' },
  { name: 'admin-all', url: '/admin.php?filter=all&page=1', waitFor: 'table' },
  { name: 'admin-cases', url: '/admin.php?filter=cases&page=1', waitFor: 'table' },
  { name: 'ade', url: '/ade.php', waitFor: 'table' },
  { name: 'kuk', url: '/kuk/', waitFor: 'table' },
  { name: 'audit-log', url: '/audit_log.php', waitFor: 'table' },
  { name: 'settings-system', url: '/settings.php?tab=system', waitFor: 'h2' }
];

const viewports = [
  { suffix: 'desktop', width: 1920, height: 1200, deviceScaleFactor: 1 },
  { suffix: 'mobile', width: 390, height: 844, deviceScaleFactor: 2, isMobile: true }
];
if (process.env.DISOWN_SCREENSHOT_WIDE === '1') {
  viewports.push({ suffix: 'wide', width: 2560, height: 1440, deviceScaleFactor: 1 });
}

await fs.mkdir(outputDir, { recursive: true });
await fs.mkdir(path.dirname(storageStatePath), { recursive: true });

const browser = await chromium.launch({ headless: true });

try {
  for (const viewport of viewports) {
    const contextOptions = {
      viewport: { width: viewport.width, height: viewport.height },
      deviceScaleFactor: viewport.deviceScaleFactor,
      isMobile: viewport.isMobile === true,
      hasTouch: viewport.isMobile === true,
      ignoreHTTPSErrors: true
    };

    if (username && password) {
      contextOptions.httpCredentials = { username, password };
    }

    try {
      await fs.access(storageStatePath);
      contextOptions.storageState = storageStatePath;
    } catch {
      // A stored OIDC/session state is optional. Basic auth or an already open DEV instance may be enough.
    }

    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();

    for (const target of pages) {
      const url = `${baseUrl}${target.url}`;
      console.log(`capture ${viewport.suffix}: ${url}`);
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(600);

      const title = await page.title();
      const loginVisible = await page.locator('text=Mit IServ anmelden').count();
      if (loginVisible > 0) {
        throw new Error(`Login page reached for ${url}. Set DISOWN_SCREENSHOT_USER/PASSWORD or provide ${storageStatePath}. Title: ${title}`);
      }

      await page.locator(target.waitFor).first().waitFor({ state: 'visible', timeout: 15000 });
      if (maskSensitive) {
        await maskSensitiveData(page);
      }
      await page.evaluate(() => {
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, 0);
      });

      const file = path.join(outputDir, `${target.name}-${viewport.suffix}.png`);
      await page.screenshot({
        path: file,
        fullPage,
        animations: 'disabled'
      });
    }

    await context.storageState({ path: storageStatePath });
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`screenshots written to ${outputDir}`);

async function maskSensitiveData(page) {
  await page.evaluate(() => {
    const maskEmail = (value) => value.replace(/\b([A-Z0-9._%+-])[A-Z0-9._%+-]*(@[A-Z0-9.-]+\.[A-Z]{2,})\b/gi, '$1...$2');
    const maskSerial = (value) => value.replace(/\b([A-Z0-9]{2})[A-Z0-9]{6,10}([A-Z0-9]{2})\b/g, '$1••••••$2');
    const keepWords = new Set([
      'admin', 'adminportal', 'ade', 'air', 'alle', 'antrag', 'antraege', 'anträge', 'asset', 'audit',
      'benutzer', 'broker', 'bulk', 'csv', 'daten', 'details', 'dev', 'device', 'disown',
      'email', 'e-mail', 'enrolled', 'erledigt', 'fehler', 'freigabe', 'freigaben', 'generation',
      'gerät', 'geraet', 'geräte', 'geraete', 'ipad', 'jamf', 'klasse', 'klärfall', 'klaerfall',
      'kuk', 'letzter', 'login', 'mail', 'manually_added', 'mdm', 'modell', 'name', 'nicht',
      'offen', 'only', 'owner', 'private', 'quelle', 'release', 'school', 'schulische',
      'seriennummer', 'status', 'sync', 'trash', 'updated', 'wi-fi', 'wunsch'
    ]);
    const maskWord = (word) => {
      if (word.length <= 2 || keepWords.has(word.toLowerCase())) {
        return word;
      }
      const visiblePrefixLength = Math.min(2, word.length);
      return `${word.slice(0, visiblePrefixLength)}${'•'.repeat(Math.min(3, Math.max(2, word.length - visiblePrefixLength)))}`;
    };
    const maskNames = (value) => value.replace(/\b[\p{L}][\p{L}.'-]{2,}\b/gu, maskWord);
    const maskText = (value, maskWords = false) => {
      let masked = maskSerial(maskEmail(value));
      if (maskWords) {
        masked = maskNames(masked);
      }
      return masked;
    };

    const sensitiveHeaders = ['person', 'gerät', 'geraet', 'seriennummer', 'owner', 'benutzer', 'jamf-daten', 'jamf'];
    const detailHeaders = ['details', 'status'];

    const replaceTextNodes = (element, maskWords = false) => {
      const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
      const nodes = [];
      while (walker.nextNode()) {
        nodes.push(walker.currentNode);
      }
      for (const node of nodes) {
        node.nodeValue = maskText(node.nodeValue || '', maskWords);
      }
    };

    for (const table of document.querySelectorAll('table')) {
      const headers = Array.from(table.querySelectorAll('thead th')).map((header) => (header.textContent || '').trim().toLowerCase());
      for (const row of table.querySelectorAll('tbody tr')) {
        const cells = Array.from(row.children);
        cells.forEach((cell, index) => {
          const header = headers[index] || '';
          if (sensitiveHeaders.some((needle) => header.includes(needle))) {
            replaceTextNodes(cell, true);
          } else if (detailHeaders.some((needle) => header.includes(needle))) {
            replaceTextNodes(cell, false);
          }
        });
      }
    }

    for (const selector of [
      '.admin-user',
      '.admin-rank-name',
      '.user-cell',
      '.owner-cell',
      '[href^="mailto:"]'
    ]) {
      for (const element of document.querySelectorAll(selector)) {
        replaceTextNodes(element, selector !== '[href^="mailto:"]');
      }
    }

    for (const input of document.querySelectorAll('input[value], textarea')) {
      if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
        input.value = maskText(input.value, false);
      }
    }
  });
}
