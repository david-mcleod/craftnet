# Craftnet

Craftnet is the Craft project that powers various services for [Craft CMS](https://craftcms.com), including [id.craftcms.com](https://id.craftcms.com), [plugins.craftcms.com](https://plugins.craftcms.com), and the [Craftnet API](https://docs.api.craftcms.com).

It is not meant to be self-installable. We’ve published it to a public repo for the [issue tracking](https://github.com/pixelandtonic/craftnet/issues) and because we hope some aspects of the code will serve as helpful examples for other advanced Craft projects.

## Development

- [Craft ID Resources](web/craftnetresources/id/README.md)
- [Craft Plugin Store Resources](web/craftnetresources/plugins/README.md)

### Local Development with DDEV

#### Prerequisites

- [Install DDEV](https://ddev.readthedocs.io/en/stable/)
- A database backup, placed in `./storage/backups/craftnet.dump`

#### Setup

```sh
cp .env.example .env
ddev start
ddev exec composer install
ddev exec php craft setup/security-key
ddev exec pg_restore --dbname=db --single-transaction --no-owner < storage/backups/craftnet.dump
```

#### Examples

```sh
# View URLs and other relevant info
ddev describe

# Run craft CLI commands
ddev exec php craft

# Login to the CP
open https://id.craftnet.ddev.site/negroni
```
