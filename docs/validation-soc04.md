# Validation SOC-04

Date : 18 octobre 2026, Europe/Paris.

- Construction Docker `app`, `web` et `test` réussie ; services locaux actualisés.
- Suite PHP complète : **38 tests, 589 assertions**, tous réussis, dont quatre tests UI (navigation par rôle et magasin, interface anonyme, neuf états d’attente et 404, échappement des composants et liens de pagination).
- Validation indépendante du contrat : conteneur `contract-test` terminé avec succès lors de la recette.
- `lint:twig templates` : neuf gabarits valides, y compris le résumé d’erreurs.
- `lint:container` : services valides.
- Inspection réelle au navigateur : tableau de bord sur ordinateur (1440 × 1000), vue mobile (390 × 844) ; présentation sur une colonne et focus visible.
- Menu mobile ouvert et refermé avec la barre d’espace : états accessible « expanded » et « collapsed » vérifiés ; Stimulus servi et exécuté localement.

## Limites

L’identité et les affectations ne sont pas encore disponibles : contexte anonyme appliqué, sélection de magasin désactivée. Les règles de présentation sont testées avec des contextes explicites ; leur branchement aux sessions et les droits serveur restent dans AUTH-01/03. Les pages métier sont des états d’attente sans données. Les composants de confirmation sont livrés pour les prochains formulaires ; leur parcours complet avec une opération métier sera recetté lors de leur intégration. Cette vérification n’est pas un audit exhaustif de conformité WCAG.

Guide d’intégration : [interface.md](interface.md).
