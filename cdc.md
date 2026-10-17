Cahier des charges — Application de gestion de stocks pour interventions techniques

Version : 1.0
Date : 17 octobre 2026
Statut : Spécification initiale à valider
Contrainte impérative : l’intégralité de l’application et de ses dépendances applicatives doit être exécutée dans des conteneurs Docker.

1. Contexte et objectifs

L’application doit permettre à une organisation réalisant des interventions techniques de gérer les articles et consommables disponibles dans plusieurs magasins, d’enregistrer les mouvements de stock et d’identifier les consommations par utilisateur, article, magasin et période.

1.1 Objectifs

	●	Centraliser un catalogue d’articles commun à plusieurs magasins.
	●	Connaître les quantités disponibles par article et magasin.
	●	Tracer toute entrée, sortie, correction et transfert de stock.
	●	Réaliser des inventaires fiables et auditables.
	●	Prévenir les ruptures par seuils d’alerte.
	●	Produire des statistiques exploitables sur les consommations.
	●	Informer les personnes concernées par courriel.
	●	Déployer et maintenir la solution exclusivement avec Docker.

1.2 Périmètre

Inclus : authentification, rôles, magasins, catalogue, stocks, mouvements, inventaires, alertes, reporting, notifications, administration, sauvegardes et exploitation Docker.

Hors périmètre initial (extensions possibles) : achats fournisseurs, commandes automatiques, facturation, géolocalisation des véhicules, ERP, gestion des équipements sérialisés, application mobile native et fonctionnement hors ligne.

2. Acteurs, rôles et autorisations

Trois rôles initiaux : Administrateur, Gestionnaire, Utilisateur. Les autorisations doivent également être limitées aux magasins auxquels chaque personne est affectée. Un utilisateur peut appartenir à plusieurs magasins.

|Fonction                                       |Administrateur|Gestionnaire (magasins affectés)      |Utilisateur (magasins affectés)   |
|-----------------------------------------------|--------------|--------------------------------------|----------------------------------|
|Se connecter, consulter son profil             |Oui           |Oui                                   |Oui                               |
|Gérer comptes, rôles, affectations             |Oui           |Non                                   |Non                               |
|Créer, modifier, désactiver un magasin         |Oui           |Non                                   |Non                               |
|Administrer le catalogue d’articles            |Oui           |Oui, selon périmètre défini           |Non                               |
|Consulter les stocks                           |Tous          |Affectés                              |Affectés                          |
|Enregistrer une consommation                   |Oui           |Oui                                   |Oui                               |
|Enregistrer une entrée / un réapprovisionnement|Oui           |Oui                                   |Non                               |
|Corriger les quantités                         |Oui           |Oui, avec motif                       |Non                               |
|Transférer entre magasins                      |Oui           |Oui, si autorisé sur les deux         |Non                               |
|Préparer et valider un inventaire              |Oui           |Oui                                   |Saisie uniquement si autorisé     |
|Paramétrer les seuils d’alerte                 |Oui           |Oui, magasins affectés                |Non                               |
|Consulter les statistiques                     |Globales      |Magasins affectés                     |Ses consommations, selon politique|
|Configurer les courriels                       |Oui           |Destinataires et abonnements autorisés|Préférences personnelles          |
|Consulter le journal d’audit                   |Tous          |Magasins affectés                     |Non                               |

Règle : les droits sont vérifiés côté serveur pour chaque opération ; masquer un bouton dans l’interface ne constitue pas un contrôle d’accès.

3. Exigences fonctionnelles

3.1 Authentification et utilisateurs — AUTH

	●	AUTH-01 [MVP] Connexion par identifiant (courriel) et mot de passe.
	●	AUTH-02 [MVP] Déconnexion et expiration des sessions.
	●	AUTH-03 [MVP] Mots de passe hachés avec Argon2id ou mécanisme équivalent éprouvé ; aucun mot de passe en clair.
	●	AUTH-04 [MVP] Création, modification, désactivation et réactivation de comptes par l’administrateur.
	●	AUTH-05 [MVP] Affectation d’un rôle et d’un ou plusieurs magasins à chaque compte.
	●	AUTH-06 [MVP] Réinitialisation sécurisée du mot de passe par lien temporaire à usage unique.
	●	AUTH-07 [MVP] Protection contre les tentatives de connexion répétées, journalisation des échecs.
	●	AUTH-08 [V2] Authentification multifacteur et SSO OIDC, selon besoins.
	●	AUTH-09 [MVP] Toute désactivation de compte interdit les nouvelles connexions et invalide les sessions actives.

