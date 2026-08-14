# Certificado de PRUEBA

`certificado-prueba.pem` es el certificado de pruebas de Greenter (autofirmado,
RUC 20210441213, vigente hasta 2039). Sirve **solo** para el entorno beta de
SUNAT.

**Nunca** se usa en producción: ahí va el certificado real de saraih, que vive
fuera de `wp-content` en el servidor y **no** está en este repositorio.

Este archivo está exceptuado en `.gitignore` a propósito: la regla `*.pem`
protege los certificados reales, pero este tiene que viajar con el código
porque Git Updater no ejecuta `composer install`.
