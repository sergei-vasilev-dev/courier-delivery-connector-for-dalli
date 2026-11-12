# Security policy

## Supported versions

Security fixes are provided for the latest minor release.

## Reporting a vulnerability

**Please do not open a public issue.** Report privately with GitHub's
[private vulnerability reporting](https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/security/advisories/new)
(repository *Security* tab → *Report a vulnerability*), or by e-mail: yurich.dev@gmail.com.

Include: affected version, WordPress/WooCommerce/PHP versions, steps to reproduce and the impact. **Never include a real Dalli token or real customer data.**
If you accidentally exposed a token, revoke it in your Dalli personal account immediately.

You can expect an acknowledgement within a few days and a fix or a mitigation plan as soon as it is reasonably possible; coordinated disclosure is appreciated.

## Scope notes

The plugin talks only to the Dalli API from the server, stores the API token as an option (or reads it from `wp-config.php`), never prints it back in full and redacts it from logs.
Vulnerabilities in the Dalli service itself should be reported to Dalli.