3.2 Gestion des magasins — MAG

	●	MAG-01 [MVP] Créer un magasin avec code unique, nom, description, adresse facultative, statut actif/inactif.
	●	MAG-02 [MVP] Modifier les informations sans altérer l’historique.
	●	MAG-03 [MVP] Affecter des utilisateurs et gestionnaires à un ou plusieurs magasins.
	●	MAG-04 [MVP] Consulter le stock d’un magasin ou la vue consolidée autorisée.
	●	MAG-05 [MVP] Interdire la suppression physique d’un magasin possédant des mouvements ; privilégier l’archivage.
	●	MAG-06 [V2] Gestion d’emplacements internes (allée, étagère, bac).

3.3 Catalogue d’articles — ART

	●	ART-01 [MVP] Créer un article avec référence unique, libellé, description, catégorie, unité (pièce, mètre, rouleau, etc.), statut.
	●	ART-02 [MVP] Modifier les informations descriptives ; conserver la référence et l’historique des mouvements.
	●	ART-03 [MVP] Désactiver/archiver un article ; ne pas supprimer physiquement un article déjà mouvementé.
	●	ART-04 [MVP] Rechercher, filtrer et trier par référence, nom, catégorie et statut.
	●	ART-05 [MVP] Définir un seuil de stock bas par couple article/magasin, et facultativement un seuil critique.
	●	ART-06 [V2] Image, code-barres/QR code, fabricant et référence fournisseur.
	●	ART-07 [V2] Import/export CSV avec validation et rapport d’erreurs.

3.4 Stocks et mouvements — STK

	●	STK-01 [MVP] Visualiser le stock courant d’un article par magasin et le total autorisé.
	●	STK-02 [MVP] Enregistrer une entrée avec quantité strictement positive, date, auteur, motif et référence facultative.
	●	STK-03 [MVP] Enregistrer une sortie/consommation avec quantité, magasin, article, auteur, bénéficiaire éventuel, date et commentaire.
	●	STK-04 [MVP] Enregistrer un ajustement positif ou négatif avec justification obligatoire et traçabilité.
	●	STK-05 [MVP] Transférer du stock entre deux magasins via une opération atomique : débit source et crédit destination, sans création ni perte d’unités.
	●	STK-06 [MVP] Empêcher tout stock négatif par défaut ; rejeter une sortie dépassant le disponible.
	●	STK-07 [MVP] Garantir l’atomicité et la cohérence des mises à jour concurrentes (transaction SQL et verrouillage ou mise à jour conditionnelle).
	●	STK-08 [MVP] Afficher l’historique horodaté et filtrable des mouvements, avec utilisateur, type, quantité, magasin, motif et lien vers l’opération.
	●	STK-09 [MVP] Ne jamais modifier ou supprimer silencieusement un mouvement validé : correction par contre-mouvement tracé.
	●	STK-10 [MVP] Distinguer la date réelle de l’opération (horodatage serveur) d’une date métier déclarée, si les saisies rétroactives sont autorisées.
	●	STK-11 [V2] Réservation de stock et quantités disponibles vs réservées.

