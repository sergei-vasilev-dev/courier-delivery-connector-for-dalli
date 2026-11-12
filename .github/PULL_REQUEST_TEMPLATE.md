## What and why

## Checklist
- [ ] `bin/qa.sh` passes (phpcs, phpstan, phpunit)
- [ ] New behaviour has tests; API assumptions are backed by the Dalli docs or a fixture
- [ ] Strings are translatable (English + text domain); `.pot`/`ru_RU.po` updated if strings changed
- [ ] `CHANGELOG.md` updated for user-visible changes
- [ ] No tokens, real customer data or Dalli logos in the diff
- [ ] Order data goes through WooCommerce CRUD (HPOS safe); endpoints have nonce + capability checks
