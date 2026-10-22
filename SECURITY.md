# Security policy

## Reporting a vulnerability

Please report security issues privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability**. Do not open a public issue. Include the package version, a description and
steps to reproduce. We aim to acknowledge reports within 3 working days.

## How this SDK handles secrets

- Private keys stay in your process (or your KMS, with `CallbackKey`). Only public keys are sent to PactivaSign.
- Key objects refuse serialization and hide key material from `var_dump` and `print_r`.
- The token cache stores only access tokens, which are bound to your DPoP key and useless without it.
- Exceptions and log entries never contain private keys, client assertions, DPoP proofs or access tokens.