3.5 Inventaires — INV

	●	INV-01 [MVP] Créer une campagne d’inventaire par magasin avec statut : brouillon, en cours, à valider, clôturée, annulée.
	●	INV-02 [MVP] Enregistrer les quantités comptées article par article, y compris zéro.
	●	INV-03 [MVP] Afficher les écarts entre stock théorique et compté, en quantité et pourcentage lorsque pertinent.
	●	INV-04 [MVP] Exiger une validation par un gestionnaire/administrateur avant génération des ajustements.
	●	INV-05 [MVP] Générer automatiquement des mouvements d’ajustement justifiés, rattachés à l’inventaire.
	●	INV-06 [MVP] Conserver l’auteur, les dates, les écarts et le validateur ; rendre l’inventaire clôturé non modifiable.
	●	INV-07 [MVP] Définir explicitement le traitement des mouvements pendant l’inventaire : pour le MVP, bloquer les mouvements du magasin pendant la phase de comptage/validation, ou imposer une photographie de référence et une réconciliation transactionnelle. Décision à valider avant développement.
	●	INV-08 [V2] Inventaire tournant, saisie mobile et lecture de codes-barres.

3.6 Alertes de stock — ALT

	●	ALT-01 [MVP] Déclencher un état « bas » lorsque le stock atteint ou descend sous le seuil configuré ; « critique » selon seuil optionnel.
	●	ALT-02 [MVP] Présenter les alertes sur un tableau de bord, par magasin et gravité.
	●	ALT-03 [MVP] Envoyer une notification lors du franchissement de seuil, sans répéter le même message à chaque consultation.
	●	ALT-04 [MVP] Lever automatiquement l’alerte après réapprovisionnement au-dessus du seuil ; garder l’historique.
	●	ALT-05 [MVP] Permettre la configuration des destinataires selon magasin et type d’alerte.
	●	ALT-06 [V2] Rappels périodiques, accusés de réception et escalade si rupture persistante.

3.7 Suivi des consommations — CON

	●	CON-01 [MVP] Conserver chaque consommation comme mouvement distinct avec date/heure, article, quantité, magasin et utilisateur responsable.
	●	CON-02 [MVP] Permettre la saisie d’un bénéficiaire différent de l’opérateur ; afficher clairement les deux identités.
	●	CON-03 [MVP] Filtrer les consommations par dates inclusives, article, catégorie, magasin et consommateur.
	●	CON-04 [MVP] Afficher les totaux dans leur unité d’origine ; ne pas additionner des unités incompatibles.
	●	CON-05 [V2] Associer une sortie à une intervention, un chantier, une équipe ou un ordre de travail.
	●	CON-06 [V2] Export CSV des consommations détaillées.

3.8 Statistiques et tableaux de bord — STAT

	●	STAT-01 [MVP] Afficher les articles les plus consommés, classés par quantité pour une même unité, et par nombre de sorties.
	●	STAT-02 [MVP] Afficher l’évolution quotidienne, hebdomadaire et mensuelle des consommations.
	●	STAT-03 [MVP] Répartir les consommations par utilisateur, magasin et catégorie.
	●	STAT-04 [MVP] Filtrer toutes les vues par période, magasin et catégorie, dans les limites des droits.
	●	STAT-05 [MVP] Fournir les indicateurs : articles actifs, références en rupture, références sous seuil, mouvements sur période et volume consommé par unité.
	●	STAT-06 [MVP] Exclure entrées, transferts et ajustements du calcul de consommation ; gérer séparément les retours éventuels.
	●	STAT-07 [V2] Export PDF/CSV, comparaison de périodes, consommation moyenne et prévision de rupture.

3.9 Courriels et notifications — MAIL

	●	MAIL-01 [MVP] Paramétrer l’envoi SMTP via variables d’environnement/secrets Docker (serveur, port, chiffrement, identifiants, expéditeur).
	●	MAIL-02 [MVP] Envoyer des courriels de stock bas, stock critique et réinitialisation de mot de passe.
	●	MAIL-03 [MVP] Envoyer des messages d’information ciblés par magasin ou rôle, sur action d’un administrateur.
	●	MAIL-04 [MVP] Utiliser des modèles de courriel lisibles (objet, article, magasin, quantité, seuil, date, lien vers application).
	●	MAIL-05 [MVP] Traiter les envois en tâche asynchrone avec reprises limitées et journal de livraison technique.
	●	MAIL-06 [MVP] Empêcher les doublons d’alerte via une clé d’événement/idempotence.
	●	MAIL-07 [V2] Synthèse quotidienne/hebdomadaire et abonnements individuels.
	●	MAIL-08 [MVP] Ne pas bloquer un mouvement de stock si le serveur SMTP est indisponible.

