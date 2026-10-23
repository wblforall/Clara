import zipfile, re
from xml.etree import ElementTree as ET
NS='{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
NSR='{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
class Buku:
    def __init__(self, path):
        self.z = zipfile.ZipFile(path)
        rels = ET.fromstring(self.z.read('xl/_rels/workbook.xml.rels'))
        self.rel = {r.get('Id'): r.get('Target') for r in rels}
        wb = ET.fromstring(self.z.read('xl/workbook.xml'))
        self.sheets = [(s.get('name'), self.rel[s.get(NSR+'id')]) for s in wb.iter(NS+'sheet')]
        self.sst = []
        try:
            sst = ET.fromstring(self.z.read('xl/sharedStrings.xml'))
            for si in sst.iter(NS+'si'):
                self.sst.append(''.join(t.text or '' for t in si.iter(NS+'t')))
        except KeyError: pass
    def baris(self, nama):
        tgt = dict(self.sheets)[nama]
        if not tgt.startswith('xl/'): tgt = 'xl/' + tgt.lstrip('/')
        root = ET.fromstring(self.z.read(tgt))
        for row in root.iter(NS+'row'):
            sel = {}
            for c in row.iter(NS+'c'):
                ref = c.get('r'); col = re.match(r'[A-Z]+', ref).group(0)
                t = c.get('t'); v = c.find(NS+'v'); isel = c.find(NS+'is')
                if t == 's' and v is not None: val = self.sst[int(v.text)]
                elif t == 'inlineStr' and isel is not None: val = ''.join(x.text or '' for x in isel.iter(NS+'t'))
                elif v is not None: val = v.text
                else: val = None
                if val not in (None, ''): sel[col] = val
            yield int(row.get('r')), sel
