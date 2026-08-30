import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const password = process.env.BETA_DEMO_PASSWORD;

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(`beta.${role}@newdaytech.test`);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/login/);
}

async function expectAccessible(page) {
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact))).toEqual([]);
}

test.skip(!password, 'BETA_DEMO_PASSWORD is required.');

test('Office desktop navigation collapses, persists, and preserves responsive behavior', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'The desktop project covers desktop and mobile widths.');
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'super_admin');

    const root = page.locator('html');
    const toggle = page.getByRole('button', { name: 'Collapse office navigation' });
    const preferenceKey = await root.getAttribute('data-office-sidebar-key');
    await page.evaluate((key) => localStorage.removeItem(key), preferenceKey);
    await page.reload();

    await expect(root).toHaveAttribute('data-office-sidebar-state', 'expanded');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');

    for (const width of [1280, 1440, 1920]) {
        await page.setViewportSize({ width, height: 900 });
        if (await root.getAttribute('data-office-sidebar-state') === 'collapsed') {
            await page.getByRole('button', { name: 'Expand office navigation' }).click();
        }
        const expandedWidth = await page.locator('[data-office-shell-grid] > div').evaluate((element) => element.getBoundingClientRect().width);
        const currentUrl = page.url();
        await page.getByRole('button', { name: 'Collapse office navigation' }).focus();
        await page.keyboard.press('Space');
        await expect(root).toHaveAttribute('data-office-sidebar-state', 'collapsed');
        await expect(page.getByRole('button', { name: 'Expand office navigation' })).toBeFocused();
        await expect(page.getByRole('button', { name: 'Expand office navigation' })).toHaveAttribute('aria-expanded', 'false');
        expect(page.url()).toBe(currentUrl);
        const collapsedWidth = await page.locator('[data-office-shell-grid] > div').evaluate((element) => element.getBoundingClientRect().width);
        expect(collapsedWidth).toBeGreaterThan(expandedWidth + 150);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.keyboard.press('Tab');
    const focusedTooltip = await page.evaluate(() => ({
        label: document.activeElement?.getAttribute('aria-label'),
        opacity: getComputedStyle(document.activeElement, '::after').opacity,
        content: getComputedStyle(document.activeElement, '::after').content,
    }));
    expect(focusedTooltip.label).toBe('Home');
    expect(focusedTooltip.opacity).toBe('1');
    expect(focusedTooltip.content).toContain('Home');

    await page.getByRole('link', { name: 'Customers', exact: true }).first().click();
    await expect(page.locator('aside [data-office-primary-customers]')).toHaveAttribute('aria-current', 'page');
    await expect(root).toHaveAttribute('data-office-sidebar-state', 'collapsed');
    await page.reload();
    await expect(root).toHaveAttribute('data-office-sidebar-state', 'collapsed');

    await page.goto('/office/customers?status=active');
    const filteredUrl = page.url();
    await page.getByRole('button', { name: 'Expand office navigation' }).click();
    expect(page.url()).toBe(filteredUrl);
    await page.getByRole('button', { name: 'Collapse office navigation' }).click();
    expect(page.url()).toBe(filteredUrl);
    await expectAccessible(page);

    await page.emulateMedia({ reducedMotion: 'reduce' });
    expect(await page.locator('[data-office-shell-grid]').evaluate((element) => getComputedStyle(element).transitionDuration)).toBe('0s');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await login(page, 'billing');
    const billingKey = await root.getAttribute('data-office-sidebar-key');
    expect(billingKey).not.toBe(preferenceKey);
    await expect(root).toHaveAttribute('data-office-sidebar-state', 'expanded');
    await page.getByRole('button', { name: 'Collapse office navigation' }).click();

    for (const viewport of [{ width: 390, height: 844 }, { width: 768, height: 1024 }]) {
        await page.setViewportSize(viewport);
        await expect(page.locator('[data-office-sidebar]')).toBeHidden();
        await expect(page.getByRole('navigation', { name: 'Office mobile' })).toBeVisible();
        await expect(page.locator('[data-office-sidebar-toggle]')).toBeHidden();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await expectAccessible(page);
});
