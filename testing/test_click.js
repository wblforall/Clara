const puppeteer = require('puppeteer');

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    defaultViewport: { width: 1280, height: 1000 },
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  
  try {
    console.log("Logging in...");
    await page.goto('http://localhost:8000/?r=login', { waitUntil: 'load' });
    await page.type('input[name="email"]', 'adil@gmail.com');
    await page.type('input[name="password"]', 'Pok3mon2001!');
    await page.click('#submit-btn');
    await page.waitForNavigation({ waitUntil: 'load' });
    
    if (page.url().includes('r=select_property')) {
      await page.click('.prop-card-btn');
      await page.waitForNavigation({ waitUntil: 'load' });
    }
    
    await page.goto('http://localhost:8000/?r=skp_form&id=19', { waitUntil: 'load' });
    console.log("URL before reject click:", page.url());
    
    console.log("Typing note...");
    await page.type('form[action="?r=skp_reject"] input[name="reject_note"]', 'Test rejection note');
    
    console.log("Clicking reject button...");
    await page.click('form[action="?r=skp_reject"] button[type="submit"]');
    
    console.log("Waiting 3 seconds...");
    await new Promise(r => setTimeout(r, 3000));
    
    console.log("URL after reject click + 3s:", page.url());
    
    const bodyText = await page.evaluate(() => document.body.innerText);
    console.log("Page text contains flash message?", bodyText.includes('ditolak'));
    
  } catch (err) {
    console.error("Error:", err);
  } finally {
    await browser.close();
  }
})();
