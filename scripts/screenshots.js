// Builds the documentation screenshots: node scripts/screenshots.js  (needs Docker + Playwright)
// Starts a throwaway PHP server, installs a demo instance, fills it with made-up traffic, and captures the pages.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '..');
const out = path.join(root, 'docs', 'screenshots');
const PORT = 18900, NAME = 'auc-shots';
const BASE = `http://127.0.0.1:${PORT}/analytics-ultimate`;
const PW = 'demo-password-123';
const sh = (c) => execSync(c, { stdio: 'pipe' }).toString();

(async () => {
  try { sh(`docker stop ${NAME}`); } catch (e) {}
  sh(`docker run -d --rm --name ${NAME} -p 127.0.0.1:${PORT}:8080 -v ${root}:/srv/home/public_html/analytics-ultimate:ro php:8.3-cli php -S 0.0.0.0:8080 -t /srv/home/public_html`);
  await new Promise((r) => setTimeout(r, 1500));
  const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 800 }, colorScheme: 'light', deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  // Show a demo domain instead of the local test address.
  const demoUrls = () => page.evaluate((from) => {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n; (n = w.nextNode()); ) n.nodeValue = n.nodeValue.split(from).join('https://stats.example.com');
  }, `http://127.0.0.1:${PORT}`);
  const shot = async (name, opts = {}) => { await demoUrls(); await page.screenshot({ path: path.join(out, name + '.png'), ...opts }); console.log('saved', name); };

  await page.goto(`${BASE}/setup.php`);
  await page.fill('#sites', 'example.com\nshop.example.com\nblog.example.com');
  await page.selectOption('#timezone', 'America/New_York');
  await page.fill('#password', PW); await page.fill('#password2', PW);
  await shot('setup', { fullPage: true });
  await page.click('button[type=submit]');
  await page.waitForSelector('text=all set');
  await shot('setup-done', { fullPage: true });

  sh(`docker exec ${NAME} php /srv/home/public_html/analytics-ultimate/tests/seed-demo.php`);
  await page.goto(`${BASE}/index.php`);
  await page.fill('input[name=password]', PW); await page.keyboard.press('Enter');
  await page.waitForSelector('text=Pageviews by day');
  await page.setViewportSize({ width: 1200, height: 900 });
  await shot('overview');
  await page.goto(`${BASE}/index.php?tab=projects&range=90`); await shot('projects', { fullPage: true });
  await page.goto(`${BASE}/index.php?tab=downloads`); await shot('downloads', { fullPage: true });
  await page.goto(`${BASE}/index.php?tab=settings`); await shot('settings', { fullPage: true });
  await page.setViewportSize({ width: 1200, height: 1500 });
  await page.goto(`${BASE}/index.php?tab=overview&range=30`); await shot('overview-full', { fullPage: true });
  await browser.close();
  sh(`docker stop ${NAME}`);
})().catch((e) => { console.error(e); try { sh(`docker stop ${NAME}`); } catch (x) {} process.exit(1); });
