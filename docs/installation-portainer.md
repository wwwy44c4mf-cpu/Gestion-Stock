# Installation Portainer / Home Assistant OS — OPS-04

Cette variante prépare une installation neuve sur Docker Standalone géré par Portainer. Elle réutilise le module Cloudflared existant, dont le réseau et l’adresse sont relevés dans **Containers → Cloudflared → Connected networks**. Elle ne crée pas de tunnel, n’ouvre aucun port hôte et n’installe rien dans Home Assistant Core. L’accès réel au serveur et le DNS ne sont pas validés par la recette locale.

**Limite de support Home Assistant OS :** Home Assistant classe les conteneurs autonomes extérieurs à son écosystème comme logiciels non pris en charge. Certains logiciels peuvent aussi interférer avec le Supervisor. Cette stack est une variante Docker testée, pas une application Home Assistant officiellement gérée par le Supervisor. Voir [la règle officielle](https://www.home-assistant.io/more-info/unsupported/software/). L’exploitant doit connaître cette limite avant de choisir ce mode ; un hôte Docker séparé permet de garder la stack hors du système Home Assistant OS. Ne pas contourner les contrôles de santé du Supervisor.

## Préparation dans Portainer

Vérifier l’espace disque et la mémoire disponibles pendant le fonctionnement normal de Home Assistant. Les sept services permanents, console de maintenance comprise, ont des plafonds de mémoire d’environ 2,4 Gio cumulés ; ces plafonds ne comprennent pas la construction des images. Surveiller la première construction et les services domotiques. Les quatre images sont construites sur l’architecture native du serveur depuis le dépôt public ; aucun registre privé ni jeton GitHub n’est nécessaire.

Dans l’environnement local **primary**, créer une stack **gestion-stock** depuis **Git Repository**, avec le dépôt `https://github.com/wwwy44c4mf-cpu/Gestion-Stock`, une référence figée correspondant à une version validée et **Compose path : `compose.portainer.yaml`**. Aucun fichier Compose supplémentaire. Désactiver les mises à jour Git automatiques : une mise à jour avec migration exige sauvegarde et maintenance explicites.

Renseigner les variables suivantes dans Portainer. Les exemples sont fictifs ; remplacer domaine et IP par les valeurs de l’exploitant. Ne pas mettre de mot de passe ou de jeton Cloudflare dans ces variables.

| Variable | Valeur / rôle |
| --- | --- |
| `COMPOSE_PROJECT_NAME` | `gestion-stock`, identique au nom de stack |
| `RELEASE_ID` | Commit immuable du code validé ; ne pas réutiliser pour un autre contenu |
| `DATABASE_RELEASE_ID` | Même commit pour la première installation ; conserver ensuite tant que l’image PostgreSQL n’est pas mise à jour séparément |
| `APP_DOMAIN` | Par exemple `stock.example.com`, sans schéma ni chemin |
| `APP_TRUSTED_HOST_PATTERN` | Par exemple `^stock[.]example[.]com\z`, sans guillemets dans le champ Portainer |
| `CLOUDFLARED_NETWORK` | Nom du réseau du module, généralement `hassio` |
| `CLOUDFLARED_ADDRESS` | Une seule adresse IP du conteneur Cloudflared sur ce réseau, jamais un sous-réseau entier |
| `MAIL_DELIVERY_ENABLED` | `0` pendant l’installation |

La stack crée ses propres volumes de base, secrets, état et sauvegarde. `setup` et `initialize` se terminent avec le code 0 ; les services `db`, `app`, `web`, `worker`, `scheduler`, `backup` restent actifs et sains ; `maintenance` reste simplement en cours d’exécution pour les commandes opérateur. Le premier démarrage applique les migrations à la base neuve, sans données fictives ni compte administrateur prédéfini. Un redéploiement conserve les identifiants et les données. Un secret manquant sur une installation initialisée fait échouer le démarrage : restaurer les volumes, ne pas les remplacer par des secrets neufs.

Ne jamais supprimer les volumes pour résoudre un échec de déploiement. Une perte partielle pendant la création initiale demande un diagnostic avant toute remise à zéro. Les services applicatifs ne possèdent ni mot de passe superutilisateur PostgreSQL ni clé privée de sauvegarde.

## Administrateur et raccordement au tunnel

Dans la console du conteneur **app**, lancer :

```sh
php bin/console app:user:create-admin adresse-de-l-administrateur@example.com "Administrateur"
```

Choisir un vrai compte et saisir son mot de passe dans l’invite masquée. Ne pas lancer les commandes de développement et ne pas partager ce mot de passe dans un ticket ou une conversation.

L’alias DNS interne du service web sur le réseau du tunnel est **`gstockbl-origin`**. Dans **Cloudflared → Configuration → Ajouter un hôte**, la cible est :

```yaml
hostname: stock.example.com
service: http://gstockbl-origin:8080
```

Remplacer uniquement le nom public par `APP_DOMAIN`. Conserver l’hôte Home Assistant et tous les autres hôtes existants. Avant d’enregistrer/redémarrer Cloudflared, vérifier que ce nom public n’est utilisé par aucun autre service : le module peut remplacer son enregistrement DNS. Le redémarrage du module interrompt brièvement ses tunnels ; Home Assistant Core n’a pas besoin d’être redémarré pour cette stack.

Le navigateur utilise HTTPS jusqu’à Cloudflare ; le tunnel chiffré rejoint le module, qui contacte web en HTTP sur le réseau Docker partagé. Symfony exige HTTPS déclaré par **l’adresse exacte** du module. Les autres conteneurs ne peuvent pas se faire accepter en ajoutant simplement `X-Forwarded-Proto: https`. Si l’adresse du module change après une recréation, relever sa nouvelle IP et redéployer la stack avec cette valeur ; ne pas élargir la confiance à tout `hassio`.

Contrôler le certificat réel, la connexion administrateur, les cookies Secure et l’absence de boucle de redirection. La recette simulant le proxy ne prouve ni le DNS ni la connexion réelle Cloudflare. Si une réponse 400 apparaît, vérifier le domaine et l’IP effective du module. Si une réponse 502 apparaît, vérifier le réseau partagé et la résolution de `gstockbl-origin` avant de modifier les permissions.

## Sauvegarde et exploitation avant ouverture

Le service backup produit une archive chiffrée quotidienne conservée 90 jours. Il embarque le code source de sa version et conserve la configuration non secrète ainsi que les quatre secrets de reprise à l’intérieur de l’archive chiffrée. Le fichier Compose de cette variante est inclus. La clé privée age est créée uniquement dans le volume **gestion-stock_recovery_key** ; les services permanents n’y ont pas accès.

Avant de saisir des données réelles, exporter cette clé vers un support séparé du serveur et vérifier une restauration. Un opérateur peut créer temporairement dans Portainer un conteneur utilisant l’image backup de la version, sans réseau, avec le volume `gestion-stock_recovery_key` monté en lecture seule sur `/recovery`, un utilisateur root, et une commande d’attente. Dans sa console, lire `/recovery/backup_identity` et conserver son contenu dans un fichier privé sur le support de reprise. Ne pas publier la clé ni la copier dans les journaux. Supprimer ensuite le conteneur temporaire sans supprimer le volume. La clé doit être protégée par les accès administratifs du serveur ; conserver sa copie hors serveur même si le volume est présent. Le `setup` vérifie sa cohérence lors d’un redéploiement.

Choisir puis tester la copie des archives **hors du mini-PC**. Les sauvegardes Home Assistant ne sont pas présumées couvrir cette stack et ses volumes Docker. La [procédure de restauration](sauvegardes-restauration.md) décrit le contrôle et le blocage avant retraitement des échéances. La recréation des volumes de secrets de cette variante doit être faite par l’opérateur à partir des quatre secrets restaurés, en gardant leurs copies applicatives et administratives cohérentes ; ne pas redéployer `setup` sur une restauration partielle.

Pour la conservation, l’aperçu se lance dans la console app avec `php bin/console app:privacy:preview`. Le traitement privilégié exige l’arrêt de **web, app, worker, scheduler, backup**, puis la console db (opérateur) : `psql -X -q -U postgres -d gestion_stock --set ON_ERROR_STOP=1 -f /opt/gestion-stock/retention.sql`. Redémarrer ces services uniquement après réussite. Cette stack ne pilote pas le démon Docker ; le timer système de [RGPD-01](conservation-donnees.md) n’est pas automatiquement installé sur Home Assistant OS. **La planification d’une maintenance régulière reste à mettre en place et à vérifier avant l’ouverture de production**, sans donner le socket Docker aux services applicatifs. Un échec conserve les services arrêtés.

SMTP reste désactivé jusqu’à configuration de son secret dans les volumes app et backup, avec des copies identiques et sans affichage dans les journaux. L’outil `/opt/portainer-setup.py smtp` fournit une saisie masquée dans un conteneur opérateur temporaire utilisant les mêmes volumes que `setup`. Redémarrer app/worker/scheduler après modification ; ne régler `MAIL_DELIVERY_ENABLED=1` et l’expéditeur réel qu’après accord et essais sur des boîtes autorisées. Le service SMTP et la copie hors serveur demeurent des prérequis d’exploitation, pas des données à inventer.

## Mises à jour

Cette variante refuse une nouvelle migration sur un simple redéploiement après sa première initialisation. Arrêter les écritures, sauvegarder l’ancienne version et sa configuration, conserver ses images, puis construire la nouvelle version. Le conteneur `maintenance`, sans port ni mot de passe superutilisateur, démarre avec la base même si `initialize` refuse une migration en attente. Dans sa console, exécuter `php bin/console doctrine:migrations:migrate --no-interaction` avec l’image candidate ; redéployer ensuite la stack pour relancer `initialize` et les services. Ne pas rouvrir le tunnel pendant cette maintenance. Vérifier réconciliation, santé et reprise ; suivre les [principes OPS-02](deploiement-production.md). Ne jamais remplacer l’image PostgreSQL en même temps qu’une mise à jour applicative sans recette dédiée.

## Preuves et références

`sh bin/test-portainer` utilise une copie de sources sans secrets réels, un réseau Cloudflared simulé et des volumes jetables. Il vérifie démarrage, conservation des secrets au redéploiement, absence de compte de démo, frontière proxy, sauvegarde chiffrée et aperçu de conservation. Il ne se connecte pas à Home Assistant et ne crée aucun DNS public. Les rapports sont dans `var/portainer/`, ignorés par Git.

La recette comprend sept tests du provisionnement et une restauration sur une seconde base avec comparaison des empreintes, puis vérification du blocage et du retraitement avant réouverture. Le réseau du proxy simulé est créé avec un sous-réseau explicite libre, nécessaire aux IP fixes des clients sur Docker Engine. Les quatre images natives de la préparation ont été analysées le 22 octobre 2026 sans signal connu dans cet audit daté ; les rapports détaillés restent dans `var/security/portainer-ops04/`. Refaire l’analyse des images réellement construites sur le serveur avant ouverture ; cela ne remplace pas une vérification du code ni ne garantit l’absence de toute vulnérabilité.

- [Portainer : stack depuis Git et variables](https://docs.portainer.io/user/docker/stacks/add)
- [Module Cloudflared : hôtes supplémentaires](https://github.com/homeassistant-apps/app-cloudflared/blob/main/cloudflared/DOCS.md)
- [État final du MVP](finalisation-mvp.md)
