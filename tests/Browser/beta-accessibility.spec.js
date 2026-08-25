import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const password = process.env.BETA_DEMO_PASSWORD;
const dashboardReviewDir = process.env.DASHBOARD_REVIEW_DIR;
const projectsReviewDir = process.env.PROJECTS_REVIEW_DIR;
const fieldTestReviewDir = process.env.FIELD_TEST_REVIEW_DIR;
const printDocumentReviewDir = process.env.PRINT_DOCUMENT_REVIEW_DIR;

async function captureDashboard(page, filename) {
    if (dashboardReviewDir) {
        await page.screenshot({ path: `${dashboardReviewDir}/${filename}`, fullPage: true });
    }
}

async function captureProjects(page, filename) {
    if (projectsReviewDir) {
        await page.screenshot({ path: `${projectsReviewDir}/${filename}`, fullPage: true });
    }
}

async function captureFieldTest(page, filename) {
    if (fieldTestReviewDir) {
        await page.screenshot({ path: `${fieldTestReviewDir}/${filename}`, fullPage: true });
    }
}

async function capturePrintDocument(page, filename) {
    if (printDocumentReviewDir) {
        await page.screenshot({ path: `${printDocumentReviewDir}/${filename}`, fullPage: true });
    }
}

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

test.describe('Printable operational documents', () => {
    test('Work Order and Project Workbook are accessible print-oriented private previews', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'One browser project loops through all required widths.');
        test.setTimeout(120_000);
        await login(page, 'super_admin');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office/projects');
            await page.locator('a:visible').filter({ hasText: /^Trip Hopper — IT Support/ }).first().click();
            await page.getByRole('link', { name: 'Print Project Workbook' }).click();
            await expect(page.getByText('PROJECT WORKBOOK', { exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Print' })).toBeVisible();
            await expect(page.locator('nav[aria-label="Office"]')).toHaveCount(0);
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            await capturePrintDocument(page, `project-workbook-${viewport.width}x${viewport.height}.png`);
            await page.emulateMedia({ media: 'print' });
            await expect(page.locator('.print-toolbar')).toBeHidden();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
            await page.emulateMedia({ media: 'screen' });

            for (const profile of [
                ['Technician Work Order', 'TECHNICIAN WORK ORDER', 'technician-work-order'],
                ['Completion Summary', 'COMPLETION SUMMARY', 'completion-summary'],
                ['Customer Service Record', 'CUSTOMER SERVICE RECORD', 'customer-service-record'],
                ['Detailed Service Report', 'DETAILED SERVICE REPORT', 'detailed-service-report'],
            ]) {
                await page.goto('/office/service-tickets');
                await page.getByRole('link', { name: /^NDT-ST-/ }).first().click();
                await page.getByText('Documents', { exact: true }).click();
                await page.getByRole('link', { name: new RegExp(`^${profile[0]}`) }).click();
                await expect(page.getByText(profile[1], { exact: true })).toBeVisible();
                await expect(page.getByRole('button', { name: 'Print / Save as PDF' })).toBeVisible();
                await expect(page.locator('nav[aria-label="Office"]')).toHaveCount(0);
                expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
                await expectAccessible(page);
                await capturePrintDocument(page, `${profile[2]}-${viewport.width}x${viewport.height}.png`);
                await page.emulateMedia({ media: 'print' });
                await expect(page.locator('.print-toolbar')).toBeHidden();
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
                await page.emulateMedia({ media: 'screen' });
            }
        }
    });
});

