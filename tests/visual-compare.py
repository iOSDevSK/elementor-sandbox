import os, sys, numpy as np
# Real page vs its workspace preview, full page, 1440 and 390 px; prints the share of
# differing pixels. Expected: < 1 % (forms: a bit more, their submit buttons are dimmed).
#   python3 tests/visual-compare.py <public id>   (H2E_VERIFY = the skill's scripts/verify, for FREEZE_JS)
from PIL import Image
from playwright.sync_api import sync_playwright
sys.path.insert(0, os.environ.get('H2E_VERIFY', '../html2wp-to-elementor/skills/html2wp-to-elementor/scripts/verify'))
import visual
B = os.environ.get('EDS_URL', 'http://localhost:58440'); pub = sys.argv[1]
paths = ['/', '/about/', '/insights/', '/contact/', '/services/', '/case-studies/', '/links/', '/coming-soon/', '/privacy-policy/', '/terms-conditions/', '/form-submitted/', '/content-checklist/', '/the-course/', '/nope-404/']
def shot(p, url, w):
    pg = p.new_page(viewport={'width': w, 'height': 900})
    pg.goto(url, wait_until='networkidle')
    pg.add_style_tag(content=visual.FREEZE_CSS) if hasattr(visual, 'FREEZE_CSS') else None
    try: pg.evaluate(visual.FREEZE_JS)
    except Exception as e: pass
    pg.wait_for_timeout(600)
    f = f'v_{w}_{abs(hash(url))}.png'; pg.screenshot(path=f, full_page=True); pg.close(); return f
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for w in (1440, 390):
        for path in paths:
            a = np.asarray(Image.open(shot(br, B + path, w)).convert('RGB')).astype(int)
            b = np.asarray(Image.open(shot(br, B + '/preview' + pub + path, w)).convert('RGB')).astype(int)
            h = min(a.shape[0], b.shape[0])
            d = (np.abs(a[:h] - b[:h]).max(axis=2) > 24).mean() * 100
            print(f'{w:5} {path:22} real_h={a.shape[0]:6} prev_h={b.shape[0]:6} diff={d:5.2f}%')
