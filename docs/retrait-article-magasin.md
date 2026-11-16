# Retirer un article d’un seul magasin

STK-07, issue #130. Maquette validée par l’exploitant ; livraison autorisée, installation en production à confirmer.

Dans Stocks, utiliser « Retirer de ce magasin » sur une ligne à zéro, ou depuis son détail. La confirmation précise le magasin et l’article. Administrateurs et gestionnaires actuellement autorisés uniquement ; refus serveur pour les autres comptes, les magasins non attribués, un inventaire ouvert ou une quantité devenue non nulle depuis l’ouverture du formulaire.

Le retrait masque la référence dans les articles et stocks du magasin, les sélections de sortie/ajustement/transfert et les compteurs de rupture et les nouveaux inventaires. Le catalogue commun, les autres magasins, les mouvements et les statistiques d’activité restent conservés. Le détail et le journal restent accessibles par leurs liens historiques autorisés. Les casages et seuils sont conservés ; les alertes locales sont clôturées et la mention « commandé » est retirée. Les rappels et les courriels d’alerte encore en file ne sont plus envoyés pour la référence retirée.

Une entrée, un retour, un transfert entrant ou une correction rétablissant une quantité positive fait réapparaître l’article automatiquement. Le solde et sa version ne sont pas réécrits lors du retrait ; une trace d’audit est ajoutée, sans mouvement fictif. La répétition de la confirmation ne crée pas une seconde trace.

Migration ajoutée : `Version20261116010000`. Les anciens zéros restent affichés par défaut ; aucun retrait automatique de données existantes. La migration ne modifie pas l’image PostgreSQL. La validation de la maquette et la mise en production ont été autorisées par l’exploitant.

## Validation locale

Recette générale : 518 tests PHP / 6 242 assertions, contrôles OpenAPI, 920 requêtes HTTP ; aucun stock négatif ni écart de rapprochement. Rapport : `var/qa/20261116T175127Z-8693`. Les dernières protections d’inventaire et de courriel ont été vérifiées séparément ; contrôle final des permissions : 2 tests / 32 assertions. Parcours mobile en 375 pixels réussi, catalogue et autre magasin conservés, réapparition après entrée vérifiée. Refus 403 d’un technicien vérifié aussi sur la maquette finale.

Exemple local : `DEMO-RETRAIT`, à zéro dans **Blaise Pascal CA — démonstration** et à deux unités dans **Blaise Pascal Conso — démonstration**. Le compte administrateur de démonstration peut tester le retrait depuis Stocks.

## Mise à jour Portainer

Créer une sauvegarde récente et vérifier sa copie hors serveur. Conserver `DATABASE_RELEASE_ID` à `99cbb3b54fa0cb98b43f3f71541a86a5d2322dc3`. Mettre `Repository reference` sur la branche de livraison nommée (avec `refs/heads/`), et `RELEASE_ID` sur son commit exact. Arrêter web, worker et scheduler pendant la migration. Après construction des nouvelles images, exécuter `php bin/console doctrine:migrations:migrate --no-interaction` dans le conteneur applicatif de maintenance, puis relancer la stack et contrôler santé, rapprochement des stocks et retrait local. Une erreur initialize signalant une migration en attente ne signifie pas qu’elle est exécutée.
