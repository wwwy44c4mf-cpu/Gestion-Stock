# Statistiques de consommation — STAT-01

L’entrée **Statistiques** affiche les quantités sorties et rendues, le nombre de sorties et de retours, deux classements d’articles, l’évolution par jour/semaine/mois et les répartitions par bénéficiaire, magasin et catégorie. Les graphiques disposent de tableaux de valeurs exactes ; aucun service externe n’est nécessaire.

## Périmètre et filtres

Utilisateur : ses consommations, y compris celles saisies pour lui et celles des magasins auxquels il n’est plus affecté, à condition que ces magasins soient actifs. Gestionnaire : ses magasins actifs actuellement affectés. Administrateur : tous les magasins, y compris désactivés. Consulter des statistiques personnelles ne donne aucun accès au stock d’un magasin non affecté. Les personnes anonymisées portent le libellé « Compte anonymisé ».

Les dates sont inclusives dans le fuseau du compte. Elles se combinent avec le magasin et la catégorie actuelle exacte de l’article, sans sous-catégories implicites. Les articles désactivés restent comptés dans leur historique. Le contexte de magasin de l’en-tête présélectionne le filtre ; la réinitialisation revient au mois en cours et à tout le périmètre.

Par défaut, l’écran couvre le début du mois jusqu’à aujourd’hui. Effacer les deux dates permet de prendre toute l’activité autorisée. Les semaines commencent le lundi et les mois au premier jour ; la première et la dernière semaine ou mois peuvent être partiels selon les dates choisies. Les périodes sans activité sont complétées par zéro.

## Sorties et retours

Une sortie est comptée à la date de la consommation. **Un retour est compté à sa date réelle**, même si la consommation d’origine précède la période. Une période peut donc contenir uniquement des retours. Les quantités sont affichées séparément : elles ne sont jamais soustraites ou mélangées. La répartition d’un retour utilise l’article, le magasin d’origine, le bénéficiaire et la catégorie de la consommation concernée.

Les consommations et retours annulés sont exclus. Une correction exclut l’original et compte son remplacement valide à la date de ce remplacement. Entrées, transferts, ajustements et opérations d’inventaire ne sont jamais des consommations. Le nombre de sorties compte les mouvements de consommation valides, pas les unités ni les retours.

L’historique CON-01 affiche les retours liés aux consommations sélectionnées, toutes dates de retour confondues. Les statistiques suivent au contraire l’activité réelle de chaque période. Le lien « Consommations de cette période » permet de contrôler les sorties ; son total de retours liés peut donc différer du total des retours de la période.

## Classements et chiffres exacts

Le premier classement trie par quantité sortie puis nombre de sorties. Le second trie par nombre de sorties puis quantité. Les égalités sont départagées par référence et identifiant d’article. La limite d’articles s’applique uniquement aux deux classements, jamais aux totaux, à l’évolution ou aux répartitions. Un article n’ayant que des retours dans la période ne figure pas parmi les articles sortis, mais reste présent dans les totaux et répartitions.

Toutes les quantités sont en unités entières. Les agrégats conservent une représentation décimale exacte, même au-delà de la précision entière JavaScript. Les proportions graphiques sont calculées séparément avec PostgreSQL ; les valeurs affichées et les réponses API ne sont jamais arrondies.

## API et limites

`GET /api/v1/statistics/consumptions` : `date_from`, `date_to`, `store_id`, `category_id`, `granularity` (`day`, `week`, `month`) et `top_limit` (1 à 100, défaut 10). Authentification obligatoire. Un magasin hors périmètre ou inconnu n’est pas révélé.

La réponse `data` contient `totals`, `top_articles`, `top_articles_by_count`, `buckets`, `by_user`, `by_store` et `by_category`. Les quantités agrégées sont des chaînes ; les nombres de mouvements sont des entiers. Une catégorie absente est représentée par un identifiant nul et « Sans catégorie ».

Sans dates, l’API couvre l’activité visible. Une borne manquante est déduite de cette activité ; si aucune activité ne correspond et qu’une seule borne est fournie, une seule période vide est retournée. Sans activité ni bornes, les tableaux sont vides et les totaux à zéro. Une période explicite produit les intervalles vides correspondants.

Au-delà de 10 000 intervalles ou de 1 000 groupes dans une répartition, la requête est refusée avec une erreur 422 invitant à affiner les filtres. Aucun résultat n’est silencieusement tronqué. Tous les agrégats sont calculés depuis le même instantané SQL, après application du périmètre et des filtres.

Aucune migration et aucune écriture métier. Le tableau de bord général et les indicateurs de rupture appartiennent à STAT-02 ; les exports et comparaisons avancées restent hors de ce lot.

Décision confirmée le 21 octobre 2026 : les retours sont comptés à leur date réelle dans les statistiques.

## Conservation des données — RGPD-01

Après effacement des bénéficiaires, les volumes restent dans les statistiques globales autorisées, regroupés sous l’identité commune ; ils ne restent pas attribués à un technicien. Les corrections liées à une consommation ancienne suivent ce détachement même si elles sont récentes. Voir la [conservation des données](conservation-donnees.md).

## Retours entre magasins

Avec RET-02, une consommation est attribuée au magasin de sortie et un retour au magasin du dépôt réel. Un magasin peut donc avoir des retours sans consommation correspondante dans ce même magasin. Le filtre magasin et les distributions respectent cette distinction. Les droits sont vérifiés séparément pour les sorties et les dépôts ; les statistiques personnelles restent limitées au bénéficiaire encore identifiable. Les dates restent les dates réelles des événements.