4. Règles de gestion transverses

	1.	Le catalogue est global ; le stock et les seuils sont propres à chaque magasin.
	2.	Les quantités sont décimales lorsque l’unité le nécessite (ex. mètres) ; précision configurée par unité ; aucun flottant binaire pour les calculs de stock.
	3.	Chaque mouvement validé possède un identifiant immuable, un horodatage UTC, un auteur, un type et une quantité signée ou un sens explicite.
	4.	La disponibilité est recalculée de manière cohérente avec le journal des mouvements ; un solde matérialisé peut être conservé pour la performance mais doit être réconciliable.
	5.	Une sortie ne peut pas être enregistrée dans un magasin non autorisé à l’utilisateur.
	6.	Un article inactif n’est plus sélectionnable pour de nouvelles consommations, mais reste consultable dans l’historique.
	7.	Les opérations sont idempotentes lorsque le client réessaie une requête après perte de connexion.
	8.	Les dates sont stockées en UTC et affichées selon le fuseau de l’utilisateur (par défaut Europe/Paris).
	9.	Les suppressions métier sont logiques ; aucune suppression physique des historiques comptables de stock via l’interface standard.
	10.	Les exports et statistiques respectent strictement le périmètre des autorisations.

5. Parcours utilisateurs

5.1 Sortie d’article

	1.	L’utilisateur se connecte et choisit un magasin autorisé.
	2.	Il recherche l’article et consulte la quantité disponible.
	3.	Il indique la quantité consommée et, si nécessaire, l’intervention et le bénéficiaire.
	4.	Le serveur valide les droits, l’article actif et le stock suffisant.
	5.	La transaction enregistre le mouvement et met à jour le solde.
	6.	Le nouveau stock s’affiche et un événement d’alerte est produit en cas de franchissement du seuil.

5.2 Inventaire

	1.	Le gestionnaire crée une campagne sur un magasin.
	2.	Les quantités de référence sont figées selon la stratégie choisie.
	3.	Les opérateurs saisissent les comptages.
	4.	Le gestionnaire examine les écarts et valide.
	5.	Les ajustements sont appliqués atomiquement et la campagne est clôturée.

5.3 Réapprovisionnement

	1.	Le gestionnaire sélectionne magasin, article et quantité reçue.
	2.	Il renseigne une référence documentaire facultative et valide.
	3.	Le stock est crédité et les alertes sont recalculées.

6. Interfaces et ergonomie

Application web responsive utilisable sur ordinateur, tablette et smartphone ; langue initiale française. Navigation recommandée : Tableau de bord, Articles, Magasins, Mouvements, Consommations, Inventaires, Statistiques, Alertes, Administration.

Écrans minimaux : connexion/mot de passe oublié ; tableau de bord ; liste et fiche article ; stocks par magasin ; formulaire d’entrée/sortie/transfert/ajustement ; historique ; inventaire et validation ; statistiques filtrables ; alertes ; comptes et droits ; configuration des courriels.

Accessibilité : navigation clavier, contrastes lisibles, libellés explicites, erreurs de saisie compréhensibles et confirmations pour les opérations sensibles.

7. Architecture technique et Docker (obligatoire)

7.1 Principe non négociable

Aucun composant applicatif ne doit nécessiter une installation directe sur l’hôte. Les services web, API, traitements asynchrones, base de données, tâches planifiées et éventuel reverse proxy sont exécutés en conteneurs Docker. L’hôte doit uniquement fournir Docker Engine, Docker Compose, le stockage, le réseau et les mécanismes de sauvegarde de l’infrastructure. Le développement local, les tests et la production doivent disposer de configurations Docker reproductibles.

