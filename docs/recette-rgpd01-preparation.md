# RGPD-01 — Plan de recette préparatoire

État : plan de recette et décisions confirmées le 22 octobre 2026. Les résultats exécutés sont distingués dans le [rapport de validation](validation-rgpd01.md). Aucun traitement n’est lancé sur les données existantes.

## Invariants indépendants de la règle de conservation

Le jeu de recette sera créé dans un projet et des volumes jetables avec des personnes fictives. L’installation locale existante ne servira pas de jeu à anonymiser.

| Scénario | Preuve attendue |
|---|---|
| Simulation seule | Aucun changement de ligne ; uniquement catégories et nombres, sans noms, courriels, jetons ou textes libres dans la sortie. |
| Conservation quantitative | Soldes et sommes par article/magasin inchangés ; réconciliation sans écart avant et après. |
| Corrections et annulations | Même nombre de mouvements, mêmes quantités et liens de compensation ; aucune correction nouvelle créée par le traitement. |
| Retours | Quantité cumulée rendue inchangée, liens d’origine conservés ; contrôles de dépassement toujours efficaces. Le droit de retour personnel après perte de son attribution reste à arbitrer. |
| Inventaires | Références théoriques, comptages, écarts, statuts et ajustements inchangés. |
| Effacement des liens | Marqueurs nominatifs recherchés dans les lignes sources, caches de réponses, contextes d’audit, notifications et audiences concernées, pas seulement dans l’interface. |
| Absence de pseudonyme stable | Pas d’étiquette, UUID par personne ou empreinte déterministe permettant de regrouper les anciennes actions d’une même personne après le traitement censé supprimer ce lien. |
| Protection du journal | Les modifications ordinaires des mouvements/audits restent refusées ; le mécanisme de maintenance ne permet pas de changer quantités, articles, magasins ou relations. |
| Relance | Deux passages successifs donnent le même résultat métier, sans nouvelle perte ni nouvelle modification des lignes déjà traitées. |
| Échec contrôlé | Une panne injectée ne laisse pas un ensemble de liens incohérent ; compte rendu expurgé et reprise explicite. |
| Concurrence | Une consommation, correction, validation ou un retour simultané ne contourne ni la conservation ni les contrôles de stock. |
| File et courriels | Un message ancien n’envoie pas de contenu effacé ; les messages récents autorisés restent fonctionnels. Aucun courriel réel pendant la recette. |
| Sauvegarde restaurée | Retraitement de la base restaurée avant réouverture, sans réintroduire les liens effacés dans l’environnement utilisé. Comparaison quantitative après restauration/retraitement. |
| Accès | Aucun endpoint permettant à un utilisateur ordinaire de déclencher le traitement ou consulter un inventaire nominatif global. |
| Journaux | Date UTC, codes et compteurs uniquement ; absence des marqueurs confidentiels, y compris lors d’un refus ou d’une erreur. |

## Frontière temporelle à préciser

Tester une ligne juste avant, exactement à et juste après la limite retenue, avec horodatages explicites. Tester le 29 février et un passage heure d’été/hiver. La définition d’« un an » et l’inclusion exacte de la borne doivent être documentées, sans remplacer silencieusement l’année civile par 365 jours.

Règle confirmée : calculer le délai depuis la date de chaque consommation ou action, indépendamment de la désactivation du compte. Vérifier qu’une action ancienne perd son lien nominatif même pour un compte encore actif, tandis qu’une action récente conserve son attribution. Les comptes réactivés et les opérations récentes liées à des opérations anciennes auront leurs cas dédiés. Les limites calendaires précises restent à définir.

## Critères de revue

Comparer les agrégats globaux et les liens d’intégrité, examiner les possibilités de rapprochement restantes et documenter les limites. Un simple test d’absence du nom dans une réponse HTML n’établit pas l’anonymat du jeu de données. Les garanties sur l’identification indirecte doivent rester cohérentes avec les dates, magasins et articles conservés.

