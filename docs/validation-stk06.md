# Validation STK-06 — Transferts entre magasins

Recette finale Docker du 19 octobre 2026 : **199 tests PHP / 2 909 assertions et quatre validations indépendantes OpenAPI réussis**. Suite `docker compose run --rm test`, sortie 0, durée PHPUnit 78,559 s.

Recette ciblée `tests/StockTransferTest.php` et `tests/StockTransferConcurrencyTest.php` : **14 tests / 256 assertions réussis**. Les écritures utilisent des schémas PostgreSQL isolés, annulés ou supprimés après chaque scénario.

## Scénarios vérifiés

- REC-07 : départ 5, arrivée sans solde, transfert 3 → départ 2 et arrivée 3. Total conservé à 5, opérateur et date serveur, réponse conforme à `MovementResource`. Un seul mouvement commun aux deux journaux locaux, variations −3/+3 ; un seul événement avec les deux magasins et un audit. Réconciliation sans écart.
- Formulaire sans JavaScript : présélection départ/article, magasins et articles inactifs ou interdits absents, motif "0" conservé, confirmation commune, liens des deux stocks et identifiant partagé. Double envoi : même redirection et aucun mouvement supplémentaire.
- Magasins identiques, source ou destination non autorisée/désactivée, article inactif et USER refusés. L’administrateur peut transférer vers un magasin actif hors affectation du gestionnaire. Les droits sur chaque extrémité restent obligatoires.
- Quantités zéro, négatives, fractions, chaîne JSON, booléen, null et dépassement de borne refusés ; motif vide explicite, espaces seuls, nul binaire ou trop long ; référence/commentaire/auteur/date/bénéficiaire ajoutés, UUID invalide, clé invalide, query sur écriture, Content-Type et CSRF invalides refusés sans changement.
- Source insuffisante et destination au maximum : aucune variation des deux magasins ni des versions, mouvements, audits ou événements. Aucun solde temporaire ne survit à l’échec.
- Inventaires in_progress et pending_validation sur le départ ou l’arrivée : 409 sans écriture. Inventaire démarré après ouverture : formulaire conservé, même clé et motif échappé. Retrait d’affectation et désactivation d’arrivée après ouverture : envoi refusé.
- Rejeu identique après désactivation de l’article et démarrage d’inventaire : résultat initial sans nouvelle écriture. Changement de quantité/motif avec la même clé : conflit. Droits retirés sur l’arrivée : rejeu refusé.
- Confirmation exigeant lecture des deux magasins ; gestionnaire avec accès uniquement au départ : 404, aucun nom ni identifiant distant ajouté à son historique local. USER refusé. Administrateur lisant après désactivation d’un magasin : confirmation disponible sans action de correction. Identifiant invalide, absent ou mouvement d’un autre type : refus.
- Correction STK-05 et annulation d’un transfert : anciens reçus signalés, lien vers remplacement autorisé, soldes restaurés et journal réconcilié.
- Contexte magasin repris ; moins de deux magasins autorisés ou aucun article actif : état explicite et bouton désactivé. Présélections strictes. Erreur de quantité, tableaux, texte non UTF-8 et CSRF forgé dans le formulaire : refus, valeurs et clé conservées pour la saisie corrigeable.
- Panne injectée dans l’événement après modification des soldes et insertion du mouvement : 500 et annulation intégrale, sans transfert partiel. Visiteurs anonymes refusés selon le contrôle CSRF/session ; formulaire et confirmation redirigés vers connexion.
- Deux vrais processus : course sur les trois dernières unités (201/409), même transfert rejoué simultanément (201/201 et même résultat), contenus différents avec même clé (201/409), transferts opposés simultanés (201/201 sans interblocage ni perte d’unités). Audits et événements cohérents, réconciliation sans écart.

Le conflit d’adresse initial entre `/stocks/transferts/{id}` et les fiches `/stocks/{storeId}/{articleId}` est corrigé par la priorité de la route de confirmation et couvert dans la recette. Les erreurs de panne injectée, des cas négatifs et des migrations protégées sont attendues.

## Interface et exploitation

Navigation dans le navigateur local depuis Stocks vers Transférer du stock. Contrôle ordinateur et mobile 390 × 844 : document et viewport mesurés à 390 pixels, aucun débordement horizontal, tous les champs et le bouton accessibles en bas de page. Taille normale rétablie. Capture complète : `/tmp/gestion-stock-stk06.jpg`.

La base locale n’a qu’un magasin actif : l’état indiquant deux magasins nécessaires est vérifié sans créer de faux magasin. Les envois valides et confirmations sont testés dans les schémas isolés. Aucun transfert ni autre mouvement de démonstration ajouté aux données locales réelles. Aucune nouvelle migration après STK-05, huit migrations conservées ; images app/test/contract-test construites et services actualisés.

Contrat : **60 opérations / 91 schémas ; 37 disponibles / 23 planifiées**. `createTransfer` disponible ; Location désigne la confirmation HTML, pas un détail d’API encore planifié. Le journal API local conserve sa projection sans détails distants. [Guide](transferts-stock.md).

Livraison pour revue : [PR #67](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/67), branche `codex/stk-06-stock-transfers`, basée sur main après intégration de la PR #66.
