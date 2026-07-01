const fs = require('fs');
const path = require('path');
const { PDFParse } = require('pdf-parse');

const files = [
    { name: 'Sales_UAT.pdf', path: 'e:\\testing_clara\\testing\\Sales_UAT.pdf' },
    { name: 'Superadmin_UAT.pdf', path: 'e:\\testing_clara\\testing\\Superadmin_UAT.pdf' },
    { name: 'Supervisor_UAT.pdf', path: 'e:\\testing_clara\\testing\\Supervisor_UAT.pdf' },
    { name: 'Superadmin_Testing_Report.pdf', path: 'e:\\testing_clara\\testing\\Superadmin_Testing_Report.pdf' },
    { name: 'Manager_Testing_Report.pdf', path: 'e:\\testing_clara\\testing\\Manager_Testing_Report.pdf' }
];

async function parseAll() {
    for (const f of files) {
        if (!fs.existsSync(f.path)) {
            console.log(`File not found: ${f.path}`);
            continue;
        }
        try {
            const dataBuffer = fs.readFileSync(f.path);
            const p = new PDFParse(new Uint8Array(dataBuffer));
            const result = await p.getText();
            const textContent = result.text || '';
            const outPath = f.path.replace('.pdf', '.txt');
            fs.writeFileSync(outPath, textContent);
            console.log(`Successfully parsed ${f.name} to ${path.basename(outPath)} (${textContent.length} chars)`);
        } catch (e) {
            console.error(`Error parsing ${f.name}:`, e);
        }
    }
}

parseAll();
