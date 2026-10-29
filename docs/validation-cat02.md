# Recette CAT-02 — suppression du catalogue

29 octobre 2026. La suppression logique est disponible localement. L’exploitant a confirmé que la suppression fonctionne dans l’aperçu local. La publication et la mise à jour serveur suivent cette validation ; aucune installation de CAT-02 en production n’est encore confirmée.

- `sh bin/test-qa core` : **457 tests PHP / 5 699 assertions**, aucune erreur ni échec. Quatre validations OpenAPI indépendantes réussies. Recette HTTP métier, cinq sessions concurrentes et 920 requêtes mesurées réussies ; aucune divergence du rapprochement de stock et aucun solde négatif.
- Les dix tests spécifiques de suppression couvrent les rôles, la saisie directe interdite aux utilisateurs, les CSRF, les versions périmées, l’historique et le stock positif conservés, les références et codes réservés, le non-rétablissement par une catégorie, l’interdiction de modification/proposition après suppression et le retour dans un autre magasin après suppression. Le gestionnaire n’obtient pas le droit de modification administrative.
- Concurrence : suppression simultanée sans double audit ; suppression puis entrée refusée ; entrée puis suppression admise en conservant quantité et mouvement. Deux tests de concurrence ciblés / 47 assertions réussis et inclus dans la suite complète.
- `sh bin/test-backup-recovery` : onze tests de sauvegarde/restauration réussis, avec 21 migrations. Application restaurée vérifiée par connexion et consultation de stock ; conservation et reprise après recréation des conteneurs réussies. Toutes les données et clés de cette recette sont fictives ; aucune restauration de production.
- Navigateur Chrome sur l’aperçu : confirmation lisible sans débordement à 375 et 1 280 pixels ; suppression par le gestionnaire, article absent du catalogue et cinq unités toujours présentes dans le stock ; action absente et requête directe refusée pour un technicien. Un article distinct avec cinq unités est conservé pour la recette humaine.

Les anciennes fixtures ciblées de test reçoivent la même migration additive que l’application, sans modification du SQL historique de la migration initiale. Les tests de sauvegarde attendent désormais 21 versions de migration.

La migration à installer ultérieurement est `DoctrineMigrations\Version20261029010000`. Le retrait du catalogue ne supprime aucune ligne ni quantité ; le retour de schéma est refusé en présence d’articles supprimés pour préserver cet état. L’aperçu ne valide pas à lui seul un navigateur iPhone/Android réel ni le déploiement serveur.
