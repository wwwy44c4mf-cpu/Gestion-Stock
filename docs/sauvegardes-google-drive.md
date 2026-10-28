# Copie privée des sauvegardes vers Google Drive — OPS-05

Ce service s’installe dans une **stack Portainer séparée**, `gestion-stock-drive`. Il n’accède ni à PostgreSQL, ni aux secrets applicatifs, ni à la clé privée de récupération. Les archives locales restent produites par la stack `gestion-stock`, une fois par jour. Le copieur vérifie le dossier chaque heure ; la copie peut donc suivre la sauvegarde locale avec un décalage allant jusqu’à environ une heure. Aucune copie réelle n’est déclarée tant que la recette serveur n’est pas terminée.

## Préparer Google et le Mac

Créer un dossier Drive privé dédié et relever son identifiant dans son URL. Créer un projet Google Cloud, activer l’API Drive et préparer un client OAuth **Application de bureau**, propre à l’exploitant. La connexion est utilisée uniquement par l’exploitant ; ne pas proposer aux utilisateurs de connecter leurs comptes.

Le mode de test OAuth externe peut limiter les jetons à sept jours. Passer le projet en production après avoir renseigné la présentation et la confidentialité. Les pages statiques publiques [sauvegardes.html](../public/sauvegardes.html) et [confidentialite-sauvegardes.html](../public/confidentialite-sauvegardes.html) doivent être accessibles sans session. Dans Branding, utiliser leurs URL HTTPS sur le domaine de l’exploitant et ajouter ce domaine dans les domaines autorisés. Une validation du domaine peut être demandée par Google. Le passage en production ne rend pas les fichiers Drive publics. Une connexion personnelle peut conserver l’avertissement d’application non vérifiée ; vérifier l’identité exacte du projet avant de continuer.

Installer rclone 1.75.2 adapté au Mac depuis le site officiel, après vérification SHA-256. Le binaire est attendu dans `~/Applications/rclone-gestion-stock/rclone`. Depuis une copie locale des sources, lancer :

```sh
python3 bin/configure-drive-mac --folder IDENTIFIANT_DU_DOSSIER
```

Le sélecteur macOS demande le JSON du client Google téléchargé. Le programme lit ce JSON **localement**, écrit une configuration privée dans `~/.config/gestion-stock/rclone.conf` et démarre l’autorisation par le navigateur. Les secrets ne passent ni dans les arguments du processus ni dans la conversation. Cette commande réinitialise uniquement cette connexion dédiée ; ne pas la relancer après authentification pour simplement exporter la configuration.

Répondre oui à l’utilisation du navigateur, choisir le compte propriétaire du dossier et autoriser. Répondre non à Shared Drive si demandé. L’accès `drive` permet techniquement l’accès à tout le compte ; `root_folder_id` limite les opérations du copieur au dossier choisi, **pas l’autorisation OAuth elle-même**. Le service refuse les configurations ajoutant une autre connexion, une identité de service ou des options de destination arbitraires.

Contrôler l’accès sans afficher la liste des fichiers :

```sh
"$HOME/Applications/rclone-gestion-stock/rclone" lsf gestion_stock_drive: --config "$HOME/.config/gestion-stock/rclone.conf" --max-depth 1 >/dev/null && echo "Accès au dossier Drive confirmé."
```

## Installer la stack séparée dans Portainer

Créer `gestion-stock-drive` depuis le même dépôt Git public, branche de la livraison OPS-05 et chemin Compose `compose.drive.yaml`. Définir :

| Variable | Valeur |
| --- | --- |
| `DRIVE_RELEASE_ID` | SHA exact de la livraison du copieur |
| `DRIVE_FOLDER_ID` | Identifiant du dossier privé choisi |
| `DRIVE_BACKUP_ENABLED` | `0` pendant l’import de l’autorisation |
| `BACKUP_VOLUME_NAME` | `gestion-stock_backup_data`, à adapter uniquement si le nom de la stack principale diffère |

La stack ne publie aucun port. Elle monte le volume existant de sauvegardes en lecture seule, puis crée ses volumes `drive_config` et `drive_state`. La configuration doit rester inscriptible : rclone renouvelle et remplace ses jetons. Le service s’exécute sous UID/GID 10001, système de fichiers en lecture seule, sans capacités supplémentaires ni socket Docker. Une archive est limitée par défaut à 256 Mio et le travail temporaire à 320 Mio ; la mémoire du conteneur est plafonnée à 512 Mio. Un dépassement est signalé et n’autorise aucune suppression distante ; adapter ces limites ensemble si le volume métier augmente.

