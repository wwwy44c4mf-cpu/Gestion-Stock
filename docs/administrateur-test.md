# Administrateur réservé au développement

Identifiants de démonstration (publics, utilisables uniquement avec **APP_ENV=dev**) :

- Courriel : `admin.dev@example.test`
- Mot de passe : `Dev-Admin-2026!`

## Installation locale

Construire les images et appliquer les migrations :

```sh
docker compose --profile test build app test
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
```

Dans le fichier local `.env`, définir `APP_ENV=dev` (le mode par défaut de `.env.example` reste `prod`). Puis :

```sh
docker compose run --rm --no-deps app php bin/console app:dev:create-admin --no-interaction
docker compose up -d --wait
```

Ouvrir [la connexion locale](http://127.0.0.1:8080/connexion). La commande est relançable : elle ne modifie pas un compte existant. Si ce courriel appartient déjà à un compte normal, elle refuse de le remplacer ou de lui donner le rôle administrateur.

## Protection hors développement

Le compte porte le marqueur persistant `users.development_only`. Le provider d’authentification le refuse dans **tout environnement autre que `dev`**, pour une nouvelle connexion comme pour le rafraîchissement d’une session existante. Le marqueur continue de s’appliquer si le courriel est renommé. La commande de création refuse également de s’exécuter hors `dev` avant toute écriture.

Le mot de passe est stocké sous forme de hachage Argon2id. Le compte passe par les mêmes contrôles CSRF, limitation de tentatives, audit et expiration que les autres comptes. Il n’est jamais créé au démarrage ni par une migration. La migration de retour arrière refuse de retirer le marqueur tant qu’un compte de développement existe.

Pour repasser en production, définir `APP_ENV=prod` dans `.env`, puis recréer les services avec `docker compose up -d --wait`. Aucun changement manuel du mot de passe de démonstration n’est nécessaire pour le bloquer : la règle s’appuie sur l’environnement serveur et le marqueur en base, pas sur des paramètres transmis par le navigateur.

La commande `app:user:create-admin` permet encore de créer le premier **véritable** administrateur si seuls des comptes de développement existent. Elle conserve sa saisie masquée et son refus de créer un second compte normal.

## Validation

Recette Docker : **60 tests PHP / 845 assertions**, plus quatre tests indépendants du contrat. Sont couverts : commande refusée hors dev sans écriture, hachage et création idempotente, absence de remplacement d’un compte normal, initialisation possible du premier administrateur normal, refus de connexion et de rafraîchissement hors dev même après renommage, session native de développement rejetée par un noyau de production, protection du retour arrière de migration.

Depuis AUTH-02, les comptes créés depuis cet administrateur héritent du marqueur de développement. Leurs connexions et sessions sont également refusées hors `dev`.