7.2 Stack proposée (à confirmer)

	●	Backend : Symfony 8.x (PHP) exposant une API REST JSON ; utiliser une version de PHP officiellement compatible.
	●	Frontend : interface web responsive, par exemple Symfony/Twig + Stimulus pour un MVP simple, ou Next.js/React si une SPA est justifiée.
	●	Base de données : PostgreSQL (version stable supportée), dans son propre conteneur.
	●	Traitements différés : Symfony Messenger avec transport Doctrine ou Redis ; worker dédié en conteneur.
	●	Courriels : Symfony Mailer via SMTP externe configuré, sans installer de MTA sur l’hôte.
	●	Reverse proxy : Caddy ou Nginx en conteneur ; HTTPS obligatoire en production.
	●	Orchestration : Docker Compose pour une installation mono-hôte.

7.3 Services Docker minimaux

|Service                    |Responsabilité                         |Persistance                       |
|---------------------------|---------------------------------------|----------------------------------|
|`web`                      |Point d’entrée HTTP(S), TLS et routage |Certificats si TLS géré localement|
|`app`                      |API, interface et logique métier       |Aucun état métier local           |
|`worker`                   |File de courriels et tâches asynchrones|File durable côté base/transport  |
|`db`                       |PostgreSQL                             |Volume Docker dédié               |
|`scheduler` (si nécessaire)|Vérifications et synthèses planifiées  |Aucun état local                  |

Un service Redis peut être ajouté si le transport de messages ou le cache le justifie. Le SMTP peut être externe ; son utilisation n’implique pas une installation hors Docker de l’application.

7.4 Exigences d’exploitation Docker

	●	DOCK-01 Fournir Dockerfile multi-étapes, compose.yaml, .env.example sans secret et .dockerignore.
	●	DOCK-02 Démarrage documenté avec docker compose up -d --build après configuration.
	●	DOCK-03 Réseaux privés Docker ; ne pas exposer directement PostgreSQL ou les workers sur Internet.
	●	DOCK-04 Healthchecks, politiques de redémarrage et gestion correcte des signaux d’arrêt.
	●	DOCK-05 Exécution non-root dès que possible, système de fichiers en lecture seule pour les services compatibles, capacités Linux minimales.
	●	DOCK-06 Données persistantes sur volumes dédiés ; aucun état critique exclusivement dans le système de fichiers éphémère d’un conteneur.
	●	DOCK-07 Secrets via mécanisme adapté au déploiement ; ne jamais committer mots de passe, clés SMTP ou secrets applicatifs.
	●	DOCK-08 Images épinglées sur des versions testées, mises à jour maîtrisées et analyse de vulnérabilités.
	●	DOCK-09 Migrations de base explicites, documentées et compatibles avec une procédure de retour arrière.
	●	DOCK-10 Fournir commandes de sauvegarde/restauration, journaux, diagnostic et mise à jour.
	●	DOCK-11 Tester le redémarrage complet après suppression/recréation des conteneurs sans perte de données persistantes.
	●	DOCK-12 Les tests automatisés doivent pouvoir s’exécuter dans Docker, sans dépendance installée sur le poste de développement.

7.5 Déploiement logique

Navigateur HTTPS

      |

[web : reverse proxy Docker]

      |

[app : Symfony Docker] ---- [db : PostgreSQL Docker + volume]

      |

  [file de messages durable]

      |

[worker : Docker] --------> [serveur SMTP externe]

      |

[scheduler : Docker, si nécessaire]

8. Modèle de données conceptuel

