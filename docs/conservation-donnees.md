# RGPD-01 — Conservation et effacement des liens nominatifs

## Règles livrées

Le délai est une année calendaire depuis chaque consommation ou action, à l’heure anniversaire incluse, en UTC. Un 29 février arrive à échéance le 28 février suivant. Les auteurs et bénéficiaires anciens sont remplacés par **une seule identité commune**, non connectable, sans correspondance par personne. Les textes libres, empreintes et clés de rejeu concernés sont effacés ; le motif technique obligatoire devient « [Effacé] ».

Les quantités, articles, magasins, dates, compensations, corrections et relations de retour sont conservés. Les bénéficiaires de toute une chaîne de corrections sont effacés dès qu’une consommation de cette chaîne arrive à échéance, même sur les corrections récentes. Les copies de références et commentaires de la consommation ancienne sont également supprimées. Le compte actif conserve son identité pour fonctionner. Les vues personnelles perdent les lignes ainsi détachées ; les statistiques globales conservent les volumes. Les retours restent possibles pour les administrateurs et gestionnaires autorisés sur le magasin d’origine, dans la limite encore retournable.

Les inventaires et propositions ouverts conservent les informations nécessaires à leur traitement. Leur clôture efface immédiatement les informations déjà anciennes dans la même transaction, en conservant les informations récentes jusqu’à leur échéance propre. Les quantités et versions de comptage restent intactes. Les identités des comptes désactivés sont effacées après un an de désactivation, sauf s’ils restent liés à un dossier ouvert ; la clôture du dernier dossier libère alors l’effacement. Une réactivation remet cette horloge à zéro. Pour les comptes déjà inactifs avant la migration, faute de date historique fiable, le délai démarre à la migration plutôt que d’inventer une date à partir d’une modification du profil.

Les caches d’idempotence peuvent contenir des copies nominatives. Ils sont supprimés à échéance ou lorsqu’un dossier ancien est expurgé : un rejeu n’est donc plus garanti après cette échéance. Les anciennes notifications, audiences, empreintes d’adresses et éléments de file correspondants sont supprimés. Les jetons de réinitialisation expirés sont purgés. Les journaux de traitement contiennent uniquement dates et compteurs.

## Simulation et exécution contrôlée

Simulation sans aucune modification : `php bin/console app:privacy:preview` dans l’image applicative à jour. Voir [le rapport de simulation](simulation-conservation.md).

En développement : `sh bin/privacy preview`, puis `sh bin/privacy apply` pour l’opérateur qui souhaite appliquer les règles. En production : `sh bin/production privacy`. **Le développement de RGPD-01 ne lance pas ces commandes sur les données existantes.**

L’exécution arrête l’accès web, l’application, les travailleurs, l’ordonnanceur et la sauvegarde. Une seule maintenance peut démarrer à la fois. Le SQL exige un rôle PostgreSQL superutilisateur ; le compte technique applicatif est refusé. Aucune route HTTP ni commande applicative d’effacement n’est ajoutée. Le secret administrateur n’est pas transmis à l’application.

Le traitement verrouille les tables concernées et modifie les seules données de confidentialité dans une transaction. Les contraintes et clés étrangères restent actives ; des protections d’historique nommées sont suspendues dans cette transaction privilégiée, puis rétablies avant validation. Soldes complets et journal quantitatif sont comparés avant/après. Une erreur annule toutes les modifications, y compris celles des protections. Le script ne rouvre l’application qu’après succès. Une interruption brutale peut laisser `.privacy-operation-lock` : l’opérateur doit vérifier qu’aucune maintenance ne tourne avant de retirer ce répertoire vide.

Les chemins ordinaires de clôture disposent uniquement de transformations vérifiées : effacement vers l’identité commune, suppression du contexte, retrait de caches périmés. Ils n’autorisent pas la modification des quantités ou la réattribution à une autre personne.

## Planification sur le serveur

Les modèles `docker/privacy/gestion-stock-privacy.service` et `.timer` prévoient une exécution quotidienne à **03 h UTC**, avec rattrapage après arrêt. Adapter le répertoire `/opt/gestion-stock` et créer `/etc/gestion-stock-privacy.env` contenant les chemins de configuration de production et le mode de proxy choisi. Installer les unités avec l’opérateur du serveur, puis activer le timer. Aucun mot de passe n’a à figurer dans ces unités : le traitement s’exécute via le service PostgreSQL local de Compose.

Le timer n’est pas installé sur ce poste de développement. Le responsable de production doit vérifier son activation et son suivi avant ouverture. Entre deux exécutions, l’effacement ordinaire peut avoir jusqu’à un jour de retard ; la clôture des dossiers anciens reste immédiate.

## Restauration et sauvegardes

La restauration vérifie le dump puis pose un verrou persistant `runtime_restore_gate`. L’application renvoie HTTP 503 et les travailleurs refusent de traiter les messages. Le verrou n’est levé qu’à la fin d’un traitement RGPD réussi. Cette table de verrou d’exploitation est exclue des empreintes métier : sa différence est volontaire, sans masquer les écarts des autres tables.

Comparer les empreintes **avant** retraitement. Migrer si nécessaire les sauvegardes anciennes vers le schéma comportant RGPD-01, puis exécuter la conservation en maintenance avant réouverture. Après retraitement, les empreintes nominatives changent nécessairement ; vérifier alors l’intégrité quantitative. Une réouverture manuelle ne doit pas contourner cette étape. Voir [la reprise après sinistre](sauvegardes-restauration.md).

Les sauvegardes restent chiffrées et conservées 90 jours : l’effacement dans la base courante ne réécrit pas leurs archives. Toute reprise applique de nouveau les échéances avant accès. La destination hors serveur reste à choisir, conformément à OPS-01.

## Limites à valider avant production

Le maintien des dates exactes, magasins et articles peut permettre des rapprochements avec des informations externes. Le retrait des liens nominatifs ne constitue donc pas, à lui seul, une garantie d’anonymat irréversible de tout le jeu. Les libellés du catalogue et adresses de magasins sont des référentiels conservés : ils ne doivent pas servir à enregistrer des noms de techniciens ou de clients. Le responsable du traitement doit valider la finalité et les durées avant production. [CNIL — anonymisation](https://www.cnil.fr/fr/technologies/lanonymisation-de-donnees-personnelles), [CNIL — durées de conservation](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees).

## Correction de finalisation — 18e migration

`Version20261022130000` appelle également la libération des comptes arrivés à échéance lors de la clôture d’un dossier récent, sans effacer prématurément les champs récents de ce dossier. Le traitement conserve aussi l’effacement des commentaires copiés dans les contre-mouvements récents d’une consommation ancienne. La migration remplace la fonction de clôture ; elle ne lance aucun effacement sur les données existantes. Le retour arrière reste refusé après un traitement de conservation journalisé.
