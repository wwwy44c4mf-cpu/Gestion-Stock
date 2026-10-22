# RGPD-01 — Cartographie préparatoire

État : règles confirmées et traitement développé en recette ; point de départ confirmé le 22 octobre 2026 : date de chaque consommation ou action, même si le compte reste actif. Aucun traitement d’effacement/anonymisation activé sur l’environnement existant. Sources : schéma initial, 17 migrations et services actuels du dépôt. Cette cartographie décrit les structures, sans extraire de données nominatives réelles.

## Données et liens à traiter

| Ensemble | Données ou liens identifiants | Risque à couvrir |
|---|---|---|
| `users`, `user_stores`, `alert_recipients` | Nom, courriel, mot de passe haché, rôle, magasins, préférences et identifiant persistant | Conserver ce qui est nécessaire aux comptes actifs ; définir séparément le sort des comptes désactivés. `anonymized_at` seul ne supprime pas les liens historiques vers un compte. |
| `stock_movements` | Auteur, bénéficiaire, motif, référence, commentaire, clé d’idempotence, empreinte, date précise | Les liens auteur/bénéficiaire et textes libres doivent être examinés ensemble. Les quantités, articles, magasins, types et relations de compensation/retour assurent l’intégrité du journal. |
| `movement_corrections`, `stock_change_events` | Relations entre mouvements, identifiants et dates | Une correction ou un retour récent peut permettre de relier un ancien mouvement à une identité encore présente ailleurs. |
| `audit_logs` | Acteur, cible, contexte JSON, date précise | L’affichage expurgé d’ADM-01 ne supprime pas les données brutes. Les cibles et contextes peuvent recoller une ancienne action à un compte actif. |
| `inventories`, `inventory_lines`, `inventory_requests` | Créateur, validateur, compteur, dates, réponses mises en cache | Préserver comptages, écarts, états et cohérence ; ne pas laisser les réponses rejouables réintroduire des identités effacées. |
| `article_proposals`, `proposal_requests` | Proposant, valideur, commentaire, contenu JSON et réponse | Textes libres potentiellement nominatifs ; dossiers encore en attente à distinguer des dossiers clos. |
| `user_creation_requests`, `store_creation_requests`, `catalog_creation_requests` | Acteur, compte cible, clé, empreinte et résultat JSON | Des réponses anciennes peuvent contenir des noms/courriels ou permettre de retrouver des actions supprimées de l’affichage courant. La durée de rejeu idempotent doit être définie. |
| `notification_events`, `notification_delivery_attempts` | Destinataire, contenu, liens, dates, état et identifiants | Ne pas envoyer après échéance un contenu qui aurait dû être effacé ; conserver les seules mesures techniques nécessaires. |
| `information_campaigns`, `information_campaign_recipients` | Auteur, sujet/corps, audience, destinataire, empreinte du courriel | Les empreintes déterministes de courriels et audiences conservent des possibilités de rapprochement. |
| `daily_notification_runs`, `daily_summary_items`, `daily_summary_batches` | Destinataire, empreinte, date, notifications et alertes liées | Distinguer agrégats quantitatifs globaux et liens individuels. |
| `password_reset_tokens` | Compte, empreinte du jeton, dates | Données de sécurité à purger selon leur finalité et expiration ; un jeton haché n’est pas une anonymisation d’une personne. |
| `messenger_messages` | Identifiant de notification, en-têtes de reprise/erreur, dates, transport | Vérifier les messages différés et en échec avant purge des données auxquelles ils renvoient ; aucun effacement d’un mouvement de stock via la file. |
| Sessions, limiteurs, journaux techniques | Session, clés de limitation/corrélation, identifiants de requête | Les sessions/cache actuels sont éphémères ; les journaux OPS-03 minimisent déjà les données et tournent par taille, sans garantir une durée fixe. |
| Sauvegardes | Instantanés chiffrés de toute la base et secrets/configuration de reprise | Rétention validée de 90 jours. Une restauration peut réintroduire des données nominatives déjà effacées : retraitement avant réouverture à prévoir. |
| Référentiels et textes métier | Adresses de magasins, descriptions, libellés et références libres | Ne pas présumer qu’un texte métier ne contient jamais de donnée personnelle ; définir un usage sans noms de personnes et examiner les champs historiques concernés. |

## Contraintes d’intégrité