|Entité               |Champs essentiels                                                                                                                                 |Relations                          |
|---------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------|
|`users`              |id, email unique, password_hash, nom, statut, dates                                                                                               |rôles, affectations, mouvements    |
|`roles`              |id, code (`ADMIN`, `MANAGER`, `USER`)                                                                                                             |utilisateurs                       |
|`stores`             |id, code unique, nom, statut, adresse                                                                                                             |stocks, utilisateurs               |
|`user_stores`        |user_id, store_id, habilitations facultatives                                                                                                     |utilisateurs/magasins              |
|`categories`         |id, nom, parent_id facultatif                                                                                                                     |articles                           |
|`articles`           |id, sku unique, nom, description, unité, statut                                                                                                   |catégorie, stocks                  |
|`stock_levels`       |article_id, store_id, quantity, low_threshold, critical_threshold, version                                                                        |article/magasin ; unicité du couple|
|`stock_movements`    |id, type, article_id, magasin source/destination, quantité, auteur, consommateur, date opération, date métier, motif, référence, clé d’idempotence|articles, magasins, utilisateurs   |
|`inventories`        |id, store_id, statut, snapshot_at, created_by, validated_by, dates                                                                                |lignes                             |
|`inventory_lines`    |inventory_id, article_id, theoretical_qty, counted_qty, delta                                                                                     |inventaire/articles                |
|`stock_alerts`       |id, article_id, store_id, niveau, statut, opened_at, closed_at                                                                                    |stock                              |
|`notification_events`|id, type, destinataire, payload minimal, statut, tentatives, dates, clé d’idempotence                                                             |alertes/courriels                  |
|`audit_logs`         |id, acteur, action, cible, date, contexte non sensible                                                                                            |toutes opérations sensibles        |

Les clés étrangères, index, contraintes CHECK, unicités et transactions doivent être définis dans les migrations. Index prioritaires : mouvements par date/article/magasin/consommateur ; stock par magasin ; alertes actives.

9. API et contrats

API REST versionnée sous /api/v1. Les exemples suivants indiquent les principales ressources ; ils ne constituent pas encore un contrat OpenAPI exhaustif.

|Méthode |Route indicative            |Usage                                  |
|--------|----------------------------|---------------------------------------|
|POST    |`/auth/login`               |Authentification                       |
|POST    |`/auth/logout`              |Déconnexion                            |
|GET/POST|`/users`                    |Lister/créer les comptes (selon droits)|
|GET/POST|`/stores`                   |Lister/créer les magasins              |
|GET/POST|`/articles`                 |Lister/créer les articles              |
|PATCH   |`/articles/{id}`            |Modifier/archiver un article           |
|GET     |`/stores/{id}/stocks`       |Consulter les soldes                   |
|POST    |`/movements/entries`        |Entrée                                 |
|POST    |`/movements/consumptions`   |Sortie consommée                       |
|POST    |`/movements/adjustments`    |Ajustement                             |
|POST    |`/movements/transfers`      |Transfert                              |
|GET     |`/movements`                |Historique filtré                      |
|GET/POST|`/inventories`              |Liste/création d’inventaires           |
|POST    |`/inventories/{id}/lines`   |Saisie de comptage                     |
|POST    |`/inventories/{id}/validate`|Validation/clôture                     |
|GET     |`/alerts`                   |Alertes actives/historiques            |
|GET     |`/statistics/consumptions`  |Agrégats de consommation               |
|POST    |`/notifications/information`|Envoi d’information autorisé           |

Exigences API : validation stricte des entrées, codes HTTP cohérents, pagination, filtrage et tri bornés, erreurs JSON normalisées, contrôle d’accès par ressource, protection CSRF pour sessions cookie, documentation OpenAPI, limitation de débit sur endpoints sensibles et clé d’idempotence sur les créations de mouvements.

10. Exigences non fonctionnelles

10.1 Sécurité et confidentialité

	●	HTTPS obligatoire en production ; cookies sécurisés HttpOnly/SameSite si authentification par session.
	●	Protection contre injections SQL, XSS, CSRF, élévation de privilèges et accès inter-magasins.
	●	Journal d’audit non modifiable par les utilisateurs ordinaires ; masquage des secrets dans les logs.
	●	Sauvegardes chiffrées ou stockées dans un emplacement à accès restreint ; test périodique de restauration.
	●	Principe du moindre privilège pour base de données, conteneurs et comptes applicatifs.
	●	Politique de conservation des données personnelles et information des utilisateurs conforme au RGPD ; durées à définir avec le responsable de traitement.

