const XLSX = require('xlsx');
const fs = require('fs');
const path = require('path');

const inputFile = process.argv[2];
if (!inputFile) {
    console.error('Usage: node template_parser.js <input_file.xlsx>');
    process.exit(1);
}

try {
    const workbook = XLSX.readFile(inputFile);
    
    // Main data sheet (usually first)
    const mainSheetName = workbook.SheetNames[0];
    const datasheet = workbook.Sheets[mainSheetName];
    const data = XLSX.utils.sheet_to_json(datasheet, { header: 1 });

    const result = {
        media: [],
        cl_units: [],
        gudang: [],
        pic: [],
        target: []
    };

    // Try to find Target sheet
    const targetSheetName = workbook.SheetNames.find(n => n.includes('TARGET'));
    if (targetSheetName) {
        const targetSheet = workbook.Sheets[targetSheetName];
        const targetData = XLSX.utils.sheet_to_json(targetSheet, { header: 1 });
        // Assume format: Period (YYYY-MM), Amount
        for (let j = 0; j < targetData.length; j++) {
            const tr = targetData[j];
            if (!tr || tr.length < 2) continue;
            const period = String(tr[0] || '').trim();
            if (/^\d{4}-\d{2}$/.test(period)) {
                result.target.push({
                    period_key: period,
                    target_amount: parseFloat(String(tr[1]).replace(/,/g, ''))
                });
            }
        }
    }

    let currentSection = '';

    for (let i = 0; i < data.length; i++) {
        const row = data[i];
        if (!row || row.length === 0) continue;

        const firstCell = String(row[0] || '').trim();
        const secondCell = String(row[1] || '').trim();

        // Detect Sections
        if (firstCell === 'B.' || firstCell.includes('OCCUPANCY PER UNIT')) {
            currentSection = 'cl_units';
            continue;
        }
        if (firstCell === 'C.' || firstCell.includes('MEDIA PROMO')) {
            currentSection = 'media';
            continue;
        }
        if (firstCell === 'D.' || firstCell.includes('GUDANG / STORAGE')) {
            currentSection = 'gudang';
            continue;
        }
        if (firstCell === 'E.' || firstCell.includes('TRACING DEALING')) {
            currentSection = 'pic';
            continue;
        }

        // Parse Data based on section
        if (currentSection === 'cl_units') {
            // Header is NO, KODE UNIT, LANTAI, NAMA LOKASI...
            // Data row starts with a number in row[0] and a code in row[1] (e.g. LG-001)
            if (/^\d+$/.test(firstCell) && secondCell.includes('-')) {
                result.cl_units.push({
                    code: secondCell,
                    floor: String(row[2] || ''),
                    location_name: String(row[3] || ''),
                    unit_type: String(row[4] || ''),
                    area_sqm: parseFloat(String(row[5] || '0').replace(/,/g, '')),
                    projection_monthly: parseFloat(String(row[6] || '0').replace(/,/g, '')),
                    status: 'active'
                });
            }
        } else if (currentSection === 'media') {
            // Header is NO, KODE, JENIS MEDIA...
            if (/^\d+$/.test(firstCell) && secondCell.startsWith('Medi-')) {
                result.media.push({
                    code: secondCell,
                    media_type: String(row[2] || ''),
                    location: String(row[3] || ''),
                    point: '', // Point is often joined in location in the template
                    size: String(row[4] || ''),
                    quantity: parseInt(row[5]) || 1,
                    slots: 1, // Default or parsed from other field
                    rate: 0, // Rate is not directly in this table, maybe use proj/qty?
                    pricing_type: 'daily_point', 
                    package_note: '',
                    projection_monthly: parseFloat(String(row[6] || '0').replace(/,/g, '')),
                    status: 'active'
                });
            }
        } else if (currentSection === 'gudang') {
            if (/^\d+$/.test(firstCell) && secondCell.startsWith('Guda-')) {
                result.gudang.push({
                    code: secondCell,
                    location: String(row[2] || ''),
                    name: String(row[3] || ''),
                    area_sqm: parseFloat(String(row[4] || '0').replace(/,/g, '')),
                    monthly_rate: parseFloat(String(row[5] || '0').replace(/,/g, '')),
                    projection_monthly: parseFloat(String(row[6] || '0').replace(/,/g, '')),
                    status: 'active'
                });
            }
        } else if (currentSection === 'pic') {
            if (/^\d+$/.test(secondCell) && String(row[2] || '').trim() !== '') {
                result.pic.push({
                    name: String(row[2] || '').trim(),
                    role_name: String(row[8] || '').trim(),
                    target_share: parseFloat(String(row[9] || '0').replace(/%/g, '')) / 100,
                    status: 'active'
                });
            }
        }
    }

    console.log(JSON.stringify(result, null, 2));
} catch (error) {
    console.error('Error parsing template:', error.message);
    process.exit(1);
}
