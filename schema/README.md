# Vendored app-store `info.xsd`

Vendored from <https://apps.nextcloud.com/schema/apps/info.xsd> so the metadata
CI check is deterministic: fetching the live schema on every run would let an
upstream schema change silently change what the check means.

- sha256: `a8ec208d4be9e507dc28b24e1bb7d8fafa45cbf00ed4b460f1545b131369d362`

To refresh, re-download from the URL above, update the sha256 here, and make
`appinfo/info.xml` validate against the new file in the same change:

```
curl -fsSL -o schema/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
sha256sum schema/info.xsd
xmllint --noout --schema schema/info.xsd appinfo/info.xml
```
