# Validation AUTH-03 — 18 octobre 2026

## Résultat

Recette complète depuis les images Docker : **84 tests PHP / 1 186 assertions**, tous réussis. Les quatre validations indépendantes du contrat OpenAPI réussissent également. Les 11 nouveaux tests de contrôle d’accès représentent 185 assertions. Aucune migration supplémentaire ni donnée métier permanente n’est nécessaire.

## Cas vérifiés

- ADMIN global ; MANAGER multi-magasins affectés ; USER magasins affectés et consommations propres.
- Magasin désactivé invisible aux non-administrateurs, y compris accès direct, historique personnel, mouvement entre magasins et agrégats ; écritures bloquées pour tous.
- Listes et totaux filtrés avant pagination, consolidation SQL filtrée avant somme ; aucun total des magasins étrangers.
- Identifiants invalides, inexistants ou étrangers : 404. Rôles interdits : 403. Identité périmée ou falsifiée : refus.
- Retrait d’affectation pris en compte sans faire confiance à la liste conservée dans l’objet utilisateur.
- Lecture personnelle et retour lié à sa consommation possibles hors affectation si l’origine reste active ; aucun accès au stock, aucune consommation nouvelle hors affectation, aucun retour pour autrui pour USER.
- Transfert et annulation exigeant les deux magasins autorisés ; stocks inchangés après chaque refus.
- Bénéficiaire actif hors magasin accepté pour admin/gestionnaire ; projection id/nom uniquement, profil complet interdit au gestionnaire, comptes désactivés ou de développement exclus hors dev.
- Inventaires, alertes, audit et propositions contrôlés par leur magasin/propriétaire réellement enregistré. Audit global réservé à ADMIN.
- Permissions inconnues, mauvais types de cible et cibles absentes refusés par le voter Symfony.
- Formulaire de contexte fonctionnel sans JavaScript, CSRF obligatoire, magasin étranger refusé et choix révoqué automatiquement nettoyé.
- Non-régression : authentification et sessions natives, isolement de l’administrateur de test, administration des comptes, idempotence, migrations et invariants du schéma.

Les tests utilisent des schémas PostgreSQL isolés et annulent leurs données. Les erreurs de migration et d’API volontairement déclenchées par la suite font partie des cas de refus attendus.

## Vérification locale

Images app/web/test/contract-test construites. Application, serveur web, base, worker et scheduler sains après mise à jour. Connexion via le compte de développement et navigation vérifiées dans le navigateur ; état « Aucun magasin accessible » correct lorsque la base locale ne contient pas de magasin. Le choix de magasin avec affectations est vérifié par les tests fonctionnels isolés.

## Limites de la livraison

AUTH-03 livre la politique commune, les scopes de données, les contrôles des écrans disponibles, les annuaires magasins/bénéficiaires et le contexte magasin. Les écrans métier restent en préparation. Les futurs services de stocks, retours, inventaires et statistiques devront appeler cette politique et appliquer ses scopes ; leurs invariants transactionnels et leurs endpoints restent dans les lots STK, INV et STAT.
