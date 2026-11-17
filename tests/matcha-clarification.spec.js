// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const mainHtmlPath = path.resolve(__dirname, '../davao_food_finder/Main.html');

test.beforeEach(async ({ page }) => {
  const html = await fs.promises.readFile(mainHtmlPath, 'utf8');
  await page.route('http://localhost/api.php**', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ answer: 'ok', sources: [], webResults: [], localResults: [], mapResults: [] }),
  }));
  await page.setContent(html.replace('<head>', '<head><base href="http://localhost/">'));
});

for (const choice of [
  { label: 'Matcha drinks', query: 'matcha drinks and cafes' },
  { label: 'Matcha desserts', query: 'matcha cakes and desserts' },
]) {
  test(`selecting ${choice.label.toLowerCase()} searches without repeating clarification`, async ({ page }) => {
    let searchRequests = 0;
    page.on('request', request => {
      if (request.url().startsWith('http://localhost/api.php')) searchRequests += 1;
    });

    await page.locator('#query').fill('matcha');
    await page.locator('#searchForm').evaluate(form => form.requestSubmit());
    await expect(page.locator('.clarification-prompt')).toHaveCount(1);

    await page.getByRole('button', { name: choice.label, exact: true }).click();

    await expect(page.locator('.bubble.user')).toContainText(choice.query);
    await expect(page.locator('.clarification-prompt')).toHaveCount(1);
    expect(searchRequests).toBe(1);
  });
}