10.2 Performance et disponibilité

	●	Cibles initiales à confirmer par tests de charge : affichage d’une liste courante en moins de 2 secondes au 95e percentile, hors latence réseau exceptionnelle ; enregistrement d’un mouvement en moins de 3 secondes au 95e percentile.
	●	Pagination obligatoire des historiques volumineux ; indexation adaptée et calculs agrégés optimisés.
	●	La disponibilité cible, le nombre d’utilisateurs simultanés et les volumes d’articles/mouvements restent à définir.
	●	Les interruptions SMTP ne doivent pas empêcher les opérations de stock.

10.3 Sauvegardes et reprise

	●	Sauvegarde quotidienne de PostgreSQL et des autres volumes persistants utiles, avec rétention configurable.
	●	Sauvegarde hors de l’hôte recommandée ; restauration documentée et testée régulièrement.
	●	Définir avant production les objectifs RPO (perte maximale de données acceptable) et RTO (temps maximal de reprise).
	●	Ne jamais considérer un simple volume Docker comme une sauvegarde.

10.4 Observabilité

	●	Logs structurés horodatés, identifiant de requête et niveaux de gravité.
	●	Indicateurs de santé des conteneurs, erreurs applicatives, échecs de mails et taille de file d’attente.
	●	Traces métier consultables sans exposer de données sensibles inutiles.

11. Tests et critères d’acceptation

|ID    |Scénario                                                           |Résultat attendu                                          |
|------|-------------------------------------------------------------------|----------------------------------------------------------|
|REC-01|Un utilisateur non connecté appelle l’API des stocks               |Accès refusé                                              |
|REC-02|Un utilisateur tente une sortie dans un magasin non affecté        |Accès refusé, stock inchangé                              |
|REC-03|Entrée de 10 unités sur un stock de 5                              |Nouveau stock 15 et mouvement enregistré                  |
|REC-04|Sortie de 4 unités sur un stock de 15                              |Nouveau stock 11 et consommation attribuée                |
|REC-05|Sortie de 20 unités sur un stock de 11                             |Rejet, aucun mouvement ni stock négatif                   |
|REC-06|Deux utilisateurs retirent simultanément la dernière unité         |Une seule sortie réussit, stock final zéro                |
|REC-07|Transfert de 3 unités de A vers B                                  |A -3, B +3, opération atomique et traçable                |
|REC-08|Une sortie franchit le seuil bas                                   |Alerte créée et courriel mis en file une seule fois       |
|REC-09|Réapprovisionnement au-dessus du seuil                             |Alerte résolue et historique conservé                     |
|REC-10|Inventaire compté à 8 contre 10 théoriques                         |Après validation, ajustement -2 et stock 8                |
|REC-11|Consultation statistiques sur période                              |Seules les consommations de la période sont agrégées      |
|REC-12|Gestionnaire consulte un magasin non affecté                       |Données invisibles, y compris dans exports et statistiques|
|REC-13|SMTP inaccessible lors d’une sortie                                |Sortie validée, notification en attente/reprise           |
|REC-14|Rejeu d’une même requête de sortie avec même clé                   |Une seule sortie enregistrée                              |
|REC-15|`docker compose down` puis `up -d` sans suppression des volumes    |Stocks et historiques conservés                           |
|REC-16|Restauration d’une sauvegarde sur environnement Docker vierge      |Application et données récupérées selon procédure         |
|REC-17|Tentative de suppression d’un article mouvementé                   |Archivage possible, historique préservé                   |
|REC-18|Utilisateur désactivé tente une opération avec une ancienne session|Accès refusé                                              |

Couverture attendue : tests unitaires sur règles de stock, tests d’intégration base de données/API, tests fonctionnels sur parcours critiques, tests de concurrence, tests de droits, tests de restauration et test de déploiement Docker.

12. Livrables

	●	Code source complet avec historique Git et licence définie.
	●	README.md (installation, utilisation et dépannage).
	●	compose.yaml, Dockerfiles, .env.example, fichiers de configuration et scripts de démarrage.
	●	Migrations et jeux de données fictifs de démonstration.
	●	Documentation OpenAPI, modèle de données et diagramme d’architecture.
	●	Guide administrateur, guide utilisateur et matrice de permissions.
	●	Procédures de sauvegarde, restauration, mise à jour et retour arrière.
	●	Tests automatisés exécutables en conteneurs et rapport de recette.
	●	Documentation sécurité (SECURITY.md), historique des versions (CHANGELOG.md) et consignes de contribution/développement.

