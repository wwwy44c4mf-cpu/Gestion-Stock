# Moteur transactionnel de stock — STK-02

Le service `App\Stock\StockMovementEngine::record(actor, operation, body, key)` est le point d’écriture commun destiné aux prochains contrôleurs métier. Ce lot livre le moteur ; il n’active aucun endpoint d’écriture ni formulaire. Les routes d’écriture du contrat restent planifiées jusqu’à leurs lots respectifs.

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
