// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const mainHtmlPath = path.resolve(__dirname, '../davao_food_finder/Main.html');

test.beforeEach(async ({ page }) => {
  const html = await fs.promises.readFile(mainHtmlPath, 'utf8');
  await page.setContent(html);
  await page.emulateMedia({ reducedMotion: 'reduce' });
});

test('scroll-to-bottom button appears away from the bottom and hides at the bottom', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 650 });
  await page.evaluate(() => window.scrollTo(0, 0));

  const button = page.locator('#jumpToBottom');
  await expect(button).toBeVisible();
  await button.click();
  await expect(button).toBeHidden();

  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(button).toBeVisible();
});

test('sticky message preview tracks the most recently passed user message and jumps to it', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 650 });
  await page.evaluate(() => {
    const thread = document.querySelector('#thread');
    thread.replaceChildren();
    ['burger', 'ramen', 'coffee'].forEach(query => {
      const userMessage = document.createElement('div');
      userMessage.className = 'bubble user';
      userMessage.textContent = query;
      thread.appendChild(userMessage);

      const response = document.createElement('div');
      response.className = 'bubble assistant';
      response.style.minHeight = '650px';
      response.textContent = `Response for ${query}`;
      thread.appendChild(response);
    });
  });

  const messageButton = page.locator('#jumpToMessage');
  const userMessages = page.locator('.bubble.user');
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await expect(messageButton).toHaveText('coffee');
  await messageButton.click();
  await expect(userMessages.nth(2)).toBeInViewport();

  await page.evaluate(() => {
    const message = document.querySelectorAll('.bubble.user')[1];
    window.scrollTo(0, message.getBoundingClientRect().bottom + window.scrollY - 20);
  });
  await expect(messageButton).toHaveText('ramen');

  await page.evaluate(() => {
    const message = document.querySelector('.bubble.user');
    window.scrollTo(0, message.getBoundingClientRect().bottom + window.scrollY - 20);
  });
  await expect(messageButton).toHaveText('burger');
});
