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
        for (const path of ['/office/dispatch', '/office/closeout-reviews', '/office/billing-handoffs', '/office/billing/settings', '/office/operations/health', '/office/admin/archive']) {
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

        await page.goto('/office/billing-handoffs');
        await page.getByRole('link', { name: 'Open invoice' }).first().click();
        await expect(page.getByRole('heading', { name: /NDT-INV-/ })).toBeVisible();
        await expectAccessible(page);
        await page.getByRole('link', { name: 'Customer presentation' }).click();
        await expect(page.getByRole('heading', { name: /NDT-INV-/ })).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);

        await page.goto('/office/service-tickets?search=NDT-ST-2026-9001');
        await page.getByRole('link', { name: /BETA A:/ }).click();
        const launcher = page.getByRole('button', { name: 'Open execution' }).first();
        await launcher.focus();
        await launcher.click();
        const dialog = page.getByRole('dialog', { name: 'Execution workspace' });
        await expect(dialog).toBeVisible();
        const dimensions = await dialog.evaluate((element) => ({ width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }));
        expect(dimensions.width).toBeGreaterThanOrEqual(0.9 * 1440);
        expect(dimensions.height).toBeLessThanOrEqual(900);
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(launcher).toBeFocused();

        const manualStart = page.getByRole('button', { name: 'Start manual closeout' });
        if (await manualStart.count()) {
            await manualStart.click();
        } else {
            await page.getByRole('button', { name: 'Manual closeout' }).click();
        }
        const manualDialog = page.getByRole('dialog', { name: 'Administrative closeout' });
        await expect(manualDialog).toBeVisible();
        const manualDimensions = await manualDialog.evaluate((element) => ({ width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }));
        expect(manualDimensions.width).toBeGreaterThanOrEqual(0.9 * 1440);
        expect(manualDimensions.height).toBeLessThanOrEqual(900);
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        const manualLauncher = page.getByRole('button', { name: 'Manual closeout' });
        await manualLauncher.focus();
        await manualLauncher.click();
        await expect(manualDialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(manualLauncher).toBeFocused();
    });
});

test.describe('mobile beta', () => {
    test.skip(({ isMobile }) => !isMobile);

    test('field today and visit workspace meet mobile and offline contracts', async ({ page, context }) => {
        await login(page, 'technician');
        await page.goto('/field');
        await expect(page.getByRole('heading', { name: 'Past 7 days' })).toBeVisible();
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

    test('issued invoice presentation is customer-safe at phone width', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office/billing-handoffs');
        await page.getByRole('link', { name: 'Open invoice' }).first().click();
        await page.getByRole('link', { name: 'Customer presentation' }).click();
        await expect(page.getByRole('heading', { name: /NDT-INV-/ })).toBeVisible();
        await expect(page.getByText('Invoice acknowledgment')).toBeVisible();
        await expect(page.getByText('Internal billing note')).toHaveCount(0);
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        const shortControls = await page.locator('button, input, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
    });
});
