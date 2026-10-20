# Moteur transactionnel de stock — STK-02

Le service `App\Stock\StockMovementEngine::record(actor, operation, body, key)` est le point d’écriture commun destiné aux prochains contrôleurs métier. Le lot STK-02 livre le moteur. Depuis STK-03, [le formulaire et l’API d’entrée](entrees-stock.md) l’utilisent ; les autres routes d’écriture seront raccordées dans leurs lots respectifs.

## Opérations préparées

`entry`, `consumption`, `transfer` et `adjustment` utilisent les schémas déjà définis dans OpenAPI. Entrée, consommation et transfert exigent un article actif ; un ajustement motivé permet de corriger un article archivé. La quantité de l’ajustement est signée ; le journal stocke une quantité positive et le magasin source ou destination correspondant au signe. L’unité est exclusivement `unit`, avec des entiers stricts. Les transferts exigent deux magasins distincts, actifs et autorisés.

Une consommation distingue l’opérateur du bénéficiaire. USER consomme uniquement pour lui-même ; ADMIN/MANAGER autorisés peuvent désigner tout compte actif, même non affecté au magasin. Les retours, contre-mouvements et ajustements de validation d’inventaire nécessiteront leurs règles dédiées dans les lots suivants ; ils ne peuvent pas être simulés par des champs supplémentaires dans cette méthode. Les champs inconnus, dates client et identifiants d’inventaire sont refusés.

## Transaction et concurrence

La transaction stabilise les rôles, affectations, statuts des magasins/articles et inventaires grâce à des verrous partagés sur les tables de référence. Ils permettent plusieurs mouvements simultanés, tout en attendant ou bloquant les modifications administratives correspondantes pendant la transaction. L’ordre commence par `users`, comme les services d’administration existants.

Un verrou transactionnel PostgreSQL par acteur et clé protège le rejeu, même entre processus. Une collision de hachage ne peut que sérialiser deux demandes supplémentaires. Les soldes sont créés si nécessaire, puis verrouillés par magasin dans l’ordre des UUID. Les transferts inverses utilisent donc le même ordre ; aucun processus ne lit un solde disponible puis ne le débite sans verrou.

Les soldes sont bornés entre zéro et 9 007 199 254 740 991. Le dépassement, le stock insuffisant ou la saturation du compteur interne de version produisent un conflit 409. Un inventaire `in_progress` ou `pending_validation` bloque toute nouvelle opération du magasin, y compris l’une des extrémités d’un transfert.

Solde, incrément de version, mouvement immuable, événement durable et audit sont validés ensemble. Toute exception annule la transaction complète. Les futurs traitements par lots devront respecter le même ordre des verrous, et trier également les articles avant d’appeler plusieurs opérations dans une transaction commune.

## Idempotence

La clé autorise 8 à 160 caractères ASCII parmi lettres, chiffres, `.`, `_`, `:`, `-`. Elle est unique par opérateur dans l’ensemble du journal, tous types d’opérations confondus. L’empreinte HMAC couvre l’opérateur, l’opération et le corps canonique ; l’ordre des propriétés et la casse des UUID n’en changent pas le résultat. Une différence de contenu ou d’opération avec la même clé donne 409. Une ancienne ligne sans empreinte est également refusée, jamais adoptée comme preuve de rejeu.

Le rejeu restitue le même mouvement et sa date initiale sans toucher aux soldes, versions, événements ou audits, même si le stock a changé, si l’article a été archivé, si un inventaire a démarré ou si le bénéficiaire a été désactivé depuis. L’identité, le rôle et les accès de l’opérateur restent contrôlés à chaque appel ; une perte d’affectation n’accorde pas un droit d’écriture par le biais d’une ancienne clé. Les noms sont nuls dans ce résultat stable ; les futures vues pourront les présenter séparément selon les droits.

## Journal, événements et exploitation

Les mouvements conservent la date serveur UTC ; aucune date rétroactive n’est acceptée. Le journal et l’audit sont protégés par les triggers d’immutabilité existants. L’audit ne recopie ni motif, ni commentaire, ni référence libre. Le résultat ne contient pas la clé ni l’empreinte technique.

