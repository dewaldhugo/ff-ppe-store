# Launch checklist

Do these before the store goes live. Nothing here is done by default.

- [ ] Lift noindex: set `STORE_NOINDEX=false` in the production environment. One flag drives every page (`config/store.php`). A placeholder page also carries its own noindex: remove the `:noindex="true"` from `resources/views/pages/placeholder.blade.php` for a page once it has real content, or give the page its own view.
- [ ] Verify every homepage "Shop by standard" chip returns products on real data (the chips are in `config/homepage.php`; the tests only prove it against the fixture catalogue).
- [ ] Confirm the trust strip claims with the client (`config/homepage.php`, `trust`). The fourth slot is for a delivery claim that is not confirmed yet: leave it empty until it is.
- [ ] Confirm `pcntl` and `posix` are present in the server's PHP CLI before starting Horizon (`composer.json` fakes them under `config.platform` for Windows, so Composer will not warn).
- [ ] Replace every TBC in the store identity: name, legal name, company number, registered office, VAT number, phone and email (`STORE_*` in `.env`).
