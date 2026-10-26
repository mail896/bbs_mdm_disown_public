import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Render only fixtures; never authenticate, fetch live data, or submit forms.
const here = path.dirname(fileURLToPath(import.meta.url));
const root = process.argv[2] || path.dirname(here);
const cssPath = process.argv[3] || path.join(root, 'assets/admin.css');
const require = createRequire(path.join(root, 'package.json'));
const { chromium } = require('playwright');
const fixture = process.argv[4] || path.join(here, 'fixtures/render_admin_button_layout.php');
const html = execFileSync('php', [fixture, root, cssPath], { encoding: 'utf8' });
const browser = await chromium.launch({ headless: true });
let checks = 0;
try {
  for (const width of [1920, 1280, 1024, 768, 641, 640, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
    await page.route('**/*', route => route.abort());
    await page.setContent(html);
    const measurements = await page.evaluate(() => [...document.querySelectorAll('[data-layout-case]')].map(row => {
      const cell = row.querySelector('.action-cell');
      const status = row.querySelector('.status-cell');
      const button = cell.querySelector('.button');
      const steps = [...status.querySelectorAll('.process-step')];
      const cellRect = cell.getBoundingClientRect();
      const statusRect = status.getBoundingClientRect();
      const buttonRect = button?.getBoundingClientRect();
      const desktop = innerWidth > 640;
      const processRight = Math.max(...steps.map(step => step.getBoundingClientRect().right));
      const cellStyle = getComputedStyle(cell);
      const paddingLeft = parseFloat(cellStyle.paddingLeft);
      const paddingRight = parseFloat(cellStyle.paddingRight);
      return {
        name: row.dataset.layoutCase,
        label: button?.textContent.trim() || '',
        expected: row.dataset.expectedAction,
        actionWidth: cellRect.width - paddingLeft - paddingRight,
        buttonWidth: buttonRect?.width || 0,
        statusContained: !desktop || processRight <= statusRect.right - parseFloat(getComputedStyle(status).paddingRight) + 1,
        gap: !desktop || !buttonRect ? null : buttonRect.left - processRight,
        actionContained: !buttonRect || (buttonRect.left >= cellRect.left + paddingLeft - 1 && buttonRect.right <= cellRect.right - paddingRight + 1),
        textUnclipped: !button || button.scrollWidth <= button.clientWidth + 1,
        mobileFits: desktop || document.documentElement.scrollWidth <= innerWidth,
      };
    }));
    for (const result of measurements) {
      checks++;
      if (result.label !== result.expected || !result.statusContained || !result.actionContained || !result.textUnclipped || !result.mobileFits || (result.gap !== null && result.gap < 16)) {
        throw new Error(`Layout failure at ${width}px: ${JSON.stringify(result)}`);
      }
    }
    if (process.env.DISOWN_LAYOUT_SCREENSHOT_DIR) {
      await page.screenshot({ path: path.join(process.env.DISOWN_LAYOUT_SCREENSHOT_DIR, `admin-buttons-${width}.png`), fullPage: true });
    }
    const gaps = measurements.filter(result => result.gap !== null).map(result => result.gap);
    const detail = gaps.length ? `minimum gap ${Math.min(...gaps).toFixed(1)}px` : 'stacked mobile layout';
    console.log(`OK: ${width}px, ${measurements.length} action states; ${detail}`);
    await page.close();
  }
  console.log(`OK: ${checks} layout cases; offline fixture HTML only, no requests or commands sent.`);
} finally {
  await browser.close();
}