La migration `Version20261018200000` ajoute `stock_change_events` : un événement unique par mouvement avec article, magasins concernés et date, destiné au recalcul des alertes. Il reste en attente jusqu’à l’intégration du consommateur dans les lots d’alertes ; aucun courriel ni appel SMTP ne fait partie de la transaction. La migration ne remplit ni ne modifie les stocks existants et ne produit pas de faux événements pour l’historique. Son retour arrière refuse de supprimer une table contenant des événements.

`app:stock:reconcile` compare toujours les soldes au journal sans rien corriger. Un résultat non cohérent requiert une analyse et une correction métier contrôlée ; une écriture SQL manuelle des soldes contourne le moteur et reste détectable. Les futures écritures applicatives doivent passer par ce service plutôt que mettre à jour directement `stock_levels`.

Les consommations sont raccordées au moteur par leur formulaire et `POST /api/v1/movements/consumptions` depuis STK-04. Voir le [guide des consommations](consommations.md), notamment les règles de bénéficiaire tiers et de consultation personnelle de la confirmation.

## Corrections atomiques (STK-05)

`StockCorrectionEngine::correct` prend un identifiant d’origine, un motif et la quantité correcte, ou le mode annulation. Il stabilise les mêmes tables d’autorisation/état, sérialise la clé de requête et verrouille l’origine pour empêcher deux compensations concurrentes. Les magasins et les soldes sont verrouillés dans l’ordre UUID. Seule la différence nette est appliquée aux soldes ; le journal reçoit la compensation exacte et le remplacement dans la même transaction, avec un événement et un audit par nouveau mouvement. Cela autorise une correction 10 → 8 avec seulement 5 unités encore disponibles, et laisse 3 unités.

`movement_corrections` lie l’origine, le contre-mouvement et le remplacement avec une contrainte de cohérence et une protection contre la mutation. Le résultat de rejeu utilise ces liens persistés, sans nouvelle attribution ni nouveau débit. Les annulations utilisent l’index unique existant sur l’origine. Les consommations avec retours actifs et les mouvements issus d’inventaires clôturés ne sont pas compensés ici. Les enregistrements de retour STK-07 verrouillent la consommation d’origine et refusent une origine déjà compensée pour sérialiser les retours avec les corrections.

Appliquer `Version20261018223000` avant d’exposer les nouvelles pages de stock et confirmations. Les statistiques futures doivent compter les consommations effectives en excluant les origines compensées. [Guide et limites](corrections-stock.md).

STK-06 raccorde `POST /api/v1/movements/transfers` et le formulaire de transfert au moteur commun. Un seul mouvement porte source et destination, un seul événement contient les deux magasins ; la confirmation HTML exige leur double périmètre de lecture. Les verrous de soldes restent ordonnés par UUID, y compris pour les transferts opposés. [Guide](transferts-stock.md).

## Retours bornés (STK-07)

`StockReturnEngine::record` valide `ReturnCreate` et les droits sur la consommation, y compris la propriété personnelle USER sans affectation. Les mêmes tables d’état sont stabilisées en SHARE ; la clé de requête est sérialisée dans le même espace que les autres mouvements. La consommation est ensuite verrouillée FOR UPDATE avant lecture du cumul des retours non compensés et avant le solde. Le magasin et l’article viennent exclusivement de l’origine. Solde/version, retour, événement et audit sont atomiques.

Pour une correction ou une annulation d’un retour, `StockCorrectionEngine` verrouille désormais la consommation avant le retour ciblé, puis le solde. Toute opération modifiant le cumul partage donc cet ordre. Un retour sur une consommation compensée est refusé ; un retour annulé est exclu du cumul et son remplacement corrigé y entre. Les statistiques futures doivent conserver les retours séparés des sorties et filtrer leurs versions compensées. [Guide](retours-stock.md).

## Clôture des inventaires — INV-03

La validation suit le garde transactionnel du workflow d’inventaire, incompatible avec les moteurs ordinaires. Elle exige l’état à valider, la complétude et l’égalité des soldes courants avec les références figées. Elle verrouille les soldes dans l’ordre des articles, puis enregistre les seules variations non nulles avec `inventory_id`, leurs événements durables et audits. Le validateur, la clôture et le résultat idempotent sont enregistrés dans la même transaction. Les moteurs ordinaires continuent de refuser les mouvements pendant le gel. Une contrainte unique et des triggers complètent les contrôles serveur. [Guide](inventaires.md).
