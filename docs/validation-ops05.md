# Recette OPS-05 — copie Google Drive

28 octobre 2026. L’autorisation Google du propriétaire et l’accès au dossier privé depuis son Mac ont été confirmés par l’exploitant. Les copies automatiques du serveur ne sont pas encore installées ni déclarées opérationnelles.

## Vérifications locales

`sh bin/test-drive` réussit :

- Trois tests du configurateur macOS sur JSON et jetons fictifs : import exact du client de bureau, fichiers 0600/répertoire 0700, refus d’injection et de remplacement d’autres connexions, export vers le presse-papiers sans arguments ni affichage de secrets.
- Dix-sept tests du copieur dans un conteneur non privilégié, sans réseau : paires chiffrées reconnues, SHA-256 local, comparaison distante, conflits immuables, reprise d’envoi et de nettoyage interrompus, refus des sauvegardes anciennes/futures, isolation de la configuration OAuth et messages d’erreur neutres. Le dernier test utilise le vrai rclone 1.75.2 avec un dossier local jetable comme destination : envoi, contrôle d’intégrité, second passage sans doublon et conservation.
- Installation Compose réelle avec trois volumes de fixture : UID 10001, système en lecture seule, source en lecture seule, configuration/état inscriptibles, aucun port ni volume de secrets applicatifs, refus d’un envoi activé sans configuration valide. Seuls les volumes jetables de cette recette sont supprimés ensuite.

Construction de l’image `drive-backup` réussie sur le Mac ARM64. Définition Compose validée. Les liens internes de 97 documents Markdown ont été vérifiés. Le contrôle continu OPS-05 reprend le même lanceur ; son résultat distant doit être contrôlé séparément.

## Limites et validation serveur restante

Les données des tests sont fictives ; aucun jeton, dossier Drive, fichier JSON Google ou backup réel n’a été lu par les outils de recette. L’essai rclone local ne démontre pas à lui seul le comportement d’un vrai Drive : vérifier le premier couple `.backup.age`/`.sha256` dans le dossier réel, l’état healthy et le téléchargement de cette copie, puis son empreinte SHA-256. La restauration complète depuis Drive reste à exercer sur une installation isolée selon le guide de reprise.

Le service n’envoie pas de courriel d’incident en cas d’échec : son journal privé et son état de santé sont les signaux disponibles dans Portainer. Les rappels Gmail de stock sont un mécanisme distinct, déjà validé avec l’exploitant.
