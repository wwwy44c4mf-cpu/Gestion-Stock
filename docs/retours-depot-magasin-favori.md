# Retours dans le magasin de dépôt et magasin favori

## Parcours courant

Dans l’historique ou le formulaire des consommations, « Rendre des articles » ouvre le dépôt par article :

1. Choisir le magasin où le matériel est réellement déposé. Le magasin favori est proposé lorsqu’il est actif et autorisé.
2. Scanner le code-barres ou QR code du carton, ou choisir l’article. Plusieurs cartons peuvent porter le même code : le code identifie le type d’article, pas un carton individuel.
3. Saisir le nombre d’unités rendues. Administrateurs et gestionnaires peuvent choisir le bénéficiaire des consommations ; un utilisateur rend uniquement ses propres articles.
4. Confirmer avec « Enregistrer le retour ».

Le scan ne crée aucun mouvement. Le retour crédite uniquement le magasin de dépôt, même si le matériel provient d’autres magasins. L’application rattache la quantité aux consommations les plus anciennes encore retournables du bénéficiaire, par date puis identifiant pour départager les égalités. Il n’est pas nécessaire de retrouver une date ni de choisir une consommation précise. Le reçu conserve magasin, date, quantité, opérateur et bénéficiaire.

Par exemple : 3 unités retirées du magasin A, puis 4 du magasin B. Un dépôt de 5 unités dans C crédite C de 5 et rattache le retour à 3 unités de la première consommation et 2 de la seconde. Les stocks de A et B ne sont pas modifiés par ce dépôt. Un même dépôt peut ainsi produire plusieurs allocations dans l’historique quantitatif, regroupées dans son reçu.

Le cumul ne peut pas dépasser les unités encore retournables. Une consommation annulée ou remplacée ne reçoit pas de nouveau retour. Les articles désactivés restent retournables. Un inventaire en cours dans le magasin de dépôt bloque le dépôt ; un inventaire dans un ancien magasin de sortie ne bloque pas un retour qui n’y modifie aucun stock. Un magasin désactivé ne peut recevoir de mouvement.

Le lien « Consulter les consommations retournables ou choisir une consommation précise » conserve le parcours détaillé, notamment pour les administrateurs et gestionnaires autorisés qui traitent une consommation anonymisée. Ce parcours détaillé permet aussi de choisir le magasin de dépôt, tout en imposant la consommation précise. Les administrateurs peuvent traiter une origine désactivée en déposant dans un magasin actif ; aucun stock du magasin désactivé n’est modifié.

## Magasin favori et droits

Dans **Mon profil**, choisir un **Magasin favori**, puis enregistrer. Cette préférence appartient au compte et se retrouve après reconnexion, y compris sur un autre appareil. Elle sélectionne par défaut le magasin dans le bandeau commun à toutes les pages autorisées, ainsi que pour les entrées, consommations et dépôts. Le choix manuel d’un autre magasin dans le bandeau reste actif pendant la navigation et prend priorité dans les formulaires. « Tous mes magasins » reste également sélectionnable. Modifier le favori réapplique la nouvelle préférence ; une nouvelle session reprend le favori. Changer le magasin d’un formulaire ne change pas le favori. Une erreur de formulaire conserve la sélection saisie.

Les techniciens peuvent retirer et déposer du matériel dans tous les magasins actifs, même sans affectation. Leur favori peut donc être tout magasin actif. Cela ne donne pas accès aux stocks courants, journaux ou consommations d’autrui en dehors des périmètres de consultation existants. Les gestionnaires conservent leur périmètre de magasins pour les opérations réalisées au nom d’un autre bénéficiaire. Les administrateurs disposent du périmètre global.

Un favori désactivé ou devenu inutilisable pour l’opération n’est pas imposé : le formulaire demande un magasin valide, ou conserve un contexte magasin explicitement choisi et autorisé lorsqu’il existe. Le favori n’est pas remplacé automatiquement. Son identifiant est effacé lorsque le compte est anonymisé.

## Traçabilité, corrections et anonymisation

Chaque allocation reste un mouvement de retour immuable lié à sa consommation. Les changements de quantité passent par les corrections compensées existantes. Le reçu du dépôt indique aussi sa quantité effective après les corrections. Les statistiques attribuent les consommations au magasin de sortie et les retours au magasin de dépôt, à leur date réelle ; le journal et la réconciliation reposent sur ces mêmes mouvements.

Les regroupements ne portent aucune identité supplémentaire : leurs opérateurs et bénéficiaires sont dérivés des mouvements existants. Comme un regroupement peut relier plusieurs consommations d’une même personne, l’anonymisation étend la suppression du bénéficiaire à toute la composante reliée par les corrections et regroupements, même lorsque certaines lignes sont récentes. Les quantités et liens comptables sont conservés. Les vues personnelles perdent ces lignes une fois leur bénéficiaire effacé.

## Déploiement et API

La migration additive **Version20261023220000** ajoute `users.favorite_store_id`, `stock_return_groups` et `stock_movements.return_group_id`, ainsi que les contrôles différés du regroupement. Elle autorise un magasin de dépôt différent du magasin de sortie et conserve les anciennes lignes. Son retour arrière refuse toute préférence, regroupement ou retour inter-magasins présent. Aucun démarrage ordinaire ne doit appliquer implicitement cette migration sur une installation existante.

- `PATCH /api/v1/auth/me` accepte `favorite_store_id` optionnel (UUID actif autorisé, ou `null`) avec le fuseau horaire habituel.
- `POST /api/v1/movements/return-batches` reçoit article, magasin de dépôt, quantité et éventuellement bénéficiaire/commentaire. Session, CSRF et clé d’idempotence sont obligatoires. Les allocations, crédits, événements et audits forment une seule transaction.
- `POST /api/v1/movements/returns` reste disponible pour le parcours par consommation d’origine.

**Cette livraison met aussi à jour le script privilégié d’anonymisation.** Pour la variante Portainer, prévoir la reconstruction de l’image PostgreSQL de cette livraison, sur la même version majeure 18 et le même volume, afin que `/opt/gestion-stock/retention.sql` contienne la fermeture des liens de regroupement. Une simple mise à jour de l’image applicative laisserait l’ancien script dans le conteneur db. Cette évolution de l’image db doit être préparée par sauvegarde/export, arrêt des écritures, vérification du volume conservé, migration explicite et recette de restauration/retraitement. Ne pas supprimer ni réinitialiser les volumes. Les paramètres exacts de version seront fournis après validation de la livraison.
