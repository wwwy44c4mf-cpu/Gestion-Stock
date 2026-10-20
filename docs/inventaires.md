# Campagnes d’inventaire — INV-01

Le menu **Inventaires** ouvre `/inventaires`. Administrateurs et gestionnaires peuvent préparer, démarrer et annuler une campagne dans leurs magasins actifs autorisés. Un utilisateur simple n’a pas accès à cet espace. Les administrateurs conservent la lecture des campagnes d’un magasin désactivé.

## Préparer et démarrer

« Préparer un inventaire » crée un brouillon sans bloquer les mouvements. Plusieurs brouillons sont possibles ; un seul inventaire peut être en cours ou à valider dans un magasin.

Le bouton « Démarrer et bloquer les mouvements » fige la référence au moment du démarrage. **Décision utilisateur du 20 octobre 2026 : tous les articles actifs sont inclus, ainsi que les articles désactivés dont le stock de ce magasin est positif.** Un article actif sans solde est compté avec une référence de zéro, sans créer de ligne de stock. Les articles désactivés sans stock sont exclus. Un article ajouté après le démarrage ne rejoint pas la campagne existante.

Le démarrage enregistre la date serveur et l’auteur dans l’audit. Le stock reste inchangé ; les quantités théoriques de la campagne ne peuvent ensuite être modifiées ou supprimées. L’écran affiche ces références, les quantités non saisies et, lorsqu’elles existent, les quantités comptées. Les dates suivent le fuseau du compte. Liste et référence sont paginées ; les filtres ne dépassent jamais les droits du compte.

## Gel et annulation

Pendant les états « En cours » et « À valider », toute entrée, consommation, retour, ajustement, correction, annulation de mouvement ou transfert impliquant le magasin est refusé. Les autres magasins restent utilisables. Le rejeu d’un mouvement déjà validé peut restituer son résultat, sans créer d’opération supplémentaire. Le magasin bloqué ne peut pas être désactivé.

Le démarrage attend les mouvements déjà engagés ; leur solde validé est inclus dans la référence. Inversement, un mouvement qui attend la fin du démarrage voit le gel et est refusé. Les verrous suivent le même ordre que les moteurs de stock et stabilisent aussi les droits, les statuts des magasins et le catalogue. L’audit et la référence sont enregistrés dans la même transaction ; une panne annule intégralement le démarrage.

« Annuler l’inventaire » est possible en brouillon, en cours ou à valider. L’action est tracée avec son auteur et sa date. Elle libère le magasin sans ajustement, tout en conservant la campagne et ses lignes. Une campagne annulée ou clôturée est immuable.

## États et lots suivants

Transitions autorisées : brouillon → en cours ou annulé ; en cours → à valider ou annulé ; à valider → clôturé ou annulé. Aucun retour en arrière ni réouverture d’une campagne terminée.

INV-01 livre le cycle de vie, la référence et le gel. **La saisie des quantités et des écarts est prévue dans INV-02 ; la validation avec ajustements atomiques et la clôture dans INV-03.** La soumission API vérifie déjà que toutes les lignes sont comptées ; elle maintient le gel. Aucune clôture artificielle ni ajustement n’est proposé par ce lot. Avant la livraison des lots suivants, l’annulation permet de libérer une campagne démarrée.

## API et exploitation

Routes disponibles : `GET/POST /api/v1/inventories`, `GET /api/v1/inventories/{id}`, `GET /api/v1/inventories/{id}/lines`, `POST /api/v1/inventories/{id}/start`, `/submit`, `/cancel`. Les transitions ne prennent aucun corps ni paramètre de recherche. Les écritures exigent le jeton CSRF et `Idempotency-Key` ; une clé réutilisée pour une autre opération renvoie 409. Un rejeu renvoie la réponse d’origine sans recommencer la transition, même si la campagne a évolué ; relire le détail pour connaître son état actuel. Les droits et l’état actif du magasin sont revérifiés, y compris au rejeu.

Une neuvième migration, `Version20261020220000`, ajoute le registre idempotent et les protections du cycle de vie et des lignes. Elle refuse le retour arrière si des campagnes existent. Aucune donnée de démonstration ajoutée. À la volumétrie du MVP, un verrou de table incompatible avec celui des moteurs sérialise brièvement les transitions ; une campagne ouverte ne conserve aucun verrou de connexion entre deux requêtes.
