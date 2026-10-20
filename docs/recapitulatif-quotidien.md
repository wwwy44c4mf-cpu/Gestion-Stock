# Récapitulatif quotidien des alertes — MAIL-03

Chaque jour à **08 h Europe/Paris**, l’application prépare un seul courriel par destinataire avec les alertes basses et critiques encore actives dans ses magasins autorisés. Les administrateurs et gestionnaires éligibles sont ceux de la préférence **Récapitulatif quotidien**, réglable depuis l’écran des destinataires d’alertes. Les comptes inactifs, anonymisés ou réservés au développement sont exclus.

Un administrateur reçoit dans un seul message les alertes de tous les magasins actifs pour lesquels il est destinataire ; un gestionnaire reçoit celles de ses magasins actifs affectés. Les préférences personnalisées peuvent retirer des personnes magasin par magasin. Aucun courriel vide n’est préparé. Le courriel contient article, magasin, niveau bas/critique, quantité, seuil, date de constat et lien vers le stock, en HTML et texte simple.

## Horaire et redémarrages

La planification utilise Europe/Paris, donc 08 h en heure d’hiver comme en heure d’été. Un contrôle chaque minute rattrape le récapitulatif du **jour courant** après un arrêt à 08 h. Aucun rattrapage des journées précédentes. Avant 08 h, aucun récapitulatif n’est créé.

Le premier passage après 08 h fige le lot du jour, même s’il est vide. Les exécutions suivantes ne le complètent pas et ne le renvoient pas. Une alerte apparaissant plus tard déclenche toujours son courriel immédiat ; elle sera incluse dans le rappel du lendemain si elle persiste. Les alertes immédiates restent actives à toute heure.

Un verrou PostgreSQL et les clés uniques destinataire/date locale empêchent les doubles générations, y compris avec plusieurs schedulers ou après redémarrage. Lot, événements, liens aux alertes et audit sont validés ensemble. Une panne annule l’ensemble et permet une reprise sûre. Le stock n’est jamais modifié par cette tâche.

## Contrôle avant envoi

Le worker relit les alertes du lot initial et leurs quantités actuelles. Il retire celles qui sont résolues, celles d’un magasin désactivé et celles pour lesquelles le destinataire n’a plus les droits ou l’abonnement requis. Un changement d’adresse, une désactivation ou un changement de rôle est également pris en compte. Une nouvelle alerte n’est pas ajoutée à un lot déjà préparé. Si aucun élément ne reste, aucun courriel n’est envoyé.

Un récapitulatif resté en attente jusqu’au lendemain n’est plus envoyé. Il est conservé avec un échec définitif `recipient_unavailable`, comme un lot devenu sans destinataire autorisé ; le récapitulatif du nouveau jour est indépendant. La commande de reprise technique ne contourne pas cette vérification. Les messages déjà envoyés ne sont pas renvoyés. Les limites inhérentes à l’accusé SMTP restent celles décrites dans [MAIL-01](courriels.md).

## Exploitation

Le conteneur `scheduler` consomme `scheduler_default` ; le `worker` livre les courriels asynchrones. Les tâches quotidiennes passent par le même système durable que les alertes immédiates. `MAIL_DELIVERY_ENABLED=0` conserve les événements en attente ; il ne désactive pas la préparation du lot quotidien. Aucun accès SMTP n’est effectué par le générateur.

Après 08 h, un opérateur peut relancer sans doublon la préparation du jour :

```sh
docker compose exec app php bin/console app:notifications:daily-summary
```

Aucun argument de date ne permet de générer des journées anciennes. La commande indique le nombre de récapitulatifs nouvellement préparés, et non le nombre de courriels effectivement reçus. Les échecs de livraison et leur reprise sont ceux de [MAIL-01](courriels.md).

Migration `Version20261021050000` : lots journaliers, liens aux alertes et protection de l’historique. Le retour arrière est refusé si un lot existe. `daily_notification_runs` conserve la contrainte unique destinataire/date et le fuseau Europe/Paris. Les audits enregistrent date locale et nombres de destinataires/éléments, sans adresses ni contenu.

Décision confirmée le 21 octobre 2026 : rattraper uniquement le jour courant après un arrêt à 08 h.
