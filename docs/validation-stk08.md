# Validation STK-08 — Historique des mouvements

Recette Docker du 20 octobre 2026 : **223 tests PHP / 3 251 assertions réussis**, sortie 0, durée PHPUnit 90,399 s. Les **quatre validations indépendantes OpenAPI** réussissent. Recette ciblée : **9 tests / 102 assertions**, sortie 0, durée 2,861 s, dans `MovementDatesTest` et `MovementHistoryTest`.

## Couverture

- Session requise ; refus USER ; gestionnaire sans affectation : liste vide et détail interdit. Filtre sur magasin hors périmètre : 404 ; ressources absentes, identifiants invalides et paramètres inconnus contrôlés.
- Administrateur : tous les magasins, y compris archivés. Gestionnaire : magasins actifs affectés. Les magasins désactivés restent réservés aux administrateurs.
- Transfert partiel : magasin extérieur, auteur, bénéficiaire, textes et liens masqués en liste et détail. Filtrer par auteur masqué ne révèle aucune ligne ; options de personnes tirées exclusivement du périmètre détaillé autorisé.
- Combinaison période/article/magasin/auteur/type/bénéficiaire avant total et pagination. Tri par quantité et date ; départage stable par identifiant et pagination sans doublon en cas d’instants égaux.
- Journée locale de Paris du 29 mars 2026 : 23 heures et borne de fin exclusive vérifiées avec des instants à minuit. Test unitaire de la journée de 25 heures du 25 octobre ; plages ouvertes, New York, Tokyo, dates impossibles et périodes inversées.
- Article désactivé conservé dans le journal ; bénéficiaire anonymisé sans profil ni courriel ; textes HTML échappés.
- Entrées, consommations, retours, transferts, ajustements et annulations ; bénéficiaire du retour issu de la consommation liée. Liens immuables d’origine/annulation/remplacement de correction, y compris lorsque le magasin est archivé pour un administrateur.
- Lecture sans création de solde ni mouvement. Les moteurs d’écriture et leurs tests de concurrence existants restent inchangés.
- Non-régression de routage : le détail API exige un identifiant UUID et n’intercepte pas les chemins d’enregistrement tels que `/movements/entries` ; leur méthode GET reste refusée en 405.

Les données de recette sont créées dans des schémas isolés puis annulées. Aucun mouvement de démonstration ajouté aux données locales.

## Interface

Vérification dans le navigateur local avec le compte de développement : accès par le menu Mouvements, trois mouvements existants affichés, filtre Entrée donnant un résultat, détail de cette entrée et lien de correction disponibles. Les heures sont affichées en Europe/Paris.

Recette mobile à 390 × 844 : page sans débordement horizontal global (`scrollWidth = clientWidth = 390`), filtres accessibles, tableau dans son conteneur à défilement horizontal. Taille normale rétablie après contrôle. Aperçu conservé localement dans `/tmp/gestion-stock-stk08.jpg`.

## Contrat et livraison

**60 opérations / 94 schémas : 40 disponibles / 20 planifiées.** `listMovements` et `getMovement` sont disponibles. Les schémas de lecture `HistoryMovement*` portent le masquage sans modifier les réponses d’écriture `MovementResource`. Aucune nouvelle migration ; les huit migrations existantes sont conservées.

Branche : `codex/stk-08-movement-history`. [Guide utilisateur et API](historique-mouvements.md). L’historique personnel USER reste dans CON-01.
