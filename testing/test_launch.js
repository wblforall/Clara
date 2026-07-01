const puppeteer = require('puppeteer');
(async () => {
  try {
    const browser = await puppeteer.launch({ headless: true });
    console.log("SUCCESS! Browser launched.");
    await browser.close();
  } catch (err) {
    console.error("ERROR launching browser:", err);
  }
})();