Le journal des mouvements et le journal d’audit sont protégés contre la modification ordinaire. Plusieurs auteurs/bénéficiaires sont obligatoires, certaines relations sont des clés étrangères composites et des réponses idempotentes stockent une copie des résultats. Un traitement ne doit pas désactiver globalement les protections ni supprimer les mouvements pour contourner ces contraintes.

Les contrôles doivent comparer avant/après : soldes, somme des mouvements par article/magasin, liens et quantités des retours, compensations/corrections, inventaires et statistiques globales. Les statistiques personnelles et droits sur les anciennes consommations dépendent du choix de conservation : ils ne peuvent pas garder un lien nominatif qui aurait été effectivement supprimé.

Un remplacement du nom par un identifiant stable, une empreinte déterministe ou un compte distinct par personne conserve des possibilités de rattachement. La CNIL distingue ce type de pseudonymisation d’une anonymisation et évalue notamment individualisation, rapprochement et inférence. Des dates précises, articles et magasins peuvent encore permettre des rapprochements avec d’autres informations : le maintien du journal quantitatif ne permet pas de promettre à lui seul une anonymisation irréversible de tout le jeu. [CNIL — anonymisation](https://www.cnil.fr/fr/technologies/lanonymisation-de-donnees-personnelles).

La durée d’un an est le choix fonctionnel recueilli pour ce projet ; sa finalité et d’éventuelles obligations spécifiques restent à valider avec le responsable de traitement avant production. [CNIL — durées de conservation](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees).

## Règles confirmées

Décision confirmée le 22 octobre 2026 : le délai d’un an part de chaque consommation ou action. Les lignes anciennes perdent leur lien nominatif, même si le compte reste actif ; la désactivation du compte ne déclenche pas ce délai. Les données nécessaires au fonctionnement du compte actif restent distinctes de ses anciennes actions. Restauration : retraitement obligatoire avant réouverture, selon la décision confirmée ci-dessous.

La procédure développée propose une simulation en lecture seule et un traitement opérateur contrôlé et journalisé sans identité. Les essais utilisent des données fictives jetables. Voir le [guide de conservation](conservation-donnees.md) et le rapport de validation pour l’exécution, les garanties et les limites. Aucun effacement lancé sur les données existantes.

Décision sur les retours : Après anonymisation d’une consommation, seuls les administrateurs et gestionnaires autorisés sur le magasin d’origine peuvent enregistrer un retour lié. Le bénéficiaire ne dispose plus du retour personnel sur cette ligne ; le magasin d’origine et le plafond des quantités retournables restent applicables.

Décision sur les consultations personnelles : Après anonymisation, les consommations anciennes disparaissent de l’historique et des statistiques personnels. Elles restent comptabilisées dans les statistiques globales autorisées, sans attribution nominative.

Décision sur les champs libres : Sur les actions anonymisées après un an, effacer entièrement les commentaires, motifs et références libres ; conserver les articles, quantités, magasins et dates. Appliquer cette suppression également aux copies présentes dans les journaux, événements et réponses mises en cache liés aux actions concernées.

Décision sur les dossiers ouverts : Exception pour les inventaires et propositions d’articles encore ouverts : conserver leur auteur et leurs commentaires jusqu’à la clôture. À la clôture, effacer immédiatement les informations ayant déjà plus d’un an ; conserver le calcul du délai depuis chaque action, sans le faire repartir à la clôture.

Décision sur les comptes désactivés : Effacer le nom et l’adresse courriel d’un compte après un an de désactivation. Appliquer séparément aux consommations et actions leur délai propre et les exceptions validées pour les dossiers ouverts.

## Priorité confirmée pour les dossiers ouverts

Si le compte est désactivé depuis au moins un an mais reste lié à un inventaire ou une proposition ouvert, différer l’effacement de son identité jusqu’à la clôture de tous ses dossiers ouverts. À cette clôture, effacer l’identité si le délai de désactivation est toujours atteint ; ne pas faire repartir le délai.

## Restauration — décision confirmée

Après restauration d’une sauvegarde, bloquer l’accès à l’application jusqu’à la réussite du retraitement des données dont le délai de conservation est dépassé, en appliquant les exceptions validées. En cas d’échec, maintenir l’application fermée ; ne pas réexposer les informations anciennes restaurées.

## Chaînes de corrections — décision confirmée

Lorsqu’une consommation ancienne est anonymisée, supprimer aussi le bénéficiaire de toutes les corrections liées, même récentes. Ces corrections quittent donc les vues personnelles ; les agrégats globaux restent conservés. Effacer également les copies de références et commentaires provenant de la consommation ancienne.
