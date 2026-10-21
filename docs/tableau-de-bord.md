# Tableau de bord — STAT-02

La page d’accueil `/` affiche les indicateurs réels de l’application. Les dates concernent l’activité ; le stock et les alertes reflètent toujours la situation actuelle. Par défaut, la période va du premier jour du mois au jour courant dans le fuseau du compte. Des dates explicitement vides ouvrent la période à tout l’historique. Le magasin choisi dans le contexte est repris, sauf filtre explicite ; « Tout mon périmètre » et la réinitialisation neutralisent ce contexte pour la page.

## Gestionnaires et administrateurs

- Articles actifs : catalogue descriptif commun, limité à la catégorie exacte choisie. Le magasin ne modifie pas ce total ; le lien ouvre la même liste d’articles actifs.
- Ruptures et sous-seuils sont comptés par article et magasin : un même article concerné dans deux magasins compte deux fois, conformément aux lignes de la liste des stocks (choix par défaut annoncé, ajustable selon la réponse utilisateur).
- Ruptures : stocks à zéro, y compris articles sans mouvement et articles désactivés, conformément à la liste des stocks.
- Seuil bas atteint : quantité inférieure ou égale au seuil bas configuré pour l’article et le magasin. Absence de seuil : aucune classification sous seuil. Une rupture peut appartenir aux deux indicateurs.
- Mouvements de période : tous les événements visibles du journal général, y compris annulations et remplacements. Chaque événement de transfert compte une seule fois, même quand les deux magasins sont autorisés. Les transferts partiellement visibles suivent les mêmes règles que le journal.
- Consommations et retours : quantités valides séparées, à leur propre date ; annulations et anciennes lignes remplacées exclues. Un retour peut provenir d’une consommation plus ancienne. Quantités exactes en chaînes, unité unique `unit` ; aucun calcul en virgule flottante.
- Alertes actives : nombres distincts de gravité critique et stock bas, pour le périmètre magasin et la catégorie, indépendamment des dates d’activité. Les liens conservent ces filtres et ouvrent les alertes en cours correspondantes.

Les gestionnaires consultent uniquement leurs magasins actifs affectés. Les administrateurs peuvent aussi consulter les magasins désactivés. Les catégories sont exactes, sans inclure automatiquement les sous-catégories. Les indicateurs et leurs liens conservent les filtres applicables ; les dates ne sont pas transmises aux listes de stocks ou d’alertes actuelles.

## Utilisateurs

La page devient « Mon tableau de bord ». Elle présente uniquement les quantités consommées et rendues, leurs nombres d’opérations et les liens personnels vers les statistiques et l’historique. Aucune quantité de stock, alerte ou donnée du journal général n’est exposée par cette projection.

Les consommations personnelles restent accessibles après retrait d’affectation à un magasin actif, sans donner accès au stock. Le bénéficiaire détermine le caractère personnel : saisir une consommation pour autrui ne l’intègre pas à ses propres indicateurs. Un magasin désactivé reste réservé aux administrateurs.

## API et cohérence

`GET /api/v1/dashboard`, réservé aux administrateurs et gestionnaires, accepte `date_from`, `date_to`, `store_id` et `category_id`. Sans dates, les bornes sont ouvertes. La réponse `data` contient `active_articles`, `out_of_stock_references`, `below_threshold_references`, `movements_on_period`, `low_alerts`, `critical_alerts` et `consumption` (mêmes totaux que les statistiques).

Les utilisateurs conservent l’API personnelle `/api/v1/statistics/consumptions`. L’API de gestion `/dashboard` leur retourne 403.

Les agrégats du tableau de bord sont calculés dans une seule instruction SQL, avec les droits avant les sommes et comptages. Aucun calendrier ni classement complet n’est matérialisé : de grandes périodes ne provoquent pas la limite graphique de 10 000 périodes. Les consultations n’écrivent ni stock, ni mouvement, ni audit métier et n’envoient aucun courriel. Aucune migration n’est ajoutée.

Les listes de mouvements et d’alertes acceptent désormais la catégorie actuelle exacte pour préserver les liens depuis les indicateurs. Les filtres inconnus, imbriqués et dates invalides sont refusés par le contrat publié. Les totaux et liens décrivent un instant de lecture : un nouveau mouvement entre deux consultations peut naturellement modifier la liste.

[Issue #32](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/32) · [Statistiques](statistiques-consommation.md).
