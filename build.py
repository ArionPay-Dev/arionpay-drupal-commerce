"""Build the Drupal Commerce module zip: copies the ArionPay PHP SDK into commerce_arionpay/lib, then zips it.
   python3 build.py  ->  dist/commerce_arionpay-1.0.0.zip  (unzip into web/modules/contrib or web/modules/custom)
   With Composer, the SDK comes from Packagist instead (composer.json requires arionpay/arionpay-php)."""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
MOD = HERE / "commerce_arionpay"
lib = MOD / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "commerce_arionpay-1.0.0.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(MOD):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, "commerce_arionpay/" + str(p.relative_to(MOD)))
print(out, os.path.getsize(out), "bytes")