test.describe('Projects V1', () => {
    test('workspace and detail remain responsive and accessible', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page, 'super_admin');
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office/projects');
            await expect(page.getByRole('heading', { name: 'Projects / Engagements' })).toBeVisible();
            await expect(page.locator('a:visible').filter({ hasText: /^Trip Hopper — IT Support/ }).first()).toBeVisible();
            const overflowing = await page.locator('body *').evaluateAll((elements) => elements
                .filter((element) => element.scrollWidth > element.clientWidth + 1)
                .filter((element) => getComputedStyle(element).overflowX === 'visible')
                .map((element) => `${element.tagName.toLowerCase()}#${element.id}.${element.className}`));
            expect(overflowing).toEqual([]);
            await expectAccessible(page);
            await captureProjects(page, `workspace-${viewport.width}x${viewport.height}.png`);

            await page.locator('a:visible').filter({ hasText: /^Trip Hopper — IT Support/ }).first().click();
            await expect(page.getByRole('heading', { name: 'Trip Hopper — IT Support' })).toBeVisible();
            for (const section of ['Overview', 'Workstreams', 'Tasks', 'Milestones', 'Related Service Tickets', 'Files & Photos', 'Notes / Activity']) {
                await expect(page.getByRole('link', { name: section })).toBeVisible();
            }
            const projectFileInput = page.getByLabel('Choose photo or file');
            await expect(projectFileInput).toBeVisible();
            expect(await projectFileInput.getAttribute('capture')).toBeNull();
            expect(await projectFileInput.getAttribute('accept')).toContain('.heic');
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            const bodyPadding = await page.locator('.office-detail-form-body').evaluateAll((forms) => forms.map((form) => Number.parseFloat(getComputedStyle(form).paddingLeft)));
            expect(bodyPadding.length).toBeGreaterThan(0);
            expect(bodyPadding.every((padding) => padding === 20)).toBeTruthy();
            await page.getByText('Edit Workstream', { exact: true }).first().click();
            const insetTreatment = await page.locator('form.office-detail-form-inset:visible').first().evaluate((form) => ({
                padding: Number.parseFloat(getComputedStyle(form).paddingLeft),
                border: Number.parseFloat(getComputedStyle(form).borderLeftWidth),
                background: getComputedStyle(form).backgroundColor,
            }));
            expect(insetTreatment.padding).toBeGreaterThanOrEqual(16);
            expect(insetTreatment.border).toBe(1);
            expect(insetTreatment.background).not.toBe('rgba(0, 0, 0, 0)');
            const shortControls = await page.locator('form:visible input, form:visible select, form:visible textarea, form:visible button').evaluateAll((elements) => elements
                .filter((element) => element.offsetParent !== null)
                .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
                .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
            expect(shortControls).toEqual([]);
            await expectAccessible(page);
            await captureProjects(page, `detail-${viewport.width}x${viewport.height}.png`);
        }
    });

    test('restricted viewer sees no Projects management controls', async ({ page }) => {
        await login(page, 'reviewer');
        await page.goto('/office/projects');
        await page.locator('a:visible').filter({ hasText: /^Trip Hopper — IT Support/ }).first().click();
        await expect(page.getByText('Edit Project')).toHaveCount(0);
        await expect(page.getByText('Add Task')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Link Ticket' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Create Service Ticket' })).toHaveCount(0);
        await expect(page.getByLabel('Choose photo or file')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Files & Photos' })).toBeVisible();
        await expectAccessible(page);
    });

    test('Project Service Ticket creation keeps fixed context across responsive widths', async ({ page }) => {
        await login(page, 'super_admin');
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office/projects');
            await page.locator('a:visible').filter({ hasText: /^Trip Hopper — IT Support/ }).first().click();
            await page.getByRole('link', { name: 'Create Service Ticket' }).click();
            await expect(page.getByRole('heading', { name: 'Create Service Ticket' })).toBeVisible();
            await expect(page.getByText(/Project context/i)).toBeVisible();
            await expect(page.locator('input[name="customer_id"]')).toHaveCount(0);
            await expect(page.getByLabel('Service location')).toBeVisible();
            await expect(page.getByRole('button', { name: 'Create and link Service Ticket' })).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            await captureProjects(page, `project-ticket-create-${viewport.width}x${viewport.height}.png`);
        }
    });
});

