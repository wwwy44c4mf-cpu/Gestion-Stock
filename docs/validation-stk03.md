# Validation STK-03 — Entrées de stock

Recette Docker du 18 octobre 2026 : **155 tests PHP / 2 290 assertions et quatre validations indépendantes OpenAPI réussis**. Suite complète `docker compose run --rm test`, sortie 0, durée PHPUnit 64,206 s. Recette ciblée `tests/StockEntryTest.php` : neuf tests / 117 assertions.

## Scénarios vérifiés

- REC-03 : création d’un stock de 5 par le moteur, puis entrée API de 10 donnant 15. Opérateur gestionnaire, date serveur, quantité et référence de la réponse contrôlés ; réponse conforme à `MovementResource`, un seul événement et un seul audit pour chaque mouvement ; réconciliation sans écart.
- Même clé et même corps : réponse 201 identique, un seul crédit. Corps différent avec la même clé : 409 sans crédit. Rejeu après archivage d’article : même résultat initial.
- USER refusé côté API et formulaire, bouton absent des stocks. Gestionnaire limité à ses magasins actifs ; magasin non affecté ou désactivé refusé. Administrateur autorisé sur un autre magasin actif, toujours refusé sur un magasin désactivé. Article désactivé refusé.
- Entiers stricts : zéro, négatif, flottant JSON, décimal, chaîne numérique, booléen, null et dépassement de borne refusés. Motif vide ou nul binaire, référence trop longue, identifiant incorrect, auteur/date ajoutés, clé invalide, corps de mauvais type et paramètres d’écriture refusés sans mouvement ni événement.
- Formulaire sans JavaScript : présélection des IDs autorisés, articles archivés et magasins interdits absents, quantité 10 validée et redirection 303 vers stock 15 avec confirmation. Soumission répétée du même formulaire : aucun crédit supplémentaire. Les textes valides `"0"` du motif et de la référence sont conservés.
- Erreur de quantité : valeurs et clé conservées, message explicite et libellés échappés. Inventaire démarré avant envoi : conflit 409, même clé et stock inchangé.
- Champs forgés, tableaux, exposant, zéro initial, quantité négative/hors borne, texte non UTF-8 et CSRF invalide refusés dans le formulaire. Retrait d’affectation après ouverture : envoi refusé sans crédit.
- Magasin d’en-tête repris par défaut ; absence de magasin ou d’article actif expliquée, bouton désactivé ; section Stocks active dans la navigation.
- Visiteur anonyme refusé : CSRF manquant 403, puis avec CSRF valide 401 ; formulaire redirigé vers connexion. Méthode GET de l’API d’écriture refusée 405.

Les tests utilisent un schéma PostgreSQL éphémère dans une transaction annulée après chaque cas. Le reste de la suite (concurrence réelle du moteur, rôles, magasins, catalogue, propositions et migrations) reste vert. Les erreurs des scénarios négatifs du socle et des migrations protégées sont attendues.

## Interface et exploitation

Contrôle navigateur local : connexion de l’administrateur de développement, accès depuis Stocks, ouverture du formulaire et examen des champs. Vérification responsive avec viewport 390 × 844 : largeur utile et largeur du document égales à 375 pixels (barre de défilement exclue), aucun débordement horizontal ; tous les champs et le bouton restent accessibles. Taille normale rétablie. Capture de la page entière : `/tmp/gestion-stock-stk03.jpg`.

Aucune entrée de démonstration soumise dans la base locale réelle pendant le contrôle navigateur. Le formulaire testé en écriture l’est dans les schémas isolés. Les images app/test/web/contract-test ont été construites ; les services locaux ont été actualisés. Aucune migration supplémentaire n’est nécessaire après STK-02.

Le contrat conserve 59 opérations et 89 schémas ; 32 opérations sont disponibles et 27 restent planifiées. L’opération `createEntry` est disponible. La réponse n’annonce pas de Location vers un détail de mouvement encore planifié.

Livraison pour revue : [PR #64](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/64), branche `codex/stk-03-stock-entries`, basée sur main.
