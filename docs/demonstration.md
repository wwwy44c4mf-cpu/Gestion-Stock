# Démonstration sur données fictives

Utiliser une copie séparée du dépôt et un nom Compose distinct. Cette procédure crée une base nouvelle ; elle ne nettoie ni ne remplace une base existante. Aucun chargement automatique au démarrage.

## Installation locale dédiée

Dans cette copie, exécuter la préparation du [README](../README.md#premier-démarrage) puis définir dans son `.env` :

```dotenv
COMPOSE_PROJECT_NAME=gestion-stock-demo
HTTP_PORT=8081
APP_ENV=dev
APP_DEBUG=0
MAIL_DELIVERY_ENABLED=0
APP_PUBLIC_URL=http://localhost:8081
```

Le port 8081 doit être libre. Ne pas reprendre les secrets d’une autre installation. Démarrer puis charger, **avant de créer un administrateur séparément** :

```sh
docker compose up -d --build --wait
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm --no-deps app php bin/console app:dev:load-demo --no-interaction
docker compose exec app php bin/console app:stock:reconcile
```

La commande exige `dev`, les courriels désactivés et une base métier vide. Elle refuse aussi une seconde exécution, sans rien modifier. Le chargement entier est transactionnel. Elle crée cinq comptes marqués `development_only` (nouvelle connexion et ancienne session refusées hors `dev`, même après changement de courriel), deux magasins A/B, trois catégories, vingt articles, quarante entrées et trois consommations. Les soldes, audits et événements sont cohérents. Aucun dossier d’inventaire n’est ouvert ; le démonstrateur garde la main.

[Ouvrir la démo](http://127.0.0.1:8081/connexion). Mot de passe public commun : **`Demo-Stock-2026!`**.

| Compte fictif | Rôle | Magasins |
| --- | --- | --- |
| `admin.demo@example.test` | Administrateur | A et B |
| `gestionnaire-a.demo@example.test` | Gestionnaire | A |
| `gestionnaire-b.demo@example.test` | Gestionnaire | B |
| `technicien-a.demo@example.test` | Utilisateur | A |
| `technicien-b.demo@example.test` | Utilisateur | B |

Ces adresses ne sont pas des boîtes de réception. Les notifications peuvent être préparées mais aucun courriel n’est envoyé. L’administrateur de test historique `admin.dev@example.test` n’est pas créé par cette commande.

## Parcours de présentation (environ 15 minutes)

1. Se connecter en administrateur : ouvrir les stocks. Dans A, `DEMO-001` est à **3**, `DEMO-002` à **0**, `DEMO-003` à **18** ; les autres sont à 20. Dans B, tous sont à 10. Les seuils sont bas=5, critique=0 ; attendre le traitement asynchrone pour l’écran Alertes.
2. Se connecter en technicien A : consommer 1 unité de `DEMO-003` (18→17), vérifier l’historique personnel puis rendre cette unité (17→18). Tenter 19 unités : refus sans changement. Vérifier que B ne figure pas dans ses stocks.
3. Se connecter en gestionnaire A : enregistrer une entrée de 10 unités de `DEMO-001` (3→13), puis corriger cette entrée à 8 avec un motif (13→11). Consulter la traçabilité. Proposer une modification de description d’article sans publication immédiate.
4. En administrateur : approuver la proposition. Transférer 2 unités de `DEMO-003` de A vers B (18→16 et 10→12). Revenir avec le gestionnaire A pour constater le masquage du côté extérieur dans le journal.
5. En gestionnaire A : préparer et démarrer un inventaire. Constater le blocage d’une consommation dans A. Saisir les vingt lignes, avec `DEMO-003` compté à 15 au lieu de 16, puis soumettre et valider. Le solde devient 15 ; le mouvement d’écart et le journal sont conservés.
6. En administrateur : consulter le tableau de bord, les alertes, les statistiques et l’audit. Montrer la configuration des destinataires et les procédures de reprise sans envoyer de message réel ni lancer d’effacement.

Si le public modifie d’autres quantités, adapter les résultats attendus. Pour recommencer de zéro, utiliser une autre installation dédiée ; ne pas effacer de volumes utiles. Le contrôle automatisé de cette installation est `sh bin/test-installation` ; la recette complète des fonctionnalités reste [QA-01](recette-qa01.md).
