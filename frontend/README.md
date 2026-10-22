# Dépendances du lecteur de codes

Le runtime de production n’a pas besoin de Node. Le lecteur ESM livré dans `public/assets/vendor/zxing.js` est construit depuis `scanner.js`, avec les versions exactes de `package.json` et `pnpm-lock.yaml`. Les vidéos sont décodées localement ; aucun service distant de lecture n’est appelé.

Pour reconstruire avec Node 24 et pnpm 11.25.0 depuis ce répertoire :

```sh
pnpm install --frozen-lockfile --ignore-scripts
pnpm exec esbuild scanner.js --bundle --format=esm --target=es2020 --minify --legal-comments=linked --outfile=../public/assets/vendor/zxing.js
pnpm audit
```

Conserver les licences intégrales de `@zxing/browser` (MIT) et `@zxing/library` (Apache-2.0), de `@zxing/text-encoding` (Unlicense ou Apache-2.0), ainsi que les notices produites par l’assembleur lorsqu’il en émet. Les versions et empreintes des archives sont verrouillées par pnpm ; ne pas remplacer par une URL `latest` ni charger un CDN à l’exécution. Vérifier le résultat de l’audit et refaire les tests après toute mise à jour.

Tests sans accès caméra réel : `node --test tests/scan_camera_test.mjs tests/scanner_controller_test.mjs tests/scanner_decode_fixture_test.mjs` depuis la racine. La recette PHP vérifie les routes et règles métier ; un essai physique sur les smartphones utilisés reste nécessaire pour vérifier netteté, autorisation et lecture des vrais emballages.
