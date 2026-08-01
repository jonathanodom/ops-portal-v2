import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const password = process.env.BETA_DEMO_PASSWORD;

async function login(page, role) {
    await page.goto('/login');
    await expectAccessible(page);
    await page.getByLabel('Email address').fill(`beta.${role}@newdaytech.test`);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/login/);
}

async function expectAccessible(page) {
    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact));
    expect(serious).toEqual([]);
}

test.skip(!password, 'BETA_DEMO_PASSWORD is required.');

test.describe('desktop beta', () => {
    test.skip(({ isMobile }) => isMobile);

    test('dispatch, review, billing, and health remain keyboard accessible', async ({ page }) => {
        await login(page, 'super_admin');
        for (const path of ['/office/dispatch', '/office/closeout-reviews', '/office/billing-handoffs', '/office/operations/health']) {
            await page.goto(path);
            await expect(page.locator('body')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
        }
        await page.keyboard.press('Tab');
        const focusVisible = await page.evaluate(() => {
            const style = getComputedStyle(document.activeElement);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });
        expect(focusVisible).toBeTruthy();
    });
});

test.describe('mobile beta', () => {
    test.skip(({ isMobile }) => !isMobile);

    test('field today and visit workspace meet mobile and offline contracts', async ({ page, context }) => {
        await login(page, 'technician');
        await page.goto('/field');
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        const visitLink = page.getByRole('link', { name: /BETA A:/ }).first();
        await expect(visitLink).toBeVisible();
        await visitLink.click();
        await expectAccessible(page);
        const shortControls = await page.locator('button, input, select, textarea, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}.${element.className}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await expect(page.locator('[data-connectivity-banner]')).toBeVisible();
        const enabledWriteButtons = await page.locator('form[method="POST"] button:not([disabled])').count();
        expect(enabledWriteButtons).toBe(0);
        await context.setOffline(false);
    });
});
