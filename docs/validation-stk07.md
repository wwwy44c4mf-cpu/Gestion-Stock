# Validation STK-07 — Retours liés aux consommations

Recette finale Docker du 19 octobre 2026 : **214 tests PHP / 3 149 assertions et quatre validations indépendantes OpenAPI réussis**. Commande `docker compose run --rm test`, sortie 0, durée PHPUnit 92,057 s. Recette ciblée `tests/StockReturnTest.php` et `tests/StockReturnConcurrencyTest.php` : **15 tests / 240 assertions réussis**.

## Scénarios vérifiés

- Consommation 4 sur stock 10 : deux retours successifs de 2 rétablissent 10 ; tout retour supplémentaire refusé. Origine inchangée, mouvement `return` distinct avec auteur/date serveur, destination et article imposés, lien d’origine, référence conservée, réponse conforme à `MovementResource`. Un audit et un événement par retour ; réconciliation sans écart. Les compteurs de la consommation suivent le cumul effectif.
- USER sans affectation restante : sélection de sa consommation, retour accepté et confirmation personnelle accessible ; aucun lien ni droit vers le stock actuel. Consommation d’autrui, magasin extérieur pour un gestionnaire ou gestionnaire sans affectation refusés. ADMIN autorisé hors affectation ; aucun rôle ne peut créditer un magasin désactivé.
- Gestionnaire retournant les fournitures d’un autre bénéficiaire, même devenu inactif ; article désactivé accepté pour une restitution historique. Bénéficiaire et opérateur distincts sur la confirmation.
- Rejeu identique : même résultat sans deuxième crédit ; quantité/commentaire différents avec la même clé : conflit. Rejeu après archivage d’article et démarrage d’un inventaire : résultat initial sans écriture.
- Inventaires in_progress et pending_validation et capacité maximale : erreur sans variation du solde, de sa version, des mouvements, audits ou événements.
- Quantités zéro, négatives, fractionnaires, chaînes JSON, booléens, null et hors borne refusées ; commentaire trop long ou nul binaire ; magasin/article/bénéficiaire/auteur/date ajoutés, consommation invalide ou d’un autre type, clé et CSRF invalides : refus sans écriture.
- Formulaire sans JavaScript depuis la sélection : quantité et commentaire "0", confirmation HTML réelle dans Location et redirection 303, double soumission sans autre crédit. Erreurs de quantité conservent valeurs et clé, textes échappés ; tableaux, texte non UTF-8, champs forgés et CSRF refusés. Quantité devenue excessive à la suite d’un autre retour : 409.
- Consommation annulée ou corrigée : aucun retour nouveau sur l’ancienne origine, formulaire désactivé et lien vers le remplacement autorisé. Retour corrigé puis annulé : seuls les retours effectifs réduisent le reste à rendre ; leurs anciennes confirmations signalent leur état.
- Sélection filtrée par droits avant le total et la pagination : 27 consommations propres et une étrangère, puis un retour total, laissent 26 consommations visibles (25 puis 1). Origine entièrement rendue et étrangère exclues ; paramètres de pagination invalides refusés ; absence de résultats expliquée.
- Confirmation de retour : propriétaire ou gestionnaire autorisé seulement ; utilisateur tiers et gestionnaire extérieur refusés. Après désactivation du magasin, ADMIN conserve la lecture sans correction possible ; USER refusé. Identifiant invalide, absent ou consommation à la place d’un retour : refus.
- Panne injectée sur l’événement après écriture du solde et du mouvement : 500 et annulation intégrale. Visiteur anonyme refusé par CSRF/session, sélection redirigée vers connexion.
- Deux vrais processus : retours concurrents dépassant ensemble la consommation (201/409), même retour rejoué (201/201, même résultat), contenus différents sous la même clé (201/409), retour contre correction de consommation, retour contre correction d’un retour existant et retour contre annulation de retour. Aucun dépassement du cumul, aucun interblocage, événements et audits cohérents et réconciliation sans écart.

Les écritures sont exécutées dans des schémas PostgreSQL isolés, annulés ou supprimés en fin de scénario. Les tests existants restent inclus, notamment les corrections et transferts. Les erreurs des scénarios négatifs, de la panne injectée et des migrations protégées sont attendues.

## Interface et exploitation

Contrôle navigateur local : Consommations → Rendre des articles, sélection de la consommation réelle existante puis ouverture du formulaire. Article et magasin imposés, quantités retirée/rendue/restante visibles, commentaire facultatif et bouton explicite. Contrôle ordinateur et mobile 390 × 844 : largeur document et largeur utile égales à 390 pixels, aucun débordement horizontal, champs et bouton accessibles en bas de page. Taille normale rétablie ; capture `/tmp/gestion-stock-stk07.jpg`.

Aucun retour de démonstration ni aucune autre écriture de stock soumise sur la base locale réelle pendant ce contrôle. Avant/après : mêmes trois mouvements (entrée 10, consommation 2 et ajustement +5), même solde 13 et version 3 ; réconciliation sans écart. Les envois valides et confirmations sont testés dans les schémas isolés. Aucune migration nouvelle après STK-05, huit migrations conservées ; cinq services sains après actualisation.

Contrat : **60 opérations / 91 schémas ; 38 disponibles / 22 planifiées**. `createReturn` est disponible ; Location désigne une confirmation HTML autorisée. Le journal conserve les retours comme opérations distinctes. L’historique personnel complet et les statistiques restent dans CON-01/STAT-01 ; cette livraison fournit les origines éligibles, les compteurs et les confirmations.

Livraison pour revue : [PR #68](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/68), branche `codex/stk-07-consumption-returns`, basée sur main après intégration de la PR #67. [Guide](retours-stock.md).