Dans la console `/bin/sh` du conteneur `gestion-stock-drive-drive-1`, lancer :

```sh
python3 /opt/drive-backup.py import-config
```

La saisie masquée attend la configuration encodée. Sur le Mac, lancer séparément :

```sh
python3 bin/configure-drive-mac --copy-config
```

Le programme copie la configuration privée dans le presse-papiers macOS sans l’afficher. La coller **uniquement dans la saisie masquée du conteneur**, jamais dans le chat, Git ou une variable publique de stack. L’encodage n’est pas un chiffrement ; le presse-papiers est sensible. Après le message `drive_configuration_saved`, effacer le presse-papiers du Mac avec `printf '' | pbcopy`.

Passer `DRIVE_BACKUP_ENABLED` à `1`, enregistrer et redéployer **uniquement cette stack**. Le service tente une copie au démarrage, puis toutes les heures. Il ne faut modifier ni RELEASE_ID ni DATABASE_RELEASE_ID de la stack applicative pour installer ce copieur.

## Garanties et contrôle de conservation

Seuls les couples reconnus `stock-YYYYMMDDTHHMMSSZ-<12hex>.backup.age` et `.sha256` sont considérés. L’en-tête age et le SHA-256 local sont contrôlés avant tout envoi. Les copies déjà présentes sont comparées à leurs empreintes MD5 calculées par Drive et à leurs tailles ; un conflit est refusé, sans remplacement. Un nouvel envoi est suivi d’un contrôle rclone. La clé privée, les journaux et les fichiers temporaires ne sont pas envoyés.

La source est copiée vers un répertoire temporaire avant transfert. Une interruption se reprend sans doublon ; un journal privé reprend aussi une suppression de paire interrompue. Une sauvegarde locale récente doit être confirmée à distance avant tout nettoyage. Le service ne lance jamais `sync`, `purge`, ni une suppression de dossier.

Les paires distantes âgées de plus de 90 jours sont téléchargées, puis vérifiées par SHA-256 avant suppression **définitive des deux fichiers nommés**, sans passage par la corbeille. Cela évite qu’elles restent conservées dans la corbeille au-delà du délai. Les fichiers étrangers, raccourcis, paires invalides ou orphelines sans intention de suppression déjà vérifiée sont préservés. Le compteur `retention_skipped_count` signale les paires anciennes invalides à examiner manuellement. Le service doit rester actif et autorisé pour appliquer cette durée.

## Recette et reprise

1. Les logs doivent afficher `drive_configuration_saved`, puis `drive_backup_succeeded`. Aucun jeton ni contenu de fichier ne doit être présent dans les logs.
2. Vérifier dans le dossier Drive la présence d’une archive récente **et de son `.sha256`**, sans fichier de clé privée. Le service doit passer healthy après son premier succès.
3. Télécharger les deux fichiers depuis Drive sur le Mac. Contrôler le SHA-256 de l’archive contre le fichier de contrôle. Une copie byte-identique prouve le transfert, mais ne remplace pas une restauration d’essai.
4. Garder la clé de récupération séparée. Pour valider la restauration complète, suivre le [guide de sauvegarde et restauration](sauvegardes-restauration.md) sur une installation isolée, jamais par-dessus la base active. Le retraitement de conservation précède la réouverture après restauration.

La santé vérifie un succès de copie récent et une sauvegarde locale âgée de moins de 36 heures. Le dernier essai échoué rend le contrôle de santé négatif. Une autorisation révoquée, un Drive plein, une archive corrompue ou trop volumineuse entraîne un échec conservateur, sans nettoyage des anciennes copies. Vérifier les services dans Portainer : l’état unhealthy n’envoie pas de courriel d’incident et n’implique pas un redémarrage automatique.

Pour suspendre les copies, mettre `DRIVE_BACKUP_ENABLED=0` et redéployer le copieur. Pour retirer l’accès, révoquer la connexion dans le compte Google, arrêter la stack et supprimer sa configuration privée si elle est abandonnée. Ne jamais supprimer le volume `gestion-stock_backup_data` en retirant cette stack. Le retrait de l’autorisation ne supprime pas les archives existantes ; les effacer séparément si souhaité.

Sources : [rclone Drive et client OAuth](https://rclone.org/drive/), [installation rclone](https://rclone.org/install/), [règles OAuth Google](https://developers.google.com/identity/protocols/oauth2/policies), [usage personnel non vérifié](https://support.google.com/cloud/answer/13464323).
