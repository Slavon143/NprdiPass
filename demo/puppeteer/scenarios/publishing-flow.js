import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption, highlightElement } from '../helpers/highlight.js';
import { assertNoError } from '../helpers/assertions.js';
import { APP_URL, TIMEOUTS } from '../config/demo.config.js';
import productData from '../fixtures/product.json' with { type: 'json' };

export async function publishProduct(page, productUuid, documents, report, recordStep) {
  const passport = productData.passport;

  // ── Part A: Create passport if needed ──
  try {
    showCaption('Checking passport status');
    await navigateToUrl(page, `/catalog/products/${productUuid}`);
    await waitForPageReady(page);

    const createBtn = await page.$(
      'form[action*="passport/store"] button, form[action*="passport"] button::-p-text("Create")'
    );
    if (createBtn) {
      showCaption('Creating product passport');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: TIMEOUTS.navigation }).catch(() => {}),
        createBtn.click(),
      ]);
      await waitForPageReady(page);
      recordStep(report, 'Create passport', 'passed', { action: 'passport_created' });
    } else {
      recordStep(report, 'Create passport', 'passed', { action: 'passport_exists' });
    }
  } catch (error) {
    console.warn(`Passport creation: ${error.message}`);
    recordStep(report, 'Create passport', 'failed', { error: error.message });
  }

  // ── Part B: Fill passport sections via editor ──
  try {
    showCaption('Filling passport sections');
    await navigateToUrl(page, `/catalog/products/${productUuid}/passport/edit`);
    await waitForPageReady(page);

    const sectionsToFill = [
      {
        sectionKey: 'identity',
        fields: {
          public_name: productData.name,
          public_description: productData.description,
        },
      },
      {
        sectionKey: 'manufacturer_and_operator',
        fields: {
          manufacturer_display_name: passport.manufacturer,
          manufacturer_country: 'SE',
          manufacturer_email: 'compliance@nordisafe.example',
        },
      },
      {
        sectionKey: 'origin_and_traceability',
        fields: {
          country_of_origin: 'SE',
          traceability_notes: `Product manufactured by ${passport.manufacturer} in ${passport.countryOfManufacture}.`,
        },
      },
      {
        sectionKey: 'materials_and_composition',
        fields: {
          composition_notes: passport.materialComposition,
        },
      },
      {
        sectionKey: 'safety',
        fields: {
          storage_instructions: 'Store dry, away from open flame and direct heat.',
          emergency_instructions: 'Remove the garment and seek medical advice if irritation occurs.',
        },
      },
      {
        sectionKey: 'repair_and_spare_parts',
        fields: {
          repairable: true,
          repair_instructions: passport.repairInformation,
        },
      },
      {
        sectionKey: 'recycling_and_disposal',
        fields: {
          recycling_instructions: passport.recyclingInstructions,
        },
      },
      {
        sectionKey: 'certifications_and_documents',
        fields: {
          compliance_summary: passport.complianceStatus,
        },
      },
      {
        sectionKey: 'support_and_contact',
        fields: {
          warranty_summary: 'Warranty period: ' + passport.warrantyPeriod,
        },
      },
    ];

    for (const { sectionKey, fields } of sectionsToFill) {
      try {
        const sectionEl = await page.$(`[data-section="${sectionKey}"]`);
        if (!sectionEl) {
          console.warn(`Section "${sectionKey}" not found on page`);
          continue;
        }

        const isEnabled = await sectionEl.evaluate((el) => !el.classList.contains('opacity-50'));
        if (!isEnabled) {
          const enableBtn = await sectionEl.$('.section-toggle[data-action="enable"]');
          if (enableBtn) {
            await enableBtn.click();
            await new Promise((r) => setTimeout(r, 400));
          }
        }

        let sectionFilled = false;
        for (const [fieldKey, value] of Object.entries(fields)) {
          const input = await sectionEl.$(`.section-input[data-field="${fieldKey}"]`);
          if (!input) continue;

          const fieldType = await input.evaluate((el) => el.dataset.fieldType || el.type || 'text');

          const isCheckbox = await input.evaluate((el) => el.type === 'checkbox');

          if (fieldType === 'boolean' || isCheckbox) {
            const isChecked = await input.evaluate((el) => el.checked);
            if (value && !isChecked) {
              await input.click();
            } else if (!value && isChecked) {
              await input.click();
            }
          } else if (fieldType === 'long_text' || (await input.evaluate((el) => el.tagName === 'TEXTAREA'))) {
            await input.click({ clickCount: 3 });
            await input.type(String(value), { delay: 20 });
          } else {
            await input.click({ clickCount: 3 });
            await input.type(String(value), { delay: 20 });
          }
          sectionFilled = true;
        }

        if (sectionFilled) {
          const saveBtn = await sectionEl.$('.save-section');
          if (saveBtn) {
            await saveBtn.click();

            try {
              await page.waitForFunction(
                (sel) => {
                  const btn = document.querySelector(sel);
                  if (!btn) return true;
                  return btn.textContent.includes('Saved') || btn.classList.contains('bg-green-600');
                },
                { timeout: 8000 },
                `.save-section[data-section="${sectionKey}"]`
              );
            } catch {
              await new Promise((r) => setTimeout(r, 1500));
            }
          }

          recordStep(report, `Fill section: ${sectionKey}`, 'passed', { section: sectionKey });
        }
      } catch (sectionErr) {
        console.warn(`Section "${sectionKey}" skipped: ${sectionErr.message}`);
        recordStep(report, `Fill section: ${sectionKey}`, 'failed', { error: sectionErr.message });
      }
    }

    if (documents.length > 0) {
      const syncResult = await page.evaluate(async ({ targetProductUuid, documentReferences }) => {
        const csrfToken = document.querySelector('#csrfToken')?.value;
        const expectedRevision = Number.parseInt(document.querySelector('#draftRevision')?.textContent || '', 10);
        const response = await fetch(`/catalog/products/${targetProductUuid}/passport/documents`, {
          method: 'PUT',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
          body: JSON.stringify({
            document_references: documentReferences.map((item, index) => ({
              document_uuid: item.uuid,
              role: 'other',
              display_order: index,
            })),
            expected_revision: expectedRevision,
          }),
        });

        return { status: response.status, body: await response.json() };
      }, { targetProductUuid: productUuid, documentReferences: documents });

      if (syncResult.status !== 200 || syncResult.body.payload?.document_references?.length !== documents.length) {
        throw new Error(`Passport document sync failed with HTTP ${syncResult.status}`);
      }

      recordStep(report, 'Attach documents to passport', 'passed', {
        count: documents.length,
        draftRevision: syncResult.body.draft_revision,
      });
    }
  } catch (error) {
    console.error(`Failed filling passport: ${error.message}`);
    recordStep(report, 'Fill passport sections', 'failed', { error: error.message });
  }

  // ── Part C: Publish passport ──
  try {
    showCaption('Publishing passport');
    await navigateToUrl(page, `/catalog/products/${productUuid}/passport/publish-confirm`);
    await waitForPageReady(page);

    const publishBtn = await page.$('button::-p-text("Publish Passport"), button::-p-text("Publish")');
    if (!publishBtn) {
      throw new Error('Publish action is unavailable because the passport is not ready');
    }

    const warnCheckbox = await page.$('input[name="acknowledge_warnings"]');
    if (warnCheckbox) {
      await warnCheckbox.click();
    }

    await highlightElement(page, publishBtn);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: TIMEOUTS.navigation }).catch(() => {}),
      publishBtn.click(),
    ]);
    await waitForPageReady(page);

    const pageText = await page.evaluate(() => document.body.textContent || '');
    const isPublished = pageText.toLowerCase().includes('published') &&
      !pageText.toLowerCase().includes('resolve blockers before publishing');

    recordStep(report, 'Publish passport', isPublished ? 'passed' : 'failed', {
      published: isPublished,
    });

    if (!isPublished) {
      throw new Error('Passport publication was not confirmed by the resulting page');
    }
  } catch (error) {
    console.error(`Publish failed: ${error.message}`);
    recordStep(report, 'Publish passport', 'failed', { error: error.message });
    throw error;
  }

  // ── Part D: QR code and public URL ──
  let publicUrl = '';

  try {
    showCaption('Retrieving QR code and public URL');
    await navigateToUrl(page, `/catalog/products/${productUuid}/passport/qr`);
    await waitForPageReady(page);

    const qrImg = await page.$('img[src*="qr"]');
    if (qrImg) console.log('  QR code image found');

    publicUrl = await page.evaluate(() => {
      const input =
        document.querySelector('#passport-public-url') ||
        document.querySelector('input[type="text"][readonly]') ||
        document.querySelector('input[value*="http"]');
      return input ? input.value.trim() : '';
    });

    if (!publicUrl) {
      publicUrl = await page.evaluate(() => {
        const links = document.querySelectorAll('a[target="_blank"]');
        for (const link of links) {
          const href = link.getAttribute('href') || '';
          if (href.includes('/p/')) return href;
        }
        return '';
      });
    }

    recordStep(report, 'QR code and public URL', publicUrl ? 'passed' : 'failed', {
      publicUrl,
    });
  } catch (error) {
    console.error(`QR retrieval failed: ${error.message}`);
    recordStep(report, 'QR code and public URL', 'failed', { error: error.message });
  }

  return { published: publicUrl !== '', publicUrl, publicId: productUuid };
}


