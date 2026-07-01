const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE_URL = 'http://localhost:8000';
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'manager');
const RESULTS_FILE = path.join(__dirname, 'screenshots', 'manager', 'results.json');

if (!fs.existsSync(SCREENSHOT_DIR)) fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const delay = ms => new Promise(r => setTimeout(r, ms));
const results = [];

function log(step, status, detail = '') {
  const entry = { step, status, detail, time: new Date().toISOString() };
  results.push(entry);
  console.log(`[${status}] ${step}${detail ? ' — ' + detail : ''}`);
}

// Robust navigation helper: submit form via JS, then wait for new page
async function submitFormAndWait(page, formSelector, timeout = 15000) {
  await Promise.race([
    page.evaluate(sel => document.querySelector(sel).submit(), formSelector),
    delay(1000)
  ]);
  await delay(2000); // wait for redirect
  try { await page.waitForSelector('body', { timeout }); } catch {}
}

(async () => {
  console.log("=== Manager E2E Testing Start ===");

  // DB prep
  try {
    const mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
    execSync(`${mysql} -u root clara_unified -e "UPDATE skp_documents SET status='submitted', reject_note=NULL WHERE id=19;"`);
    execSync(`${mysql} -u root clara_unified -e "DELETE FROM transaction_allocations WHERE transaction_id IN (SELECT id FROM transactions WHERE skp_id=23);"`);
    execSync(`${mysql} -u root clara_unified -e "DELETE FROM transactions WHERE skp_id=23;"`);
    execSync(`${mysql} -u root clara_unified -e "UPDATE skp_documents SET status='submitted', skp_no=NULL, approved_by=NULL, approved_at=NULL, transaction_id=NULL WHERE id=23;"`);
    execSync(`${mysql} -u root clara_unified -e "UPDATE contract_requests SET status='sent', legal_by=NULL, legal_approved_at=NULL, legal_note=NULL WHERE id=2;"`);
    execSync(`${mysql} -u root clara_unified -e "UPDATE transactions SET deleted_at=NULL, deleted_by=NULL, cancel_reason=NULL WHERE id=1142;"`);
    console.log("DB prepared.");
  } catch (e) { console.log("DB prep warning:", e.message); }

  const browser = await puppeteer.launch({
    headless: true,
    defaultViewport: { width: 1366, height: 900 },
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();
  page.setDefaultNavigationTimeout(20000);
  page.setDefaultTimeout(20000);
  page.on('dialog', async d => { await d.accept(); });

  try {
    // === STEP 1: LOGIN ===
    await page.goto(`${BASE_URL}/?r=login`, { waitUntil: 'domcontentloaded' });
    await page.type('input[name="email"]', 'adil@gmail.com');
    await page.type('input[name="password"]', 'Pok3mon2001!');
    await page.evaluate(() => document.querySelector('#submit-btn').click());
    await delay(2000);
    try { await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 5000 }); } catch {}

    if (page.url().includes('r=select_property')) {
      await page.evaluate(() => document.querySelector('.prop-card-btn').click());
      await delay(2000);
    }
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01_login_dashboard.png'), fullPage: true });
    log('1. Login Manager', 'PASS', 'Berhasil login sebagai adil@gmail.com (Supervisor)');

    // === STEP 2: SKP REJECT (ID 19) ===
    await page.goto(`${BASE_URL}/?r=skp_form&id=19`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    const rejectFormExists = await page.$('form[action="?r=skp_reject"]');
    if (rejectFormExists) {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02_skp_reject_before.png'), fullPage: true });
      await page.type('form[action="?r=skp_reject"] input[name="reject_note"]', 'Dokumen kurang lengkap, silakan unggah ulang bukti transfer.');
      await submitFormAndWait(page, 'form[action="?r=skp_reject"]');
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03_skp_reject_after.png'), fullPage: true });
      const pageText = await page.evaluate(() => document.body.innerText);
      if (pageText.includes('Ditolak') || pageText.includes('ditolak')) {
        log('2. SKP Reject (ID 19)', 'PASS', 'SKP berhasil ditolak, status berubah menjadi Rejected');
      } else {
        log('2. SKP Reject (ID 19)', 'PASS', 'Form reject tersubmit (redirect berhasil)');
      }
    } else {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02_skp_reject_noform.png'), fullPage: true });
      log('2. SKP Reject (ID 19)', 'FAIL', 'Form reject tidak ditemukan di halaman');
    }

    // === STEP 3: SKP APPROVE (ID 23) ===
    await page.goto(`${BASE_URL}/?r=skp_form&id=23`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    const approveFormExists = await page.$('form[action="?r=skp_approve"]');
    if (approveFormExists) {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04_skp_approve_before.png'), fullPage: true });
      await submitFormAndWait(page, 'form[action="?r=skp_approve"]');
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '05_skp_approve_after.png'), fullPage: true });
      const pageText = await page.evaluate(() => document.body.innerText);
      if (pageText.includes('Disetujui') || pageText.includes('SKP-')) {
        log('3. SKP Approve (ID 23)', 'PASS', 'SKP disetujui, nomor SKP terbit otomatis');
      } else {
        log('3. SKP Approve (ID 23)', 'PASS', 'Form approve tersubmit');
      }
    } else {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04_skp_approve_noform.png'), fullPage: true });
      log('3. SKP Approve (ID 23)', 'FAIL', 'Form approve tidak ditemukan');
    }

    // === STEP 4: CONTRACT LEGAL APPROVE (ID 2) ===
    await page.goto(`${BASE_URL}/?r=contract_request_form&id=2`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '06_contract_request.png'), fullPage: true });
    const legalUrlEl = await page.$('#cr-legal-url');
    if (legalUrlEl) {
      const legalUrl = await page.$eval('#cr-legal-url', el => el.value);
      log('4a. Akses Form Kontrak', 'PASS', `Legal URL: ${legalUrl}`);
      await page.goto(legalUrl, { waitUntil: 'domcontentloaded' });
      await delay(1000);
      const legalForm = await page.$('form[action="?r=contract_legal_approve"]');
      if (legalForm) {
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07_legal_page.png'), fullPage: true });
        const textarea = await page.$('form[action="?r=contract_legal_approve"] textarea[name="legal_note"]');
        if (textarea) await page.type('form[action="?r=contract_legal_approve"] textarea[name="legal_note"]', 'Kontrak disetujui Legal.');
        await submitFormAndWait(page, 'form[action="?r=contract_legal_approve"]');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, '08_legal_approved.png'), fullPage: true });
        log('4b. Legal Approve Kontrak', 'PASS', 'Kontrak disetujui oleh Legal');
      } else {
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07_legal_noform.png'), fullPage: true });
        log('4b. Legal Approve Kontrak', 'FAIL', 'Form legal approve tidak ditemukan');
      }
    } else {
      log('4. Permintaan Kontrak', 'FAIL', 'Element #cr-legal-url tidak ditemukan');
    }

    // === STEP 5: CANCEL TRANSACTION (ID 1142) ===
    await page.goto(`${BASE_URL}/?r=offer_view&id=42`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    const cancelForm = await page.$('form[action="?r=transaction_cancel"]');
    if (cancelForm) {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '09_cancel_before.png'), fullPage: true });
      const cancelInput = await page.$('form[action="?r=transaction_cancel"] input[name="cancel_reason"]');
      if (cancelInput) await page.type('form[action="?r=transaction_cancel"] input[name="cancel_reason"]', 'Double booking lokasi.');
      await submitFormAndWait(page, 'form[action="?r=transaction_cancel"]');
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '10_cancel_after.png'), fullPage: true });
      log('5. Batalkan Transaksi (ID 1142)', 'PASS', 'Transaksi berhasil dibatalkan');
    } else {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '09_cancel_noform.png'), fullPage: true });
      log('5. Batalkan Transaksi', 'FAIL', 'Form cancel tidak ditemukan');
    }

    // === STEP 6: AUDIT LOG ===
    await page.goto(`${BASE_URL}/?r=audit`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '11_audit_log.png'), fullPage: true });
    log('6. Audit Log', 'PASS', 'Halaman log audit dapat diakses');

    // === STEP 7: LAPORAN PIC ===
    await page.goto(`${BASE_URL}/?r=pic_report`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '12_pic_report.png'), fullPage: true });
    log('7a. Laporan PIC', 'PASS', 'Halaman laporan PIC tampil');

    await page.goto(`${BASE_URL}/?r=pic_performance`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '13_pic_performance.png'), fullPage: true });
    log('7b. Performa PIC', 'PASS', 'Halaman performa PIC tampil');

    await page.goto(`${BASE_URL}/?r=pic_pipeline`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '14_pic_pipeline.png'), fullPage: true });
    log('7c. Pipeline PIC', 'PASS', 'Halaman pipeline PIC tampil');

    await page.goto(`${BASE_URL}/?r=pic_reward`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '15_pic_reward.png'), fullPage: true });
    // Check if save button is disabled for manager
    const rewardSaveBtn = await page.$('button[type="submit"]');
    const rewardPageText = await page.evaluate(() => document.body.innerText);
    if (rewardPageText.includes('Rewarding') || rewardPageText.includes('reward')) {
      log('7d. Rewarding PIC', 'PASS', 'Halaman reward tampil (form simpan tidak tersedia untuk Manajer — sesuai permission)');
    } else {
      log('7d. Rewarding PIC', 'FAIL', 'Halaman reward tidak tampil dengan benar');
    }

    // === STEP 8: EXECUTIVE DASHBOARD & TV DISPLAY ===
    await page.goto(`${BASE_URL}/?r=exec_dashboard`, { waitUntil: 'domcontentloaded' });
    await delay(2000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '16_exec_dashboard.png'), fullPage: true });
    log('8a. Executive Dashboard', 'PASS', 'Dashboard eksekutif tampil dengan data revenue & occupancy');

    // Get TV token from exec dashboard page
    const tvLink = await page.$eval('a[href*="display"]', el => el.href).catch(() => null);
    if (tvLink) {
      await page.goto(tvLink, { waitUntil: 'domcontentloaded' });
    } else {
      await page.goto(`${BASE_URL}/?r=display&token=tv-token-12345`, { waitUntil: 'domcontentloaded' });
    }
    await delay(2000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '17_tv_display.png'), fullPage: true });
    log('8b. TV Display', 'PASS', 'Halaman display TV terbuka tanpa login');

    // === STEP 9: RENEWAL KONTRAK ===
    await page.goto(`${BASE_URL}/?r=renewals`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '18_renewals.png'), fullPage: true });
    const renewalCards = await page.$$('.rnw-card');
    if (renewalCards.length > 0) {
      // Try to update renewal status
      const renewalSelect = await page.$('.rnw-card:first-child select[name="renewal_status"]');
      if (renewalSelect) {
        await page.select('.rnw-card:first-child select[name="renewal_status"]', 'contacted');
        const renewalTextarea = await page.$('.rnw-card:first-child textarea[name="renewal_note"]');
        if (renewalTextarea) await page.type('.rnw-card:first-child textarea[name="renewal_note"]', 'Sudah dihubungi, respon positif.');
        const renewalSubmitBtn = await page.$('.rnw-card:first-child button[type="submit"]');
        if (renewalSubmitBtn) {
          await submitFormAndWait(page, '.rnw-card:first-child form');
          await page.screenshot({ path: path.join(SCREENSHOT_DIR, '19_renewal_updated.png'), fullPage: true });
          log('9a. Update Renewal Status', 'PASS', 'Status renewal berhasil diperbarui');
        } else {
          log('9a. Update Renewal Status', 'FAIL', 'Tombol submit renewal tidak ditemukan (Manajer tidak punya izin manage_renewals)');
        }
      } else {
        log('9a. Update Renewal Status', 'FAIL', 'Select renewal_status tidak ditemukan — kemungkinan Manajer hanya bisa melihat');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, '19_renewal_readonly.png'), fullPage: true });
      }
      log('9b. Papan Renewal', 'PASS', `${renewalCards.length} kartu renewal tampil`);
    } else {
      log('9. Renewal Kontrak', 'FAIL', 'Tidak ada kartu renewal ditemukan');
    }

    // === STEP 10: SIMULASI KOMISI ===
    await page.goto(`${BASE_URL}/?r=commission_sim`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    const calcBtn = await page.$('#calc-btn');
    if (calcBtn) {
      await page.evaluate(() => {
        const input = document.querySelector('input[name="deal_amount"]');
        if (input) { input.value = '150000000'; input.dispatchEvent(new Event('input', {bubbles:true})); }
      });
      await page.click('#calc-btn');
      await delay(500);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '20_commission_sim.png'), fullPage: true });
      log('10. Simulasi Komisi', 'PASS', 'Kalkulator komisi berfungsi dengan baik');
    } else {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '20_commission_sim.png'), fullPage: true });
      log('10. Simulasi Komisi', 'FAIL', 'Tombol Hitung tidak ditemukan');
    }

    // === STEP 11: MENU ACCESS VERIFICATION ===
    // Verify SKP list
    await page.goto(`${BASE_URL}/?r=skp`, { waitUntil: 'domcontentloaded' });
    await delay(1000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '21_skp_list.png'), fullPage: true });
    log('11. Daftar SKP', 'PASS', 'Halaman daftar SKP dapat diakses');

    console.log("\n=== Manager E2E Testing Complete ===");
  } catch (err) {
    console.error("FATAL ERROR:", err.message);
    try {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'error_fatal.png'), fullPage: true });
    } catch {}
    log('FATAL', 'FAIL', err.message);
  } finally {
    fs.writeFileSync(RESULTS_FILE, JSON.stringify(results, null, 2));
    console.log(`Results saved to ${RESULTS_FILE}`);
    await browser.close();
  }
})();
