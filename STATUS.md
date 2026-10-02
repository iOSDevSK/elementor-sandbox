# Elementor Demo Sandbox — stav práce

Stav k 2. 10. 2026. Pokračujeme neskôr.

## Hotové: Fáza 2

Overené na lokálnom testovacom webe (`h2e-rt-sb`, http://localhost:58440, WordPress 7.1.2, Elementor 4.3.3, téma claire-hayes-elementor, Bridge for Elementor).

- Prihlásenie demo/demo vytvorí vlastnú kópiu webu. Kópia obsahuje 14 stránok, hlavičku a pätu (šablóny), kit so 346 globálnymi triedami a nastavenia Design details z bridge.
- V Elementor editore sa dajú upraviť stránky, hlavička aj globálne triedy. Zmeny vidí iba táto kópia, aj keď demo otvorí bežné adresy webu.
- Verejný náhľad `/preview<id>/` sa neindexuje, formuláre sú vypnuté a page cache (Cache Enabler a iné) ho neukladá. Vizuálne sedí so skutočným webom: pri 1440 a 390 px je rozdiel do 1 %. Stránky s formulárom majú rozdiel o niečo väčší, lebo tlačidlá odoslania sú zámerne stlmené.
- „Začať odznova“ aj vypršanie po 1 hodine kópiu úplne zmažú, vrátane nahraných obrázkov a jej CSS priečinka. Vypršaný odkaz vráti 410.
- Skutočný web je po celom cykle bajt po bajte rovnaký: príspevky, metadáta, nastavenia, používatelia aj CSS súbory Elementora (`tests/fingerprint.sh`).
- Zablokované sú všetky exporty a sťahovania návrhov: export WordPressu, export šablón, kitu a import-export v Elementore, Elementor Tools a system info. Zablokované sú aj nastavenia témy a pluginov a zoznam používateľov. Surové dáta stránok a šablón sa cez API prečítať nedajú.
- Nahrávať sa dajú iba obrázky (max. 4 MB). Patria kópii a zmažú sa s ňou. SVG a JSON sú odmietnuté.

Verejné CSS, JS a fonty témy si môže stiahnuť ktokoľvek, ako na každom webe. Blokuje sa stiahnutie témy ako balíka.

## Zostáva (odhad ~1,5–2 dni)

1. **Blogové články a menu v demo kópii** (približne 1 deň)
   - Články klonovať do vlastného typu príspevku. Bridge potrebuje filter `h2e_post_types` pre výpisy článkov a pre zobrazenie jedného článku.
   - Menu ako tieňové menu v kópii. Väzby menu v bridge (`h2e_menu_bindings`, `h2e_menu_ids`) preložiť na kópie.
2. **Odpočet a ping aktivity** v editore (`assets/demo.js`: hodiny do vypršania, `eds/v1/activity`), `uninstall.php` (zmaže všetky kópie, tabuľky a demo rolu) a readme. Približne pol dňa.
3. **Nasadenie na claire.designready.studio** (približne pol dňa)
   - Starý Visual Edit sandbox (`visual-edit-demo`) vypnúť; demo používateľa nový plugin prevezme.
   - Na živom webe overiť, že sa náhľady nekešujú (dvakrát načítať, žiadna hlavička Cache Enablera) a že mazanie kópií nezasiahne CSS živého webu (`tests/fingerprint.sh` pred a po, s `EDS_CONTAINER` živého kontajnera).
   - Limit 5 spustení za hodinu na IP počíta skutočnú IP návštevníka za Cloudflare a Traefikom (`EDS_Store::client_ip()`).

Drobnosť: počet šablón „Section“ v zozname Templates započíta aj demo kópie, kým nejaká existuje. Samotné kópie sú skryté.

## Ako testovať

```bash
tests/fingerprint.sh > before.txt            # skutočný web pred demo sedením
tests/demo-login.sh                           # demo prihlásenie, vytvorí kópiu
# … úpravy v editore (Playwright), náhľad …
tests/fingerprint.sh > after.txt && diff before.txt after.txt   # musí byť prázdne (okrem počtov termov knižnice)
python3 tests/visual-compare.py <public id>   # skutočná stránka vs. náhľad
```

Pred porovnaním si web raz načítaj anonymne. Pri prvom zobrazení si Elementor vytvorí vlastné CSS a cache príznaky a tie do porovnania nepatria. Voľba `_elementor_design_system_sync_css_meta` sa mení pri každom zobrazení skutočného webu, preto ju fingerprint vynecháva.

## Čo sa pri testovaní ukázalo (a je opravené)

- Kópia kitu sa uložila s typom „page“, takže pre Elementor to nebol kit a skopírované triedy sa k nemu nenaviazali.
- Elementor si drží cache príznaky a meta CSS súborov ako voľby celého webu (`elementor_atomic_cache_validity__*`, `elementor-custom-breakpoints-files`, meta design-system-sync). Kópia ich teraz má vlastné. Inak skutočný web odkazoval na CSS súbor, ktorý nikdy nevytvoril.
- Mazanie kópie (kit, triedy) bez vlastného rozsahu súborov zmazalo CSS súbory skutočného webu. HTML v page cache by potom odkazovalo na chýbajúce súbory.
- Kópie stránok sa dali otvoriť priamo na skutočnej doméne (`?post_type=eds_page&p=…`). Mimo svojej kópie teraz vracajú 404.
- CSP `sandbox` dával náhľadu „nulový“ pôvod, takže fonty témy padali na CORS.
- „Začať odznova“ nefungovalo, lebo ochrana administrácie presmerovala `admin-post.php`.
