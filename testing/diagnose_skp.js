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
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load' }),
      page.click('#submit-btn')
    ]);
    
    if (page.url().includes('r=select_property')) {
      console.log("Selecting property...");
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'load' }),
        page.click('.prop-card-btn')
      ]);
    }
    
    console.log("Going to SKP form 19...");
    await page.goto('http://localhost:8000/?r=skp_form&id=19', { waitUntil: 'load' });
    
    console.log("Current URL:", page.url());
    
    // Check if reject form exists
    const formExists = await page.evaluate(() => {
      const form = document.querySelector('form[action="?r=skp_reject"]');
      return form ? {
        exists: true,
        html: form.outerHTML,
        visible: form.getBoundingClientRect().width > 0
      } : { exists: false };
    });
    
    console.log("Reject Form details:", JSON.stringify(formExists, null, 2));
    
    // Check the whole page panels
    const panels = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('.panel')).map(p => ({
        text: p.innerText.substring(0, 100),
        html: p.outerHTML.substring(0, 200)
      }));
    });
    console.log("Panels on page:", JSON.stringify(panels, null, 2));

  } catch (err) {
    console.error("Error:", err);
  } finally {
    await browser.close();
  }
})();
