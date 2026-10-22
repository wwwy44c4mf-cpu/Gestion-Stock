# Contribuer et développer avec Docker

Le code du projet est propriétaire, tous droits réservés : obtenir l’accord du propriétaire pour y contribuer ou diffuser du code. Le dépôt GitHub est actuellement public ; ce régime de licence ne modifie pas sa visibilité. Ne pas inclure de données réelles dans les tests ni dans les captures.

## Préparer le poste

Suivre [le premier démarrage](README.md#premier-démarrage), puis [la démonstration isolée](docs/demonstration.md) si nécessaire. PHP et Composer restent dans les conteneurs. Le code est copié dans l’image : après une modification, reconstruire l’image correspondante ; un redémarrage seul ne recharge pas le code.

```sh
docker compose --profile test build app test web contract-test
docker compose up -d --wait
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm --no-deps test composer validate
```

Les tests de recette utilisent des bases/secrets temporaires, pas les données de travail :

```sh
sh bin/test-qa core
sh bin/test-installation
```

Le banc QA demande aussi un shell POSIX et Python 3 sur l’hôte ; les dépendances de l’application et d’OpenAPI restent conteneurisées. `sh bin/test-qa full` ajoute sauvegarde, HTTPS et incidents. Les commandes et leurs limites figurent dans [la recette](docs/recette-qa01.md). Les tests privilégiés RGPD exigent uniquement un secret administrateur de test jetable créé par ce banc ; ne jamais leur monter celui de la base de travail.

## Modifier une fonctionnalité

1. Partir de l’issue et des décisions de [suivi.md](suivi.md). Une PR doit indiquer ses dépendances encore ouvertes.
2. Contrôler les droits côté serveur et dans les agrégats avant pagination. Préserver quantités exactes, gel d’inventaire, écritures atomiques, rejeu et audit ; passer par les moteurs métier.
3. Ajouter une migration explicite si le schéma change. Ne pas remplacer les migrations déjà utilisées, ni modifier un mouvement historique directement.
4. Adapter le contrat [OpenAPI](docs/openapi.json), le guide concerné et les tests pertinents (droits, cas limites, concurrence si nécessaire).
5. Exécuter la recette affectée ; joindre son résultat, les limites et les étapes manuelles à la PR. La CI n’effectue aucun déploiement.

Aucun secret dans le code, une commande, les journaux de CI ou une issue. Le changement d’une dépendance ou d’une image demande un verrou actualisé et un audit ; ne pas supprimer les alertes pour rendre un rapport vert. Ne pas lancer `down --volumes` sur une installation contenant des données utiles. Un retour arrière de schéma peut être refusé pour préserver l’historique : utiliser la procédure de restauration validée.
