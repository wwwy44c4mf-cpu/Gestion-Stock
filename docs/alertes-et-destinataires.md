# Alertes et destinataires — ALT-03

Le menu **Alertes** ouvre les alertes en cours. Les filtres magasin, état et gravité permettent de consulter aussi les alertes résolues. Le tri se fait par date ou gravité, avec pagination stable. Une alerte renvoie vers la fiche du stock ; les dates utilisent le fuseau du compte connecté. Consulter ou filtrer ne crée aucun événement ni courriel.

Les administrateurs voient tous les magasins, y compris désactivés. Les gestionnaires voient uniquement leurs magasins actifs affectés. Les utilisateurs ordinaires n’accèdent pas aux alertes. Les totaux, listes, labels et liens sont limités au périmètre autorisé.

## Personnaliser les destinataires

Depuis la liste, choisir **Configurer les destinataires** pour un magasin actif autorisé. Les administrateurs peuvent configurer tous les magasins actifs ; les gestionnaires, leurs seuls magasins actifs affectés.

Par défaut, tous les administrateurs actifs et les gestionnaires actifs affectés au magasin reçoivent les alertes. Les comptes de développement sont exclus. Pour chaque personne, trois cases sont proposées : stock bas, stock critique et rappel quotidien. Le rappel est préparé ici ; son envoi sera développé dans MAIL-03.

Choix confirmé le 21 octobre 2026 : la liste est personnalisable parmi ces personnes. Enregistrer les cases choisies remplace explicitement les abonnements des trois types pour ce magasin. Une colonne vide désactive ce type de notification ; une liste entièrement vide n’a aucun destinataire. Une liste personnalisée n’ajoute pas automatiquement les nouveaux comptes éligibles. **Rétablir la liste par défaut** réactive la liste dynamique, qui suit les rôles, affectations et activités courants.

Un compte ne peut pas devenir destinataire par une adresse libre ou par la sélection d’un utilisateur d’un autre magasin. Les noms et rôles nécessaires au choix sont présentés, sans adresses électroniques ni affectations extérieures. La liste effective est recalculée à chaque lecture et avant livraison. Un destinataire retiré ou devenu inéligible ne reçoit pas un message encore en attente ; un envoi déjà parti ne peut pas être rappelé.

Les modifications sont atomiques et auditées avant/après. Une répétition identique n’ajoute pas d’audit. Les quantités, mouvements, alertes et événements déjà enregistrés ne changent pas, et les anciens courriels ne sont pas renvoyés. Les choix enregistrés pour une personne devenue inéligible restent sans effet tant qu’elle ne remplit pas les conditions d’accès.

Le formulaire empêche d’écraser des préférences ou une éligibilité modifiées pendant son ouverture. En cas de conflit, utiliser **Recharger les destinataires actuels**. Les formulaires incomplets sont refusés ; la limite est de 1 000 abonnements sélectionnés et 64 Kio par écriture. Les grandes listes peuvent être consultées par l’API paginée.

## API

- `GET /api/v1/alerts` : pagination, magasin, article, état, gravité, tri et ordre. Sans filtre d’état, l’API inclut actives et résolues ; l’écran commence sur les actives.
- `GET /api/v1/stores/{id}/alert-recipients` : page des abonnements effectifs ; en-tête `X-Recipients-Mode` default/custom et ETag de la configuration et des personnes éligibles.
- `GET /api/v1/stores/{id}/alert-recipient-options` : page des identifiants, noms et rôles des personnes actuellement autorisées ; aucune adresse exposée.
- `PUT /api/v1/stores/{id}/alert-recipients` : session et CSRF, `recipients` obligatoire, liste d’objets user_id/alert_type. `use_defaults` facultatif, false par défaut ; true exige une liste vide. If-Match facultatif avec l’ETag précédent ; conflit 409 si les données ont changé. Aucun champ de recherche accepté sur l’écriture.

La migration 14 ajoute le mode de configuration, conserve les éventuelles sélections existantes et raccorde le recalcul des alertes aux préférences. Elle n’ajoute aucun mouvement ou notification. Un retour arrière est refusé si une configuration a été enregistrée.
