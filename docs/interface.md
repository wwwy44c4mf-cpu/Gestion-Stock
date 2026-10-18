# Interface commune — SOC-04

L’interface est rendue par Symfony/Twig, en français. Le CSS et Stimulus 3.2.2 sont servis localement depuis `public/assets` : aucune requête CDN, police distante ou compilation Node n’est requise à l’exécution. La distribution ESM officielle de `@hotwired/stimulus` et sa licence MIT sont conservées dans `public/assets/vendor`.

## Navigation et raccordement à AUTH

`App\Ui\Navigation::items($roles, $storeId)` produit les liens selon des rôles de confiance et le magasin sélectionné. Les administrateurs disposent de neuf rubriques. Les mouvements, inventaires et alertes des gestionnaires exigent un contexte magasin. Les utilisateurs n’ont pas les liens de gestion réservés. La saisie d’inventaire utilisateur reste exclue conformément à l’arbitrage du suivi.

Le subscriber fournit actuellement le contexte anonyme : seul le tableau de bord apparaît et le sélecteur de magasin est désactivé. AUTH-01/03 devra alimenter ce contexte depuis l’identité authentifiée et ses affectations, valider le magasin demandé avant de le conserver en session et fournir les options du sélecteur. Aucun rôle ni magasin fourni par une URL ne devient une autorisation. Masquer un lien ne remplace pas les contrôles serveur.

Les pages `/espace/{section}` sont des états d’attente publics sans données ni actions métier. Les lots métier remplaceront ces états et appliqueront les contrôles d’accès. Les tirets des indicateurs signifient « donnée non disponible », jamais un stock nul calculé.

## Composants Twig

Inclure les composants avec `only` pour rendre leurs entrées explicites ; l’échappement Twig reste activé.

| Composant | Entrées |
|---|---|
| `components/field.html.twig` | `id`, `label` ; options `name`, `type`, `value`, `required`, `hint`, `error` |
| `components/errors.html.twig` | `errors` : liste de `{field, message}`, `id` facultatif et unique |
| `components/notice.html.twig` | `message`, `kind` (`error` pour un message d’erreur) |
| `components/table.html.twig` | `caption`, `columns`, `rows` ; `empty_message` facultatif |
| `components/pagination.html.twig` | `route`, `parameters` (filtres et tri conservés), `page`, `pages` |
| `components/confirmation.html.twig` | `id` unique, `label`, `title`, `message` |

La pagination reçoit des nombres déjà bornés côté serveur. Le tableau propose un intitulé, des en-têtes et une zone défilante accessible au clavier. Le résumé d’erreurs renvoie vers les champs ; les champs associent leurs erreurs via `aria-describedby` et `aria-invalid`.

La confirmation doit être incluse **dans le formulaire POST protégé**, après les champs cachés et le jeton CSRF. Le bouton initial ouvre un `dialog` natif, avec focus sur Annuler ; Échap annule, la tabulation reste dans la boîte et Confirmer soumet le formulaire parent. Ce composant ne fournit ni validation, ni autorisation, ni contrôle CSRF. Les futurs contrôleurs restent responsables de ces garanties.

## Accessibilité et adaptation

Lien d’évitement, repères sémantiques, page active, focus contrasté, libellés explicites, erreurs textuelles et cibles de contrôle d’au moins 44 px. Sous 760 px : menu repliable, cartes sur une colonne. Sans JavaScript, les liens du menu restent visibles. Stimulus synchronise la visibilité et `aria-expanded` à chaque changement de largeur.

Le rendu initial représente le socle : les formulaires métier, la connexion, les statistiques et les opérations ne sont pas livrés dans SOC-04.