13. Découpage du projet

|Phase                  |Contenu                                                         |Condition de sortie                        |
|-----------------------|----------------------------------------------------------------|-------------------------------------------|
|0 — Cadrage            |Arbitrages, maquettes, modèle de données, critères d’acceptation|Spécification validée                      |
|1 — Socle              |Docker, base, authentification, rôles, magasins, catalogue      |Connexion et administration opérationnelles|
|2 — Stock              |Entrées, sorties, ajustements, transferts, concurrence          |Tests transactionnels validés              |
|3 — Inventaires/alertes|Comptage, validation, seuils, notifications SMTP                |Recette inventaire et alertes validée      |
|4 — Reporting          |Historique, filtres, statistiques, tableaux de bord             |Cohérence des agrégats vérifiée            |
|5 — Mise en production |Sécurité, sauvegardes, performance, documentation               |Recette complète et restauration réussies  |

Les durées et charges seront estimées après validation du périmètre et de l’équipe de développement.

14. Risques et mesures

|Risque                           |Impact                         |Mesure prévue                                        |
|---------------------------------|-------------------------------|-----------------------------------------------------|
|Sorties simultanées              |Stock négatif/incohérent       |Transactions, verrouillage, tests de concurrence     |
|Inventaire pendant mouvements    |Écarts erronés                 |Gel ou photographie réconciliée, stratégie formalisée|
|SMTP indisponible                |Courriels non transmis         |File durable, reprises, supervision                  |
|Mauvaise configuration des droits|Fuite de données inter-magasins|Vérifications serveur, tests de permissions          |
|Perte de volumes Docker          |Perte de données               |Sauvegardes hors hôte, restauration testée           |
|Mise à jour d’image incompatible |Interruption                   |Versions épinglées, environnement de test, rollback  |
|Historique modifié               |Statistiques faussées          |Journal append-only et contre-mouvements             |

15. Décisions à valider avant réalisation

	1.	Stack UI : interface Symfony/Twig/Stimulus (simplicité) ou frontend séparé (besoins UX plus complexes) ?
	2.	Périmètre des magasins : les gestionnaires peuvent-ils créer des articles globaux ou seulement proposer des créations ?
	3.	Consommation : l’utilisateur qui saisit est-il toujours le consommateur, ou peut-il déclarer une sortie pour un technicien tiers ?
	4.	Interventions : faut-il associer chaque sortie à un numéro d’intervention, chantier ou client dès le MVP ?
	5.	Inventaire : gel des mouvements pendant le comptage ou comptage avec réconciliation des mouvements concurrents ?
	6.	Unités : quelles unités sont nécessaires et quelle précision décimale par unité ?
	7.	Retours : les articles non utilisés peuvent-ils être réintégrés, et comment les rattacher à la consommation initiale ?
	8.	Notifications : quels destinataires, seuils, horaires et politiques anti-spam ?
	9.	Infrastructure : mono-serveur, nom de domaine, reverse proxy existant, SMTP disponible, contraintes de ressources ?
	10.	Dimensionnement : nombre de magasins, utilisateurs, références et mouvements quotidiens ?
	11.	Données personnelles : durée de conservation, responsables et procédure de suppression/anonymisation compatibles avec la traçabilité métier ?
	12.	Exploitation : objectifs RPO/RTO, rétention des sauvegardes, responsabilités de maintenance et supervision ?

16. Définition de terminé (Definition of Done)

Une fonctionnalité est considérée terminée lorsqu’elle respecte ses règles métier et droits d’accès, possède des validations serveur, des tests automatisés pertinents, une documentation mise à jour, une gestion explicite des erreurs et fonctionne dans l’environnement Docker cible. La mise en production est conditionnée à la réussite des scénarios de recette, des tests de restauration et des contrôles de sécurité.