test.describe('desktop beta', () => {
    test.skip(({ isMobile }) => isMobile);

    test('Service Ticket Work Items remain responsive and accessible across Office and Review', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'super_admin');
        await page.goto('/office');
        await page.getByRole('link', { name: /BETA Camera C-14 remains offline/ }).click();
        const ticketUrl = page.url();

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto(ticketUrl);
            await expect(page.getByRole('heading', { name: 'Work Items' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Work Time Attribution' })).toBeVisible();
            await expect(page.getByText('BETA Camera C-14 remains offline', { exact: true })).toBeVisible();
            await expect(page.getByText('Create follow-up Service Ticket', { exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
        }

        await page.goto('/office/closeout-reviews');
        const reviewLinks = await page.locator('a[href*="/office/closeout-reviews/"]:visible').evaluateAll((links) => links.map((link) => link.href));
        let workItemReview = null;
        for (const reviewLink of reviewLinks) {
            await page.goto(reviewLink);
            if (await page.getByText('BETA Camera C-14 remains offline', { exact: true }).count()) {
                workItemReview = reviewLink;
                break;
            }
        }
        expect(workItemReview).not.toBeNull();
        await expect(page.getByRole('heading', { name: 'Work Items handled this Visit' })).toBeVisible();
        await expect(page.getByText('Allocation explains what recorded time was spent on.')).toBeVisible();
        await expect(page.getByText('Approval will not close the Service Ticket yet.')).toBeVisible();
        await expectAccessible(page);
    });

    test('Super Admin submitted-time correction remains responsive and accessible', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'super_admin');
        await page.goto('/office/closeout-reviews');
        const reviewLinks = await page.locator('a[href*="/office/closeout-reviews/"]:visible').evaluateAll((links) => links.map((link) => link.href));
        let correctableReview = null;
        for (const reviewLink of reviewLinks) {
            await page.goto(reviewLink);
            if (await page.getByText('Correct submitted time', { exact: true }).count()) {
                correctableReview = reviewLink;
                break;
            }
        }
        expect(correctableReview).not.toBeNull();

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto(correctableReview);
            const correction = page.getByText('Correct submitted time', { exact: true });
            await expect(correction).toBeVisible();
            await correction.click();
            await expect(page.getByLabel(/Why was the recorded clock interval wrong/)).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
        }
    });

    test('closeout review photo gallery is responsive and keyboard operable', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'reviewer');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office/closeout-reviews');
            await page.locator('a[href*="/office/closeout-reviews/"]:visible').first().click();

            const gallery = page.locator('[data-review-photo-gallery]');
            await expect(gallery).toBeVisible();
            const thumbnails = gallery.locator('[data-review-photo-open]');
            expect(await thumbnails.count()).toBeGreaterThan(1);
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();

            await thumbnails.first().click();
            const dialog = page.getByRole('dialog', { name: 'Photo evidence' });
            await expect(dialog).toBeVisible();
            await expect(dialog.locator('[data-review-photo-position]')).toHaveText(/1 of \d+/);
            await expect(dialog.locator('[data-review-photo-image]')).toHaveAttribute('src', /\/field-media\/\d+/);
            await dialog.getByRole('button', { name: /Next/ }).click();
            await expect(dialog.locator('[data-review-photo-position]')).toHaveText(/2 of \d+/);
            await page.keyboard.press('ArrowLeft');
            await expect(dialog.locator('[data-review-photo-position]')).toHaveText(/1 of \d+/);
            await page.keyboard.press('ArrowRight');
            await expect(dialog.locator('[data-review-photo-position]')).toHaveText(/2 of \d+/);
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(thumbnails.first()).toBeFocused();

            await thumbnails.first().click();
            await dialog.getByRole('button', { name: 'Close photo viewer' }).click();
            await expect(dialog).toBeHidden();
            await expect(page.getByRole('button', { name: /Approve closeout/ })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Return to field' })).toBeVisible();
            await expectAccessible(page);
        }
    });

    test('Ticket files upload, render, and remove in the desktop workspace', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office/service-tickets?search=NDT-ST-2026-9001');
        await page.getByRole('link', { name: /BETA A:/ }).click();
        const section = page.locator('[data-ticket-files]');
        const filename = `desktop-ticket-reference-${Date.now()}.png`;
        await section.getByLabel('Ticket file').setInputFiles({
            name: filename,
            mimeType: 'image/png',
            buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
        });
        await section.getByLabel(/Caption/).fill('Desktop Ticket reference');
        await section.getByRole('button', { name: 'Upload Ticket file' }).click();
        await expect(page.locator('[data-ticket-files]').getByText(filename)).toBeVisible();
        await expectAccessible(page);
        await captureFieldTest(page, 'ticket-files-1440x900.png');
        const fileCard = page.locator('[data-ticket-files] article').filter({ hasText: filename });
        await expect(fileCard.getByRole('link', { name: filename })).toBeVisible();
        await fileCard.getByRole('button', { name: 'Remove' }).click();
        await expect(page.locator('[data-ticket-files]').getByText(filename)).toHaveCount(0);
    });

    test('service ticket navigation and open filter retain the directory', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office');
        await page.getByRole('link', { name: 'Service Tickets', exact: true }).first().click();
        await expect(page).toHaveURL(/\/office\/service-tickets$/);
        await expect(page.getByRole('heading', { name: 'Service Tickets', exact: true })).toBeVisible();
        await captureFieldTest(page, 'office-ticket-directory-1440x900.png');

        await page.goto('/office/service-tickets?status=open');
        await expect(page.getByLabel('Status')).toHaveValue('open');
        await expect(page.getByText('No service tickets found.')).toHaveCount(0);
        await expectAccessible(page);
        await captureFieldTest(page, 'office-open-tickets-1440x900.png');
    });

    test('NewDay Home is responsive, bounded, and accessible', async ({ page }) => {
        await login(page, 'super_admin');
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office');
            await expect(page.getByRole('heading', { name: 'NewDay Home' })).toBeVisible();
            await expect(page.locator('[data-home-directory-search]')).toBeVisible();
            await expect(page.locator('[data-home-apps]')).toBeVisible();
            await expect(page.locator('[data-home-attention-feed]')).toBeVisible();
            await expect(page.locator('[data-home-projects]')).toBeVisible();
            await expect(page.locator('[data-dashboard-attention]')).toBeVisible();
            await expect(page.locator('[data-dashboard-today]')).toBeVisible();
            await expect(page.locator('[data-dashboard-billing]')).toBeVisible();
            await expect(page.locator('[data-dashboard-follow-up]')).toBeVisible();
            await expect(page.locator('[data-dashboard-health]')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            await captureDashboard(page, `super-admin-${viewport.width}x${viewport.height}.png`);
        }
    });

    test('NewDay Home directory search is keyboard usable and grouped', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office');
        const search = page.getByLabel('Search Customers, Contacts, and Service Locations');
        await search.focus();
        await search.fill('BETA');
        await search.press('Enter');
        await expect(page).toHaveURL(/\/office\/search\?q=BETA/);
        await expect(page.getByRole('heading', { name: 'Customer Directory Search' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Customers' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Contacts' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Service Locations' })).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
    });

    test('dispatch, review, billing, and health remain keyboard accessible', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page, 'super_admin');
        for (const path of ['/office/dispatch', '/office/closeout-reviews', '/office/billing-handoffs', '/office/invoices', '/office/subscriptions', '/office/settings/organization', '/office/settings/billing', '/office/settings/invoices', '/office/operations/health', '/office/admin/archive']) {
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

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/office/invoices?status=issued');
        await page.getByRole('link', { name: /NDT-INV-/ }).first().click();
        await expect(page.getByRole('heading', { name: /NDT-INV-/ })).toBeVisible();
        await expectAccessible(page);
        await page.getByRole('link', { name: 'Customer view' }).click();
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
        await expect(manualDialog.getByLabel('Take photo')).toHaveAttribute('capture', 'environment');
        await expect(manualDialog.getByLabel('Choose from gallery or files')).not.toHaveAttribute('capture');
        await captureFieldTest(page, 'office-manual-closeout-photos-1440x900.png');
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

    test('dispatch date strip and calendar adapt without horizontal overflow', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page, 'super_admin');

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 900 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/office/dispatch');
            await expect(page.locator('[data-dispatch-date-strip] > a')).toHaveCount(5);
            await expect(page.getByRole('link', { name: 'Previous day' })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Next day' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Dispatch calendar' })).toBeVisible();

            if (viewport.width >= 1024) {
                await expect(page.locator('[data-dispatch-calendar-grid]')).toBeVisible();
                await expect(page.locator('[data-dispatch-calendar-agenda]')).toBeHidden();
            } else {
                await expect(page.locator('[data-dispatch-calendar-grid]')).toBeHidden();
                await expect(page.locator('[data-dispatch-calendar-agenda]')).toBeVisible();
            }

            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
        }
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

                if (path === '/office/customers/1') {
                    await expect(page.locator('[data-customer-projects]')).toBeVisible();
                    await expect(page.getByRole('link', { name: 'New Project' })).toBeVisible();
                }
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/customers/1');
        const detailNav = page.getByRole('navigation', { name: 'On this page' });
        await expect(detailNav.getByRole('link', { name: 'Overview' })).toHaveAttribute('href', '#overview');
        await expect(detailNav.getByRole('link', { name: 'Projects' })).toHaveAttribute('href', '#projects');
        await expect(detailNav.getByRole('link', { name: 'Locations' })).toHaveAttribute('href', '#locations');
        await expect(detailNav.getByRole('link', { name: 'Contacts' })).toHaveAttribute('href', '#contacts');
        await expect(page.locator('#overview')).toHaveCount(1);
        await expect(page.locator('#projects')).toHaveCount(1);
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

            for (const path of ['/office/service-tickets', '/office/closeout-reviews', '/office/billing-handoffs', '/office/invoices']) {
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
            await expect(page.locator('[data-office-width="workspace"]')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expect(page.locator('[data-invoice-command-bar]')).toBeVisible();
            const columns = await page.locator('[data-invoice-workspace]').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length);
            expect(columns).toBe(1);
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/service-tickets');
        const shortControls = await page.locator('button, input, select, a.office-mobile-card, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/office/billing-handoffs');
        await expect(page).toHaveURL(/\/office\/invoices\?workspace=ready_to_invoice/);
        await expect(page.getByRole('navigation', { name: 'Billing and invoice status' }).getByRole('link', { name: /Ready to Invoice/ })).toHaveAttribute('aria-current', 'page');
        await page.getByRole('navigation', { name: 'Billing and invoice status' }).getByRole('link', { name: 'All' }).click();
        await expect(page.getByRole('heading', { name: 'Billing / Invoices' })).toBeVisible();
        await expect(page.locator('[data-office-table]')).toBeVisible();
        await expect(page.locator('[data-office-mobile-list]')).toBeHidden();
        await page.getByLabel('Invoice number').fill('NDT-INV-2026-0001');
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect(page.getByRole('link', { name: 'NDT-INV-2026-0001' }).first()).toBeVisible();
        await expectAccessible(page);
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.locator('[data-office-table]')).toBeHidden();
        await expect(page.locator('[data-office-mobile-list]')).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/office/invoices?status=draft');
        await page.getByRole('link', { name: /NDT-INV-/ }).first().click();
        await expect(page.getByRole('region', { name: 'Invoice actions' })).toBeVisible();
        await expect(page.locator('[data-invoice-item-workspace]')).toBeVisible();
        await expect(page.locator('[data-invoice-item-table]')).toBeVisible();
        await expect(page.locator('[data-invoice-item-cards]')).toBeHidden();
        const firstItemEditorLauncher = page.locator('[data-invoice-item-table] [data-invoice-item-open^="invoice-line-editor-"]').first();
        await firstItemEditorLauncher.click();
        const itemEditor = page.getByRole('dialog', { name: 'Edit invoice item' });
        await expect(itemEditor).toBeVisible();
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(itemEditor).toBeHidden();
        await expect(firstItemEditorLauncher).toBeFocused();
        await page.getByRole('button', { name: '+ Add Manual Line' }).click();
        const manualLineDialog = page.getByRole('dialog', { name: 'Add manual line' });
        await expect(manualLineDialog).toBeVisible();
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(manualLineDialog).toBeHidden();
        const billingLauncher = page.locator('[data-invoice-command-bar]').getByRole('button', { name: 'Billing details' });
        await billingLauncher.click();
        const billingDialog = page.getByRole('dialog', { name: 'Edit billing details' });
        await expect(billingDialog).toBeVisible();
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(billingDialog).toBeHidden();
        await expect(billingLauncher).toBeFocused();

        await page.goto('/office/invoices/1');
        const recordPaymentLauncher = page.locator('[data-invoice-command-bar]').getByRole('button', { name: 'Record payment' });
        await recordPaymentLauncher.click();
        const recordPaymentDialog = page.getByRole('dialog', { name: 'Record payment' });
        await expect(recordPaymentDialog).toBeVisible();
        await expect(recordPaymentDialog.getByText('Balance due')).toBeVisible();
        await expect(recordPaymentDialog.getByRole('radio', { name: 'Cash', exact: true })).toBeVisible();
        await expect(recordPaymentDialog.getByRole('radio', { name: 'Check', exact: true })).toBeVisible();
        await expect(recordPaymentDialog.getByRole('radio', { name: 'Credit Card — Square POS', exact: true })).toBeVisible();
        await expect(recordPaymentDialog.getByRole('radio', { name: 'Debit Card — Square POS', exact: true })).toBeVisible();
        await recordPaymentDialog.getByRole('radio', { name: 'Credit Card — Square POS', exact: true }).check();
        await expect(recordPaymentDialog.getByText('Square POS payment already completed')).toBeVisible();
        await expect(recordPaymentDialog.getByText(/does not charge the card or verify/)).toBeVisible();
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 800 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            const choices = recordPaymentDialog.locator('.payment-method-choice');
            await expect(choices).toHaveCount(4);
            for (const choice of await choices.all()) {
                expect((await choice.boundingBox())?.height).toBeGreaterThanOrEqual(44);
            }
            expect(await recordPaymentDialog.evaluate((dialog) => dialog.scrollWidth <= dialog.clientWidth)).toBeTruthy();
        }
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(recordPaymentDialog).toBeHidden();
        await expect(recordPaymentLauncher).toBeFocused();

        const securePaymentLauncher = page.locator('[data-invoice-command-bar]').getByRole('button', { name: 'Pay securely' });
        await securePaymentLauncher.click();
        const securePaymentDialog = page.getByRole('dialog', { name: 'Pay securely' });
        await expect(securePaymentDialog).toBeVisible();
        await expect(securePaymentDialog.getByText(/Connected/).first()).toBeVisible();
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(securePaymentDialog).toBeHidden();
        await expect(securePaymentLauncher).toBeFocused();

        const paymentHistoryLauncher = page.locator('[data-invoice-command-bar]').getByRole('button', { name: /Open payment history/ });
        await paymentHistoryLauncher.click();
        const paymentHistoryDialog = page.getByRole('dialog', { name: 'Payment history' });
        await expect(paymentHistoryDialog).toBeVisible();
        await expect(paymentHistoryDialog.getByText('Current balance')).toBeVisible();
        await expectAccessible(page);
        await page.keyboard.press('Escape');
        await expect(paymentHistoryDialog).toBeHidden();
        await expect(paymentHistoryLauncher).toBeFocused();

        await page.goBack();
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.locator('[data-invoice-item-table]')).toBeHidden();
        await expect(page.locator('[data-invoice-item-cards]')).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await page.locator('[data-invoice-item-cards] [data-invoice-item-open]').first().click();
        await expect(itemEditor).toBeVisible();
        const itemEditorDimensions = await itemEditor.evaluate((element) => ({
            width: element.getBoundingClientRect().width,
            height: element.getBoundingClientRect().height,
            viewportWidth: innerWidth,
            viewportHeight: innerHeight,
        }));
        expect(itemEditorDimensions.width).toBe(itemEditorDimensions.viewportWidth);
        expect(itemEditorDimensions.height).toBe(itemEditorDimensions.viewportHeight);
        await expectAccessible(page);
        await page.keyboard.press('Escape');
    });

    test('catalog services, products, packages, recipes, categories, and units follow the responsive workspace system', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page, 'super_admin');
        const suffix = Date.now();

        await page.goto('/office/catalog/services/create');
        await page.getByLabel('Service code').fill(`TV-MOUNT-${suffix}`);
        await page.getByLabel('Service name').fill('TV Mounting Browser Test');
        await page.getByLabel('Sales unit').selectOption({ label: 'Each (ea)' });
        await page.getByLabel('Pricing model').selectOption('variant');
        await page.getByLabel('Default price').fill('299.00');
        await page.getByRole('button', { name: 'Create service' }).click();
        await expect(page.getByRole('heading', { name: 'TV Mounting Browser Test' })).toBeVisible();

        await page.getByLabel('New variant code').fill('TV-56-75');
        await page.getByLabel('New variant label').fill('56–75 inch');
        await page.getByLabel('New variant customer label').fill('TV mounting for 56–75 inch displays');
        await page.getByLabel('New variant price override').fill('399.00');
        await page.getByRole('button', { name: 'Add variant' }).click();
        await expect(page.getByRole('heading', { name: '56–75 inch' })).toBeVisible();
        await expectAccessible(page);

        await page.goto('/office/catalog/products/create');
        await page.getByLabel('Product code').fill(`CAT6-BLUE-${suffix}`);
        await page.getByLabel('Product name').fill('Blue Cat6 Browser Test');
        await page.getByLabel('Base consumption unit').selectOption({ label: 'Foot (ft)' });
        await page.getByLabel('Default sales unit').selectOption({ label: 'Foot (ft)' });
        await page.getByLabel('Base units per sales unit').fill('1');
        await page.getByLabel('Future inventory classification').selectOption('lot_or_roll');
        await page.getByLabel('Default cost').fill('187.49');
        await page.getByLabel('Cost covers base-unit quantity').fill('500');
        await page.getByLabel('Default sell price').fill('0.95');
        await page.getByRole('button', { name: 'Create product' }).click();
        await expect(page.getByRole('heading', { name: 'Blue Cat6 Browser Test' })).toBeVisible();
        await page.getByLabel('Purchase unit', { exact: true }).selectOption({ label: 'Box' });
        await page.getByLabel('Label', { exact: true }).fill('250 ft box');
        await page.getByLabel('Base-unit quantity', { exact: true }).fill('250');
        await page.getByRole('button', { name: 'Add purchase unit' }).click();
        await expect(page.getByRole('heading', { name: '250 ft box' })).toBeVisible();
        await expect(page.getByText('1 Box = 250 Feet')).toBeVisible();
        await expectAccessible(page);

        await page.goto('/office/catalog/packages/create');
        await page.getByLabel('Package code').fill(`ISH-TV-${suffix}`);
        await page.getByLabel('Package name').fill('Integrated Smart Home TV Rough-In Browser Test');
        await page.getByLabel('Sales unit').selectOption({ label: 'Location' });
        await page.getByLabel('Default package price').fill('2499.00');
        await page.getByRole('button', { name: 'Create package' }).click();
        await expect(page.getByRole('heading', { name: 'Integrated Smart Home TV Rough-In Browser Test' })).toBeVisible();
        const addProduct = page.getByRole('heading', { name: 'Add Product' }).locator('..');
        await addProduct.getByLabel('Product', { exact: true }).selectOption({ label: `Blue Cat6 Browser Test (CAT6-BLUE-${suffix}) · Foot` });
        await addProduct.getByLabel('Direct standard quantity', { exact: true }).fill('350');
        await addProduct.getByRole('button', { name: 'Add Product' }).click();
        await expect(page.getByRole('heading', { name: 'Blue Cat6 Browser Test' })).toBeVisible();
        await page.getByLabel('Package quantity').fill('5');
        await page.getByRole('button', { name: 'Calculate' }).click();
        await expect(page.getByText('5 × Integrated Smart Home TV Rough-In Browser Test')).toBeVisible();
        await expect(page.getByText('1750 Feet').first()).toBeVisible();
        await expectAccessible(page);

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/field/visits/2');
        const catalogLauncher = page.getByRole('button', { name: 'Add Catalog item' });
        await expect(catalogLauncher).toBeVisible();
        await catalogLauncher.focus();
        await catalogLauncher.click();
        const catalogDialog = page.getByRole('dialog', { name: 'Add Catalog item' });
        await expect(catalogDialog).toBeVisible();
        const catalogDimensions = await catalogDialog.evaluate((element) => ({
            width: element.getBoundingClientRect().width,
            height: element.getBoundingClientRect().height,
            viewportWidth: innerWidth,
            viewportHeight: innerHeight,
        }));
        expect(catalogDimensions.width).toBe(catalogDimensions.viewportWidth);
        expect(catalogDimensions.height).toBe(catalogDimensions.viewportHeight);
        await catalogDialog.getByLabel('Search Catalog').fill(`CAT6-BLUE-${suffix}`);
        const matchingCatalogOption = catalogDialog.getByLabel('Catalog item').locator('option', { hasText: `CAT6-BLUE-${suffix}` });
        await expect(matchingCatalogOption).toHaveCount(1);
        await expect(matchingCatalogOption).not.toHaveAttribute('hidden', '');
        await expect(catalogDialog.getByText('$0.95')).toHaveCount(0);
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        page.once('dialog', (confirmation) => confirmation.accept());
        await catalogDialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(catalogDialog).toBeHidden();
        await expect(catalogLauncher).toBeFocused();

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            for (const path of ['/office/catalog/services', '/office/catalog/products', '/office/catalog/packages', '/office/catalog/categories', '/office/catalog/units']) {
                await page.goto(path);
                await expect(page.locator('[data-office-width="workspace"]')).toBeVisible();
                expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
                if (viewport.width < 1024) {
                    await expect(page.locator('.office-mobile-list')).toBeVisible();
                    await expect(page.locator('.office-table-wrap')).toBeHidden();
                } else {
                    await expect(page.locator('.office-table-wrap')).toBeVisible();
                    await expect(page.locator('.office-mobile-list')).toBeHidden();
                }
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/office/catalog/services');
        const shortControls = await page.locator('button, input, select, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);
    });
});

