const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE_URL = 'http://localhost:8000';
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR);
}

// Helper to delay
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

// Helper for click and wait navigation
const clickAndWait = async (page, selector) => {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click(selector)
  ]);
};

// Helper to clear and type
const clearAndType = async (page, selector, text) => {
  await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (el) {
      el.value = '';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, selector);
  await page.type(selector, text);
};

// Format date for current month
const getPeriod = () => {
  const d = new Date();
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${yyyy}-${mm}`;
};

(async () => {
  console.log("Starting automated E2E tests for Clara Superadmin...");
  
  // Cleanup database from previous test runs to prevent duplicate keys
  try {
    console.log("Cleaning up database entries from previous runs...");
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM master_pic WHERE email='sales.budi@clara.local';"`);
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM user_properties WHERE user_id = (SELECT id FROM users WHERE email='sales.budi@clara.local');"`);
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM users WHERE email='sales.budi@clara.local';"`);
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM master_cl_units WHERE location_name='Fiktif Unit';"`);
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM targets_monthly WHERE target_amount=50000000;"`);
    execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -e "DELETE FROM master_clients WHERE company_name='Fiktif Corp';"`);
    console.log("Database cleanup completed.");
  } catch (dbErr) {
    console.log("Warning: Database cleanup had some issues (might be due to empty tables):", dbErr.message);
  }

  // Launch Puppeteer browser
  const browser = await puppeteer.launch({
    headless: true,
    defaultViewport: { width: 1280, height: 800 },
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  
  // Set default timeout to 15s
  page.setDefaultNavigationTimeout(15000);
  page.setDefaultTimeout(15000);

  try {
    // ----------------------------------------------------
    // STEP 1: LOGIN SUPERADMIN
    // ----------------------------------------------------
    console.log("Step 1: Logging in as Superadmin...");
    await page.goto(`${BASE_URL}/?r=login`, { waitUntil: 'networkidle2' });
    
    // Fill credentials
    await page.type('input[name="email"]', 'saadilaeffendi@gmail.com');
    await page.type('input[name="password"]', 'pok3mon');
    
    console.log("Clicking login submit button...");
    await clickAndWait(page, '#submit-btn');
    
    // Check if we are on select property page
    const currentUrl = page.url();
    if (currentUrl.includes('r=select_property')) {
      console.log("On property selection page. Selecting first property...");
      await page.waitForSelector('.prop-card-btn');
      await clickAndWait(page, '.prop-card-btn');
    }
    
    console.log("Successfully logged in. Capturing Dashboard...");
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '1_dashboard.png') });

    // ----------------------------------------------------
    // STEP 2: USER & ROLE - LIST & ADD USER
    // ----------------------------------------------------
    console.log("Step 2: Accessing Users list...");
    await page.goto(`${BASE_URL}/?r=users`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '2_users_list.png') });
    
    console.log("Navigating to Tambah User form...");
    await page.goto(`${BASE_URL}/?r=user_form`, { waitUntil: 'networkidle2' });
    
    // Fill User Form
    await page.type('input[name="name"]', 'Sales Budi');
    await page.type('input[name="email"]', 'sales.budi@clara.local');
    await page.select('select[name="role"]', 'sales');
    await page.select('select[name="status"]', 'active');
    
    // Check property checkbox (first property)
    await page.evaluate(() => {
      const checkbox = document.querySelector('input[name="property_ids[]"]');
      if (checkbox) checkbox.checked = true;
    });
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '3_add_user_form.png') });
    
    console.log("Submitting User form...");
    await clickAndWait(page, 'button[type="submit"]');
    
    // Captured WA template screen
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '4_user_created.png') });
    
    // Get newly created user ID from database to edit
    const getBudiIdCmd = `C:\\xampp\\mysql\\bin\\mysql.exe -u root clara_unified -s -N -e "SELECT id FROM users WHERE email='sales.budi@clara.local' ORDER BY id DESC LIMIT 1;"`;
    const budiId = execSync(getBudiIdCmd).toString().trim();
    console.log(`Newly created user Budi has ID: ${budiId}`);

    // ----------------------------------------------------
    // STEP 3: MASTER DATA - CREATE PIC AND LINK TO BUDI
    // ----------------------------------------------------
    console.log("Step 3: Creating PIC for Sales Budi...");
    await page.goto(`${BASE_URL}/?r=master&type=pic`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '11_master_pic.png') });
    
    await page.goto(`${BASE_URL}/?r=master_form&type=pic`, { waitUntil: 'networkidle2' });
    
    await page.type('input[name="name"]', 'Sales Budi');
    await page.type('input[name="role_name"]', 'Sales');
    await page.type('input[name="email"]', 'sales.budi@clara.local');
    await page.type('input[name="phone"]', '08123456789');
    await page.select('select[name="user_id"]', budiId);
    await clearAndType(page, 'input[name="target_share_pct"]', '100');
    await page.select('select[name="status"]', 'active');
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '12_add_pic_form.png') });
    
    console.log("Submitting PIC form...");
    await clickAndWait(page, 'button[type="submit"]');

    // ----------------------------------------------------
    // STEP 4: USER NON-ACTIVATION & BLOCKED LOGIN TEST
    // ----------------------------------------------------
    console.log("Step 4: Non-activating Budi's account...");
    await page.goto(`${BASE_URL}/?r=user_form&id=${budiId}`, { waitUntil: 'networkidle2' });
    await page.select('select[name="status"]', 'inactive');
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '5_edit_user_inactive.png') });
    
    console.log("Submitting Inactive status for Budi...");
    await clickAndWait(page, 'button[type="submit"]');
    
    await page.goto(`${BASE_URL}/?r=users`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '6_users_list_inactive.png') });
    
    // Log out superadmin
    console.log("Logging out superadmin...");
    await page.goto(`${BASE_URL}/?r=logout`, { waitUntil: 'networkidle2' });
    
    // Attempt login with Budi (password defaults to '123456' on creation)
    console.log("Attempting blocked login for inactive user Budi...");
    await page.goto(`${BASE_URL}/?r=login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', 'sales.budi@clara.local');
    await page.type('input[name="password"]', '123456');
    
    console.log("Submitting Budi's blocked login...");
    await clickAndWait(page, '#submit-btn');
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '6b_login_inactive_blocked.png') });
    console.log("Blocked login attempt recorded successfully.");

    // Log back in as Superadmin
    console.log("Logging back in as Superadmin...");
    await page.goto(`${BASE_URL}/?r=login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', 'saadilaeffendi@gmail.com');
    await page.type('input[name="password"]', 'pok3mon');
    
    console.log("Submitting Superadmin login...");
    await clickAndWait(page, '#submit-btn');
    
    // Re-select first property
    if (page.url().includes('r=select_property')) {
      console.log("Selecting first property for Superadmin...");
      await clickAndWait(page, '.prop-card-btn');
    }

    // ----------------------------------------------------
    // STEP 5: ROLE & PERMISSION
    // ----------------------------------------------------
    console.log("Step 5: Configuring Role & Permissions for supervisor...");
    await page.goto(`${BASE_URL}/?r=roles`, { waitUntil: 'networkidle2' });
    
    // Modify supervisor checkboxes
    await page.evaluate(() => {
      // Checked permissions
      const checkList = ['approve_skp', 'manage_offers', 'manage_skp', 'view_exec_summary', 'view_renewals', 'view_commission_sim', 'export_reports', 'view_pic_report'];
      // Unchecked permissions
      const uncheckList = ['manage_users', 'view_logs', 'manage_deleted'];
      
      checkList.forEach(perm => {
        const cb = document.querySelector(`input[name="perms[supervisor][]"][value="${perm}"]`);
        if (cb) cb.checked = true;
      });
      uncheckList.forEach(perm => {
        const cb = document.querySelector(`input[name="perms[supervisor][]"][value="${perm}"]`);
        if (cb) cb.checked = false;
      });
    });
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '7_roles_permissions.png') });
    
    console.log("Saving permissions...");
    await clickAndWait(page, 'button[type="submit"]');

    // ----------------------------------------------------
    // STEP 6: MASTER EXHIBITION
    // ----------------------------------------------------
    console.log("Step 6: Accessing Master Exhibition...");
    await page.goto(`${BASE_URL}/?r=master&type=cl`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '8_master_exhibition.png') });
    
    await page.goto(`${BASE_URL}/?r=master_form&type=cl`, { waitUntil: 'networkidle2' });
    
    // Generate unit code or enter manual
    await page.type('input[name="location_name"]', 'Fiktif Unit');
    await page.select('select[name="floor"]', 'GF');
    await page.type('input[name="area_sqm"]', '25');
    await clearAndType(page, 'input#rate_fmt', '150000');
    
    // Select first option for unit type
    await page.evaluate(() => {
      const select = document.querySelector('select[name="unit_type"]');
      if (select && select.options.length > 1) {
        select.selectedIndex = 1;
      }
    });
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '9_add_exhibition_form.png') });
    
    console.log("Submitting Master Exhibition form...");
    await clickAndWait(page, 'button[type="submit"]');
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '10_exhibition_list.png') });

    // ----------------------------------------------------
    // STEP 7: MASTER TARGET BULANAN
    // ----------------------------------------------------
    console.log("Step 7: Accessing Master Target Bulanan...");
    await page.goto(`${BASE_URL}/?r=master&type=target`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '13_master_target.png') });
    
    await page.goto(`${BASE_URL}/?r=master_form&type=target`, { waitUntil: 'networkidle2' });
    await clearAndType(page, 'input#target_amount_fmt', '50000000');
    
    // Fill period (e.g. 2026-06)
    const period = getPeriod();
    await page.evaluate((periodVal) => {
      const input = document.querySelector('input[name="period_key"]');
      if (input) input.value = periodVal;
    }, period);
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '14_add_target_form.png') });
    
    console.log("Submitting Target Bulanan form...");
    await clickAndWait(page, 'button[type="submit"]');

    // ----------------------------------------------------
    // STEP 8: MASTER CLIENT
    // ----------------------------------------------------
    console.log("Step 8: Accessing Master Client list...");
    await page.goto(`${BASE_URL}/?r=clients`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '15_master_clients.png') });
    
    console.log("Navigating to Tambah Client...");
    await page.goto(`${BASE_URL}/?r=client_form`, { waitUntil: 'networkidle2' });
    
    await page.type('input[name="company_name"]', 'Fiktif Corp');
    await page.type('input[name="brand_name"]', 'Fiktif Brand');
    await page.type('input[name="npwp"]', '012345678901234');
    await page.type('textarea[name="address"]', 'Jalan Fiktif No 1');
    
    // Select Province "Kalimantan Timur"
    await page.select('select[name="province"]', 'Kalimantan Timur');
    await delay(1000); // wait for ajax cities
    
    // Select City "Balikpapan"
    await page.select('select[name="city"]', 'Balikpapan');
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '16_add_client_form.png') });
    
    console.log("Submitting Client form...");
    await clickAndWait(page, 'button[type="submit"]');
    
    // Captured client profile
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '17_client_profile.png') });

    // ----------------------------------------------------
    // STEP 9: KELOLA OPSI DROPDOWN
    // ----------------------------------------------------
    console.log("Step 9: Accessing Kelola Opsi Dropdown...");
    await page.goto(`${BASE_URL}/?r=lookup_manage`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '18_dropdown_options.png') });

    // ----------------------------------------------------
    // STEP 10: AUDIT & TRASH & RECURRING
    // ----------------------------------------------------
    console.log("Step 10: Accessing Activity Log...");
    await page.goto(`${BASE_URL}/?r=audit`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '19_activity_log.png') });
    
    console.log("Accessing Transaksi Dihapus...");
    await page.goto(`${BASE_URL}/?r=deleted_transactions`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '20_deleted_transactions.png') });
    
    console.log("Accessing Konversi Recurring...");
    await page.goto(`${BASE_URL}/?r=recurring_candidates`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '21_recurring_conversion.png') });

    // ----------------------------------------------------
    // STEP 11: MULTI-PROPERTY & DASHBOARDS
    // ----------------------------------------------------
    console.log("Step 11: Switch Property E-Walk -> Pentacity...");
    // Find switch property url in E-Walk
    await page.goto(`${BASE_URL}/?r=dashboard`, { waitUntil: 'networkidle2' });
    await page.evaluate(() => {
      // Find the tab or dropdown link containing switch to Pentacity (property_id=2)
      const switchLink = Array.from(document.querySelectorAll('a')).find(a => a.href.includes('to=2'));
      if (switchLink) switchLink.click();
    });
    await delay(3000); // Wait for transition
    
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '22_switch_property.png') });
    
    console.log("Accessing Executive Summary...");
    await page.goto(`${BASE_URL}/?r=exec_dashboard`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '23_exec_summary.png') });
    
    console.log("Accessing Display TV...");
    await page.goto(`${BASE_URL}/?r=display&token=tv-token-12345`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '24_display_tv.png') });

    // ----------------------------------------------------
    // STEP 12: SECURITY GERBANG FILE BYPASS CHECK
    // ----------------------------------------------------
    console.log("Step 12: Security Bypass Check (without session)...");
    
    // Open a new browser context with no cookies / sessions
    const securityBrowser = await puppeteer.launch({ headless: true });
    const secPage = await securityBrowser.newPage();
    
    await secPage.goto(`${BASE_URL}/?r=file&p=uploads/signatures/test.png`, { waitUntil: 'networkidle2' });
    await secPage.screenshot({ path: path.join(SCREENSHOT_DIR, '25_security_bypass_blocked.png') });
    
    await securityBrowser.close();
    console.log("Security bypass check captured.");

    console.log("E2E Tests completed successfully!");

  } catch (err) {
    console.error("ERROR running E2E tests:", err);
    try {
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'error_snapshot.png') });
      console.log("Saved error snapshot to error_snapshot.png");
    } catch (ssErr) {
      console.error("Failed to capture error snapshot:", ssErr);
    }
  } finally {
    await browser.close();
  }
})();
