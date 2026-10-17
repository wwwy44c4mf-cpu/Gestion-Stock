# Vérification de SOC-01

Date : 18 octobre 2026 (Europe/Paris). Exécution locale Docker Desktop, Linux/arm64. Les images de base verrouillées proposent aussi amd64 ; cette architecture n’a pas été exécutée pendant cette recette.

## Résultats

| Contrôle | Résultat |
|---|---|
| Configuration Compose | Valide |
| Construction multi-étapes | Application, web, PostgreSQL et cible de test construites |
| Démarrage | Les cinq services permanents sont sains ; `init` se termine avec le code 0 |
| Accès depuis l’hôte | `/` et `/health/ready` répondent sur 127.0.0.1:8080 |
| PHPUnit | 5 tests réussis, 15 assertions ; PHP 8.4.26, PHPUnit 12.5.40 |
| Injection de services | `lint:container` réussi |
| Configuration Symfony | `lint:yaml config` réussi, 5 fichiers YAML |
| Dépendances PHP | `composer audit` ne signale aucune vulnérabilité connue |
| Compte PostgreSQL applicatif | Non-superutilisateur, sans création de base ni de rôle |
| Initialisation vierge | Compte applicatif et transport Messenger créés sur volume séparé ; environnement de contrôle arrêté ensuite |
| Persistance | Sonde en queue dédiée conservée après `down` et `up`, puis supprimée par le vérificateur |
| Panne PostgreSQL | HTTP 503 sans détail sensible ; retour à `status: ok` après reprise |
| Planificateur | Sonde technique exécutée ; récapitulatif métier réservé à MAIL-03 |
| Réseau | Seul web publié sur loopback ; PostgreSQL et PHP-FPM restent privés |

Les tests fonctionnels contrôlent le rendu français, la connexion réelle à PostgreSQL, la réponse minimale de liveness, une route inconnue et la persistance/acquittement d’un message asynchrone. La queue de test est distincte de la queue métier.

## Audit des images

Audit Trivy exécuté sur des archives locales avec `--network none`, sans socket Docker monté dans le scanner. La base publique de vulnérabilités a été téléchargée séparément dans un conteneur n’ayant pas accès au projet. Aucun envoi d’image ou de métadonnées du projet vers un service d’analyse externe.

Scanner verrouillé : `aquasec/trivy@sha256:2416d1f74045b57274df1a340eee6b510cacd73c66a6ccb8da092b117eb7d222`.

| Image finale analysée | Identifiant de l’image | Vulnérabilités détectées |
|---|---|---|
| Application | `sha256:eb132ac610e680e89e52de9c2814ed1b8adf003287c6ab376d9f7d1e6150abc5` | 0 |
| Web | `sha256:a42d4a59ecdc8cce0387879431e16b208f03b96e95aae17b496b5e860f7c5fba` | 0 |
| PostgreSQL | `sha256:bf0ebaac01bd04093047e6cb81efc240419ffff207480cda4952df9627bd0531` | 0 |

Les premières images ont été remplacées par des bases Alpine corrigées. Dans PostgreSQL, l’utilitaire `gosu` embarquait un ancien runtime Go ; il a été remplacé par `su-exec` dans l’entrypoint officiel, puis le démarrage vierge et le redémarrage ont été revérifiés. Les compilateurs et dépendances de construction PHP sont supprimés de l’image finale.

Les résultats décrivent la base de vulnérabilités disponible lors de cette recette, pas une garantie permanente. Les mises à jour de paquets système à la construction peuvent modifier l’identifiant d’une image reconstruite ; refaire les contrôles et l’audit avant publication d’une nouvelle image. Les images de base et les dépendances Composer restent verrouillées dans le dépôt.

## Limites et suite

- Avertissement Composer sur l’absence de licence : décision laissée à DOC-01, validation du schéma réussie avec cet avertissement.
- Environnement HTTP local ; domaine, TLS et déploiement production relèvent d’OPS-02.
- Pas encore de schéma de stock, authentification, SMTP métier ou récapitulatif quotidien : ces fonctionnalités restent dans leurs lots respectifs.
- La recette de persistance ne remplace pas une sauvegarde ni un test de restauration, prévus dans OPS-01.
- Le code est livré pour revue ; l’issue reste ouverte jusqu’à intégration.

Commandes de démarrage, tests, diagnostic et vérification de persistance : [README](../README.md).
