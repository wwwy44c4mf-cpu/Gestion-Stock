# Messages d’information ciblés — MAIL-02

Depuis **Administration → Messages d’information**, un administrateur peut rédiger un objet (200 caractères) et un message en texte simple (10 000 caractères), puis choisir des magasins actifs et/ou des rôles.

Plusieurs magasins réunissent leurs membres ; plusieurs rôles réunissent les personnes de ces rôles. Lorsque les deux critères sont renseignés, ils se cumulent : **magasin A + Gestionnaire = uniquement les gestionnaires affectés au magasin A**. L’accès global d’un administrateur ne constitue pas une affectation. Comptes inactifs, anonymisés ou réservés au développement exclus. Un compte présent dans plusieurs magasins reçoit un seul événement. Limite : 1 000 destinataires par message.

La prévisualisation affiche le message, la cible et les noms et adresses des personnes retenues. Elle ne crée aucun envoi. Vérifier ces informations, cocher la confirmation et confirmer la mise en file. Une cible vide ne peut pas être confirmée. La prévisualisation expire après 30 minutes ; toute modification du message ou de l’audience impose une nouvelle vérification.

Le reçu et l’historique restent réservés aux administrateurs. Ils distinguent les messages en attente, envoyés ou en échec. La mise en file ne prouve pas la réception d’un courriel. Avant chaque livraison, l’application vérifie à nouveau l’état du compte, son adresse et les critères choisis. Une personne sortie de la cible ou ayant changé d’adresse ne reçoit pas ce message ancien.

## API et exploitation

`POST /api/v1/notifications/information`, administrateur authentifié, jeton CSRF et `Idempotency-Key` obligatoires. Corps : `subject`, `message`, `store_ids` et/ou `roles` (`ADMIN`, `MANAGER`, `USER`). Réponse 202 : `data.event_ids` et `data.recipient_count` ; `Location` mène au reçu HTML. Cet appel constitue l’instruction explicite de mise en file ; les clients doivent présenter leur propre confirmation avant l’appel. Limite de cinq appels par minute et par adresse/route.

Une même clé, un même auteur et un contenu/ciblage identique renvoient le résultat initial, même après expiration de la prévisualisation. Une clé réutilisée pour un autre contenu produit un conflit. Campagne, destinataires, événements et audit sont enregistrés ensemble ; aucune donnée de campagne n’est enregistrée si l’opération échoue. L’audit conserve les critères et le nombre de destinataires, sans texte ni adresses. L’historique confirmé ne peut pas être réécrit.

Migration `Version20261021040000` : campagnes et liens aux événements existants. Le retour arrière est refusé si une campagne existe. Aucun changement du stock. Le worker et les procédures de reprise sont ceux de [MAIL-01](courriels.md). L’envoi SMTP reste désactivé localement ; le développement et les tests n’envoient aucun courriel réel.