[Cartographie](cartographie-donnees-personnelles.md) · [Suivi](../suivi.md#rgpd-01--anonymisation-des-données-nominatives-après-un-an).

## Retours après anonymisation — décision confirmée

Après anonymisation d’une consommation, seuls les administrateurs et gestionnaires autorisés sur le magasin d’origine peuvent enregistrer un retour lié. Le bénéficiaire ne dispose plus du retour personnel sur cette ligne ; le magasin d’origine et le plafond des quantités retournables restent applicables. Tester le refus pour un utilisateur ordinaire, y compris l’ancien bénéficiaire, et pour un gestionnaire sans droit sur le magasin ; tester le succès pour les rôles autorisés et le maintien du plafond cumulé, sans réattribuer la consommation ancienne à une personne.

## Historique et statistiques personnels — décision confirmée

Après anonymisation, les consommations anciennes disparaissent de l’historique et des statistiques personnels. Elles restent comptabilisées dans les statistiques globales autorisées, sans attribution nominative. Vérifier l’absence des anciennes lignes dans les consultations et exports personnels, la conservation des lignes récentes et l’invariance des agrégats globaux. Les statistiques par technicien ne doivent pas réattribuer les lignes anonymisées à leur ancien bénéficiaire.

## Champs libres — décision confirmée

Sur les actions anonymisées après un an, effacer entièrement les commentaires, motifs et références libres ; conserver les articles, quantités, magasins et dates. Utiliser des marqueurs fictifs distincts dans chaque champ et vérifier leur absence dans les données sources, leurs copies, les réponses et exports après traitement. Vérifier la conservation des champs quantitatifs et des actions récentes.

## Dossiers ouverts — exception confirmée

Exception pour les inventaires et propositions d’articles encore ouverts : conserver leur auteur et leurs commentaires jusqu’à la clôture. À la clôture, effacer immédiatement les informations ayant déjà plus d’un an ; conserver le calcul du délai depuis chaque action, sans le faire repartir à la clôture. Tester un dossier ancien ouvert, puis sa clôture (validation, rejet ou annulation selon le cycle de vie). Vérifier que les actions récentes restent nominatives jusqu’à leur propre échéance et que les quantités et statuts restent cohérents.

## Comptes désactivés — décision confirmée

Effacer le nom et l’adresse courriel d’un compte après un an de désactivation. Appliquer séparément aux consommations et actions leur délai propre et les exceptions validées pour les dossiers ouverts. Tester les limites du délai de désactivation et vérifier que l’identité d’un compte actif reste disponible pour son fonctionnement. Les règles de conservation des anciennes actions doivent fonctionner indépendamment de l’état actif du compte.

## Compte désactivé lié à des dossiers ouverts — priorité confirmée

Si le compte est désactivé depuis au moins un an mais reste lié à un inventaire ou une proposition ouvert, différer l’effacement de son identité jusqu’à la clôture de tous ses dossiers ouverts. À cette clôture, effacer l’identité si le délai de désactivation est toujours atteint ; ne pas faire repartir le délai.

Tester plusieurs dossiers ouverts : la clôture du premier ne doit pas effacer l’identité tant que le dernier reste ouvert. Tester aussi une réactivation avant la dernière clôture : un compte redevenu actif doit conserver son identité.

## Restauration — décision confirmée

Après restauration d’une sauvegarde, bloquer l’accès à l’application jusqu’à la réussite du retraitement des données dont le délai de conservation est dépassé, en appliquant les exceptions validées. En cas d’échec, maintenir l’application fermée ; ne pas réexposer les informations anciennes restaurées.

## Chaînes de corrections — décision confirmée

Lorsqu’une consommation ancienne est anonymisée, supprimer aussi le bénéficiaire de toutes les corrections liées, même récentes. Ces corrections quittent donc les vues personnelles ; les agrégats globaux restent conservés. Effacer également les copies de références et commentaires provenant de la consommation ancienne.
