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
        test.setTimeout(90_000);
        await login(page, 'super_admin');
        for (const path of ['/office/dispatch', '/office/closeout-reviews', '/office/billing-handoffs', '/office/settings/organization', '/office/settings/billing', '/office/settings/invoices', '/office/operations/health', '/office/admin/archive']) {
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

        await page.goto('/office/settings/organization');
        await expect(page.getByRole('tab')).toHaveCount(0);
        await expect(page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: 'Organization' })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByLabel('Organization timezone')).toBeVisible();
        await expect(page.getByLabel('Upload full logo')).toBeVisible();
        await expectAccessible(page);

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

        await page.goto('/office/service-tickets/create');
        await page.getByLabel('Ticket title').fill('Preserved ticket draft');
        const customerSearch = page.getByRole('combobox', { name: 'Customer' });
        await customerSearch.fill(`Browser Quick Add ${Date.now()}`);
        const quickAdd = page.getByRole('button', { name: 'Add customer and location' });
        await expect(quickAdd).toBeVisible();
        await quickAdd.click();
        const quickDialog = page.getByRole('dialog', { name: 'Add customer and location' });
        await expect(quickDialog).toBeVisible();
        const quickDimensions = await quickDialog.evaluate((element) => ({ width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }));
        expect(quickDimensions.width).toBeGreaterThanOrEqual(0.9 * 1440);
        expect(quickDimensions.height).toBeLessThanOrEqual(900);
        await quickDialog.getByLabel('Address line 1').fill('100 Browser Way');
        await quickDialog.getByLabel('City').fill('Jacksboro');
        await quickDialog.getByLabel('ZIP code').fill('76458');
        await quickDialog.getByRole('button', { name: 'Save and select customer' }).click();
        await expect(quickDialog).toBeHidden();
        await expect(page.getByLabel('Ticket title')).toHaveValue('Preserved ticket draft');
        await expect(page.locator('input[name="customer_id"]')).not.toHaveValue('');
        await expect(page.getByLabel('Service location')).toBeEnabled();
        await expectAccessible(page);
    });

    test('customer workspace uses responsive cards and full-width desktop tables', async ({ page }) => {
        await login(page, 'super_admin');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 800 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);

            for (const path of ['/office/customers', '/office/locations']) {
                await page.goto(path);
                expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
                await expect(page.locator('[data-office-width="workspace"]')).toBeVisible();
                await expect(page.getByRole('navigation', { name: 'Customer workspace' })).toBeVisible();

                if (viewport.width < 1024) {
                    await expect(page.locator('[data-office-mobile-list]')).toBeVisible();
                    await expect(page.locator('[data-office-table]')).toBeHidden();
                } else {
                    await expect(page.locator('[data-office-table]')).toBeVisible();
                    await expect(page.locator('[data-office-mobile-list]')).toBeHidden();
                }

                if (viewport.width === 1920) {
                    const width = await page.locator('[data-office-width="workspace"]').evaluate((element) => element.getBoundingClientRect().width);
                    expect(width).toBeGreaterThan(0.9 * (viewport.width - 248));
                }
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/customers');
        const shortControls = await page.locator('button, input, select, a.office-workspace-tab, a.office-mobile-card, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);

        await page.setViewportSize({ width: 1920, height: 1080 });
        await page.goto('/office/locations');
        await expect(page.locator('aside [data-office-primary-customers]')).toHaveAttribute('aria-current', 'page');
        await expect(page.locator('aside nav[aria-label="Office"] a', { hasText: 'Service locations' })).toHaveCount(0);
        await expectAccessible(page);

        await page.getByRole('link', { name: 'Customers', exact: true }).last().focus();
        const focusVisible = await page.evaluate(() => {
            const style = getComputedStyle(document.activeElement);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });
        expect(focusVisible).toBeTruthy();
    });

    test('customer and location details use the responsive detail system', async ({ page }) => {
        await login(page, 'super_admin');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 800 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);

            for (const path of ['/office/customers/1', '/office/locations/1']) {
                await page.goto(path);
                await expect(page.locator('[data-office-width="detail"]')).toBeVisible();
                expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
                await expect(page.locator('h1')).toHaveCount(1);

                const columns = await page.locator('[data-office-detail-grid]').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length);
                expect(columns).toBe(viewport.width >= 1280 ? 2 : 1);

                if (viewport.width === 1920) {
                    const width = await page.locator('[data-office-width="detail"]').evaluate((element) => element.getBoundingClientRect().width);
                    expect(width).toBeGreaterThan(1500);
                    expect(width).toBeLessThanOrEqual(1600);
                }
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/customers/1');
        const detailNav = page.getByRole('navigation', { name: 'On this page' });
        await expect(detailNav.getByRole('link', { name: 'Overview' })).toHaveAttribute('href', '#overview');
        await expect(detailNav.getByRole('link', { name: 'Locations' })).toHaveAttribute('href', '#locations');
        await expect(detailNav.getByRole('link', { name: 'Contacts' })).toHaveAttribute('href', '#contacts');
        await expect(page.locator('#overview')).toHaveCount(1);
        await expect(page.locator('#locations')).toHaveCount(1);
        await expect(page.locator('#contacts')).toHaveCount(1);
        const shortControls = await page.locator('button, input, select, a.office-detail-nav-link, a.button-primary, a.button-secondary, a.office-record-back').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);

        await page.setViewportSize({ width: 1920, height: 1080 });
        await page.goto('/office/locations/1');
        await expect(page.locator('aside [data-office-primary-customers]')).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('link', { name: 'BETA Scenario A Customer' }).first()).toBeVisible();
        await expectAccessible(page);

        await page.getByRole('link', { name: 'BETA Scenario A Customer' }).first().focus();
        const focusVisible = await page.evaluate(() => {
            const style = getComputedStyle(document.activeElement);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });
        expect(focusVisible).toBeTruthy();
    });

    test('operational workspaces share responsive queues and structured invoice detail', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'super_admin');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 800 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);

            for (const path of ['/office/service-tickets', '/office/closeout-reviews', '/office/billing-handoffs']) {
                await page.goto(path);
                await expect(page.locator('[data-office-width="workspace"]')).toBeVisible();
                const overflow = await page.evaluate(() => ({ scrollWidth: document.body.scrollWidth, viewportWidth: innerWidth }));
                expect(overflow.scrollWidth, `${path} overflows at ${viewport.width}px: ${overflow.scrollWidth}px`).toBeLessThanOrEqual(overflow.viewportWidth);

                if (viewport.width < 1024) {
                    await expect(page.locator('[data-office-mobile-list]')).toBeVisible();
                    await expect(page.locator('[data-office-table]')).toBeHidden();
                } else {
                    await expect(page.locator('[data-office-table]')).toBeVisible();
                    await expect(page.locator('[data-office-mobile-list]')).toBeHidden();
                }

                if (viewport.width === 1920) {
                    const width = await page.locator('[data-office-width="workspace"]').evaluate((element) => element.getBoundingClientRect().width);
                    expect(width).toBeGreaterThan(0.9 * (viewport.width - 248));
                }
            }

            await page.goto('/office/invoices/1');
            await expect(page.locator('[data-office-width="detail"]')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            const columns = await page.locator('[data-office-detail-grid]').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length);
            expect(columns).toBe(viewport.width >= 1280 ? 2 : 1);
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/service-tickets');
        const shortControls = await page.locator('button, input, select, a.office-mobile-card, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);

        await page.goto('/office/invoices/1');
        const invoiceNav = page.getByRole('navigation', { name: 'On this page' });
        await expect(invoiceNav.getByRole('link', { name: 'Approved work' })).toHaveAttribute('href', '#approved-work');
        await expect(invoiceNav.getByRole('link', { name: 'Invoice lines' })).toHaveAttribute('href', '#invoice-lines');
        await expect(invoiceNav.getByRole('link', { name: 'Payments' })).toHaveAttribute('href', '#payments');
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
        await page.goto('/office/settings/organization');
        await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        const settingsShortControls = await page.locator('button, input, select, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(settingsShortControls).toEqual([]);
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

    test('quick customer dialog fills the phone viewport and protects unsaved work', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office/service-tickets/create');
        await page.getByRole('combobox', { name: 'Customer' }).fill(`Missing Mobile Customer ${Date.now()}`);
        const launcher = page.getByRole('button', { name: 'Add customer and location' });
        await expect(launcher).toBeVisible();
        await launcher.click();
        const dialog = page.getByRole('dialog', { name: 'Add customer and location' });
        await expect(dialog).toBeVisible();
        const dimensions = await dialog.evaluate((element) => ({
            width: element.getBoundingClientRect().width,
            height: element.getBoundingClientRect().height,
            viewportWidth: innerWidth,
            viewportHeight: innerHeight,
        }));
        expect(dimensions.width).toBe(dimensions.viewportWidth);
        expect(dimensions.height).toBe(dimensions.viewportHeight);
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        page.once('dialog', (confirmation) => confirmation.accept());
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
        await expect(launcher).toBeFocused();
    });
});