test.describe('field Work Items', () => {
    test('assigned technician sees additional Work Item context without overflow', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'One browser project loops through all required widths.');
        test.setTimeout(120_000);
        await login(page, 'technician');
        await page.getByRole('link', { name: /BETA A: Resolved with photo and acknowledgment/ }).click();
        const visitUrl = page.url();

        for (const viewport of [
            { width: 390, height: 844 },
            { width: 768, height: 1024 },
            { width: 1280, height: 900 },
            { width: 1440, height: 900 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto(visitUrl);
            await expect(page.getByRole('heading', { name: 'Primary scope' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Work Items' })).toBeVisible();
            await expect(page.getByRole('combobox', { name: 'Work focus' })).toBeVisible();
            await expect(page.getByText('BETA AP-07 restored', { exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
        }
    });
});

test.describe('mobile beta', () => {
    test.skip(({ isMobile }) => !isMobile);

    test('Ticket files remain usable at phone width', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office/service-tickets?search=NDT-ST-2026-9001');
        await page.getByRole('link', { name: /BETA A:/ }).click();
        const section = page.locator('[data-ticket-files]');
        const filename = `mobile-ticket-reference-${Date.now()}.png`;
        await section.getByLabel('Ticket file').setInputFiles({
            name: filename,
            mimeType: 'image/png',
            buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
        });
        await section.getByLabel(/Caption/).fill('Mobile Ticket reference');
        await section.getByRole('button', { name: 'Upload Ticket file' }).click();
        await expect(page.locator('[data-ticket-files]').getByText(filename)).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        const shortControls = await section.locator('button, input, textarea, a').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);
        await captureFieldTest(page, 'ticket-files-390x844.png');
        const fileCard = page.locator('[data-ticket-files] article').filter({ hasText: filename });
        await fileCard.getByRole('button', { name: 'Remove' }).click();
        await expect(page.locator('[data-ticket-files]').getByText(filename)).toHaveCount(0);
    });

    test('Office manual closeout photos remain usable at phone width', async ({ page }) => {
        await login(page, 'super_admin');
        await page.goto('/office/service-tickets?search=NDT-ST-2026-9001');
        await page.getByRole('link', { name: /BETA A:/ }).click();
        const manualStart = page.getByRole('button', { name: 'Start manual closeout' });
        if (await manualStart.count()) {
            await manualStart.click();
        } else {
            await page.getByRole('button', { name: 'Manual closeout' }).click();
        }
        const dialog = page.getByRole('dialog', { name: 'Administrative closeout' });
        await expect(dialog).toBeVisible();
        const dimensions = await dialog.evaluate((element) => ({
            width: element.getBoundingClientRect().width,
            height: element.getBoundingClientRect().height,
            viewportWidth: innerWidth,
            viewportHeight: innerHeight,
        }));
        expect(dimensions.width).toBe(dimensions.viewportWidth);
        expect(dimensions.height).toBe(dimensions.viewportHeight);
        await expect(dialog.getByLabel('Take photo')).toHaveAttribute('capture', 'environment');
        await expect(dialog.getByLabel('Choose from gallery or files')).not.toHaveAttribute('capture');
        expect(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBeTruthy();
        await expectAccessible(page);
        await captureFieldTest(page, 'office-manual-closeout-photos-390x844.png');
    });

    test('restricted office dashboard hides financial and health data at phone width', async ({ page }) => {
        await login(page, 'dispatcher');
        await page.goto('/office');
        await expect(page.getByRole('heading', { name: 'NewDay Home' })).toBeVisible();
        await expect(page.locator('[data-dashboard-today]')).toBeVisible();
        await expect(page.locator('[data-dashboard-follow-up]')).toBeVisible();
        await expect(page.locator('[data-dashboard-billing]')).toHaveCount(0);
        await expect(page.locator('[data-dashboard-health]')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'New Service Ticket' })).toBeVisible();
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        const shortControls = await page.locator('button, input, select, textarea, a').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => element.getBoundingClientRect().height < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}.${element.className}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await expectAccessible(page);
        await captureDashboard(page, 'dispatcher-390x844.png');
    });

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
        await expect(page.locator('[data-connectivity-label]')).toHaveText('Online');
        const workspaceNavigation = page.getByRole('navigation', { name: 'Visit workspace sections' });
        await expect(workspaceNavigation.getByRole('link', { name: 'Time' })).toBeVisible();
        await expect(workspaceNavigation.getByRole('link', { name: 'Notes & outcome' })).toBeVisible();
        await expect(workspaceNavigation.getByRole('link', { name: 'Photos' })).toBeVisible();
        await expect(workspaceNavigation.getByRole('link', { name: 'Parts' })).toBeVisible();
        await expect(page.locator('[data-closeout-action-footer]')).toHaveCount(0);
        await page.getByRole('button', { name: 'Start En Route' }).click();
        await page.getByRole('button', { name: 'Mark On Site' }).click();
        await expect(page.locator('[data-closeout-action-footer]')).toBeVisible();
        expect(await page.locator('[data-closeout-action-footer]').evaluate((element) => element.getBoundingClientRect().height)).toBeLessThanOrEqual(80);
        await page.getByText('Needs return trip', { exact: true }).click();
        await expect(page.locator('[data-selected-outcome]')).toHaveText('Needs return trip');
        await expect(page.locator('[data-selected-outcome]')).toHaveAttribute('data-outcome', 'needs_return_trip');
        await page.getByLabel('Diagnosis').fill('Additional work is required.');
        await page.getByLabel('Work performed').fill('Made the system safe for a return visit.');
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.locator('[data-save-feedback]')).toContainText('Saved successfully');
        await expect(page.locator('#return_reason')).toHaveAttribute('aria-invalid', 'true');
        await page.locator('[data-closeout-dialog-open]').click();
        const closeoutDialog = page.getByRole('dialog', { name: 'Review closeout' });
        await expect(closeoutDialog.locator('[data-closeout-fix-target="return_reason"]')).toBeVisible();
        await captureFieldTest(page, 'field-closeout-required-fields-390x844.png');
        await closeoutDialog.locator('[data-closeout-fix-target="return_reason"]').click();
        await expect(page.locator('#return_reason')).toBeFocused();
        await page.getByLabel('Return reason').fill('A replacement component is required.');
        await page.getByLabel('Unfinished work').fill('Install and validate the replacement.');
        await page.getByLabel('Needed parts / equipment').fill('Replacement component and test equipment.');
        await page.getByLabel('Recommendations').fill('Schedule the return after the component arrives.');
        await page.getByLabel('POC or customer name').fill('Beta Customer');
        await page.getByLabel('POC role or title').fill('Site manager');
        await page.locator('#no_photo_category').selectOption('not_applicable');
        await page.locator('#no_photo_detail').fill('No visual evidence applies to the diagnostic work.');
        await page.getByRole('button', { name: 'Save draft' }).click();
        await page.locator('[data-closeout-dialog-open]').click();
        await expect(closeoutDialog.locator('[data-signature-canvas]')).toBeVisible();
        await expect(closeoutDialog.getByRole('button', { name: 'Clear signature' })).toBeVisible();
        const signatureBox = await closeoutDialog.locator('[data-signature-canvas]').boundingBox();
        await page.mouse.move(signatureBox.x + 30, signatureBox.y + 70);
        await page.mouse.down();
        await page.mouse.move(signatureBox.x + 130, signatureBox.y + 35, { steps: 8 });
        await page.mouse.move(signatureBox.x + 230, signatureBox.y + 90, { steps: 8 });
        await page.mouse.up();
        await expect(closeoutDialog.locator('[data-signature-status]')).toContainText('Signature captured');
        await captureFieldTest(page, 'field-acknowledgment-signature-390x844.png');
        await closeoutDialog.getByRole('button', { name: 'Continue editing' }).click();
        await workspaceNavigation.getByRole('link', { name: 'Photos' }).click();
        await expect(page.getByRole('heading', { name: 'Private photos' })).toBeVisible();
        const cameraPhoto = page.getByLabel('Take photo');
        const libraryPhoto = page.getByLabel('Choose from gallery or files');
        await expect(cameraPhoto).toHaveAttribute('capture', 'environment');
        await expect(libraryPhoto).not.toHaveAttribute('capture');
        await cameraPhoto.setInputFiles({ name: 'camera-photo.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('camera-photo') });
        await expect(page.locator('[data-upload-selection]')).toContainText('Camera: camera-photo.jpg');
        await libraryPhoto.setInputFiles({ name: 'saved-photo.png', mimeType: 'image/png', buffer: Buffer.from('saved-photo') });
        await expect(cameraPhoto).toHaveValue('');
        await expect(page.locator('[data-upload-selection]')).toContainText('Gallery or files: saved-photo.png');
        await captureFieldTest(page, 'field-photo-source-390x844.png');
        const shortControls = await page.locator('button, input, select, textarea, a.button-primary, a.button-secondary').evaluateAll((elements) => elements
            .filter((element) => element.offsetParent !== null)
            .filter((element) => Math.max(element.getBoundingClientRect().height, element.closest('label')?.getBoundingClientRect().height ?? 0) < 44)
            .map((element) => `${element.tagName.toLowerCase()}#${element.id}.${element.className}:${element.getBoundingClientRect().height}`));
        expect(shortControls).toEqual([]);
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await expect(page.locator('[data-connectivity-banner]')).toBeVisible();
        await expect(page.locator('[data-connectivity-label]')).toHaveText('Offline');
        const enabledWriteButtons = await page.locator('form[method="POST"] button:not([disabled])').count();
        expect(enabledWriteButtons).toBe(0);
        await context.setOffline(false);
    });

    test('opt-in Field Visit Workspace V2 is reversible, accessible, and uploads a retryable multi-photo queue without reload', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'technician');
        await page.goto('/field');
        await page.getByRole('link', { name: /BETA A:/ }).first().click();
        if (await page.getByRole('button', { name: 'Start En Route' }).count()) await page.getByRole('button', { name: 'Start En Route' }).click();
        if (await page.getByRole('button', { name: 'Mark On Site' }).count()) await page.getByRole('button', { name: 'Mark On Site' }).click();
        await page.getByRole('link', { name: 'Try new Visit workspace' }).click();
        await expect(page).toHaveURL(/\/field\/visits\/\d+\/workspace-v2/);
        await expect(page.getByRole('link', { name: 'Switch to classic workspace' })).toBeVisible();

        const tabs = page.getByRole('tablist', { name: 'Visit workspace' });
        await expect(tabs.getByRole('tab', { name: /Overview/ })).toHaveAttribute('aria-selected', 'true');
        await tabs.getByRole('tab', { name: /Work/ }).click();
        await expect(page).toHaveURL(/#work$/);
        await expect(page.locator('[data-v2-panel="work"]')).toBeVisible();
        await expect(page.locator('[data-v2-panel="overview"]')).toBeHidden();

        await tabs.getByRole('tab', { name: /Evidence/ }).click();
        await page.locator('[data-v2-upload-form] label').filter({ hasText: /^After$/ }).click();
        let failedOnce = false;
        await page.route('**/field/visits/*/media', async (route) => {
            if (route.request().method() === 'POST' && !failedOnce) {
                failedOnce = true;
                await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Simulated weak-connection failure.' }) });
                return;
            }
            await route.continue();
        });
        const tinyPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
        const currentUrl = page.url();
        await page.getByLabel('Choose multiple').setInputFiles([
            { name: 'workspace-a.png', mimeType: 'image/png', buffer: tinyPng },
            { name: 'workspace-b.png', mimeType: 'image/png', buffer: tinyPng },
        ]);
        await expect(page.locator('[data-v2-upload-summary]')).toContainText('1 uploaded');
        await expect(page.locator('[data-v2-upload-summary]')).toContainText('1 need retry');
        await page.getByRole('button', { name: 'Retry' }).click();
        await expect(page.locator('[data-v2-upload-summary]')).toContainText('2 uploaded');
        expect(page.url()).toBe(currentUrl);
        await expect(page.getByLabel('Choose multiple')).toHaveValue('');

        await page.getByRole('button', { name: 'Finish Visit' }).first().click();
        const finish = page.getByRole('dialog', { name: 'Finish Visit' });
        await expect(finish).toBeVisible();
        await finish.getByText('Needs return trip', { exact: true }).click();
        await finish.getByRole('button', { name: '2 Work' }).click();
        await expect(finish.getByLabel('Return reason')).toBeVisible();
        await expect(finish.getByLabel('Exceptions')).toBeHidden();
        await finish.getByRole('button', { name: '1 Outcome' }).click();
        await finish.getByText('Resolved', { exact: true }).click();
        await finish.getByRole('button', { name: '2 Work' }).click();
        await expect(finish.getByLabel('Exceptions')).toBeVisible();
        await expect(finish.getByLabel('Return reason')).toBeHidden();
        await finish.getByRole('button', { name: 'Close' }).click();

        await page.getByRole('link', { name: 'Switch to classic workspace' }).click();
        await expect(page.getByRole('link', { name: 'Try new Visit workspace' })).toBeVisible();
        await page.getByRole('link', { name: 'Try new Visit workspace' }).click();
        await expect(page.locator('[data-v2-media-list]')).toContainText('After');

        for (const viewport of [
            { width: 390, height: 844 }, { width: 430, height: 932 }, { width: 768, height: 1024 },
            { width: 1280, height: 900 }, { width: 1440, height: 900 }, { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport);
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            await captureFieldTest(page, `field-workspace-v2-${viewport.width}x${viewport.height}.png`);
        }
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
        await page.goto('/office/invoices?status=issued');
        await page.getByRole('link', { name: /NDT-INV-/ }).first().click();
        await page.getByRole('link', { name: 'Customer view' }).click();
        await expect(page.getByRole('heading', { name: /NDT-INV-/ })).toBeVisible();
        await expect(page.getByText('Invoice acknowledgment')).toBeVisible();
        await expect(page.getByText('Internal billing note')).toHaveCount(0);
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        await expect(page.locator('#contact_name')).toHaveCSS('min-height', '44px');
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
