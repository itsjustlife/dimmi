# dimmi

This repository hosts personal notes and a small web interface for editing them.

## DIMMI CLOUD

The `CLOUD/index.php` script provides a standalone PHP editor named **DIMMI CLOUD**.
It requires no dependencies beyond standard PHP and confines access to the
repository root. Adjust `$USER` and `$PASS` at the top of the file to configure
login. The jail path is automatically set to the repository root.

To run it locally:

```
php -S localhost:8000 -t CLOUD
```

Then visit `http://localhost:8000` in a browser and log in with your
credentials.

Enhancement ideas and follow-up tasks are tracked in
`THINK/APPS/WebEditor/PROPROMPTS`.
